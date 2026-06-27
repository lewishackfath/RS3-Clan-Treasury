<?php

declare(strict_types=1);

namespace Treasury\Services;

use PDO;
use Treasury\Auth\ApiContext;
use Treasury\Database;
use Treasury\Support\Uuid;

final class PaymentRequestService
{
    public function create(ApiContext $context, array $data): array
    {
        $data = $this->normaliseCreateData($data);
        $this->validateCreate($data);

        if ($this->shouldAutoReceive($data) && !$context->can('payments:receive')) {
            throw new \RuntimeException('API key does not have required scope: payments:receive', 403);
        }

        $pdo = Database::pdo();

        $existing = $this->findBySource($context->appId, $data['source_type'], $data['source_id']);
        if ($existing) {
            if ($this->shouldAutoReceive($data) && $existing['status'] === 'pending') {
                return $this->receiveFromApi($existing['request_uuid'], $context, $data);
            }
            return $existing;
        }

        $accounts = new AccountService();
        if (!empty($data['revenue_account_code'])) {
            $revenueAccountId = $accounts->requirePostingAccountByCode((string)$data['revenue_account_code'], ['income']);
        } elseif (!empty($data['revenue_account_id'])) {
            $revenueAccountId = $accounts->requirePostingAccount((int)$data['revenue_account_id'], ['income']);
        } else {
            throw new \InvalidArgumentException('revenue_account_code is required');
        }

        $uuid = Uuid::v4();
        $stmt = $pdo->prepare(
            'INSERT INTO treasury_payment_requests
             (request_uuid, app_id, source_type, source_id, player_rsn, amount, purpose, description, revenue_account_id, metadata)
             VALUES
             (:request_uuid, :app_id, :source_type, :source_id, :player_rsn, :amount, :purpose, :description, :revenue_account_id, :metadata)'
        );
        $stmt->execute([
            'request_uuid' => $uuid,
            'app_id' => $context->appId,
            'source_type' => $data['source_type'],
            'source_id' => $data['source_id'],
            'player_rsn' => $data['player_rsn'],
            'amount' => (int)$data['amount'],
            'purpose' => $data['purpose'],
            'description' => $data['description'] ?? 'Money received from ' . $data['player_rsn'],
            'revenue_account_id' => $revenueAccountId,
            'metadata' => isset($data['metadata']) && is_array($data['metadata']) ? json_encode($data['metadata'], JSON_UNESCAPED_SLASHES) : null,
        ]);

        $created = $this->getByUuid($uuid);
        AuditService::log('payment_request.created', 'treasury_payment_request', $uuid, null, $created, $context);

        if ($this->shouldAutoReceive($data)) {
            return $this->receiveFromApi($uuid, $context, $data);
        }

        return $created;
    }

    public function getByUuid(string $uuid): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT pr.*, a.slug AS app_slug, a.name AS app_name,
                    admin.display_name AS received_by_display_name,
                    admin.rsn AS received_by_rsn,
                    revenue.code AS revenue_account_code,
                    revenue.name AS revenue_account_name
             FROM treasury_payment_requests pr
             JOIN treasury_apps a ON a.id = pr.app_id
             LEFT JOIN treasury_admins admin ON admin.id = pr.received_by_admin_id
             LEFT JOIN treasury_accounts revenue ON revenue.id = pr.revenue_account_id
             WHERE pr.request_uuid = :uuid
             LIMIT 1'
        );
        $stmt->execute(['uuid' => $uuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \RuntimeException('Payment request not found', 404);
        }

        return $this->format($row);
    }

    public function receive(string $uuid, array $data): array
    {
        $adminId = (int)($data['admin_id'] ?? 0);
        $postedByAdminId = (int)($data['posted_by_admin_id'] ?? $adminId);
        if ($adminId <= 0) {
            throw new \InvalidArgumentException('admin_id is required');
        }
        if ($postedByAdminId <= 0) {
            throw new \InvalidArgumentException('posted_by_admin_id is required');
        }

        return $this->receiveInternal($uuid, $adminId, $data, $postedByAdminId, null);
    }

    public function receiveFromApi(string $uuid, ApiContext $context, array $data): array
    {
        if (!$context->can('payments:receive')) {
            throw new \RuntimeException('API key does not have required scope: payments:receive', 403);
        }

        $adminId = $this->resolveReceivedByAdminId($data);
        return $this->receiveInternal($uuid, $adminId, $data, null, $context);
    }

    public function cancel(string $uuid, int $adminId, ?string $notes = null): array
    {
        if ($adminId <= 0) {
            throw new \InvalidArgumentException('admin_id is required');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $row = $this->lockByUuid($uuid);
            if ($row['status'] !== 'pending') {
                throw new \RuntimeException('Only pending payment requests can be cancelled. Received or reconciled payments need a ledger correction instead.', 409);
            }

            $before = $this->format($this->hydrateRow($row));
            $metadata = $row['metadata'] ? json_decode((string)$row['metadata'], true) : [];
            if (!is_array($metadata)) {
                $metadata = [];
            }
            $metadata['cancelled'] = [
                'cancelled_by_admin_id' => $adminId,
                'cancelled_at' => gmdate('Y-m-d H:i:s'),
                'notes' => $notes,
            ];

            $stmt = $pdo->prepare(
                'UPDATE treasury_payment_requests
                 SET status = "cancelled", metadata = :metadata
                 WHERE id = :id'
            );
            $stmt->execute([
                'metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES),
                'id' => (int)$row['id'],
            ]);

            $pdo->commit();
            $after = $this->getByUuid($uuid);
            AuditService::log('payment_request.cancelled', 'treasury_payment_request', $uuid, $before, $after, null, $adminId);
            return $after;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function getBySource(int $appId, string $sourceType, string $sourceId): array
    {
        $result = $this->findBySource($appId, $sourceType, $sourceId);
        if (!$result) {
            throw new \RuntimeException('Payment request not found', 404);
        }
        return $result;
    }

    private function receiveInternal(string $uuid, int $adminId, array $data, ?int $postedByAdminId, ?ApiContext $context): array
    {
        if ($adminId <= 0) {
            throw new \InvalidArgumentException('received_by_admin_id or received_by_admin_rsn is required');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $row = $this->lockByUuid($uuid);
            if ($row['status'] !== 'pending') {
                if (in_array($row['status'], ['received_by_admin', 'reconciled_to_treasury'], true)
                    && (int)($row['received_by_admin_id'] ?? 0) === $adminId) {
                    $pdo->commit();
                    return $this->getByUuid($uuid);
                }
                throw new \RuntimeException('Only pending payment requests can be marked as received', 409);
            }

            $accounts = new AccountService();
            $heldAccountId = $accounts->ensureAdminHeldAccount($adminId);
            $incomeAccountId = !empty($row['revenue_account_id'])
                ? $accounts->requirePostingAccount((int)$row['revenue_account_id'], ['income'])
                : $accounts->defaultRevenueAccountId((int)$row['app_id'], (string)$row['purpose']);

            $ledger = new LedgerService();
            $transaction = $ledger->postTransaction([
                'app_id' => (int)$row['app_id'],
                'source_type' => 'payment_request',
                'source_id' => $this->uniqueTransactionSourceId((int)$row['app_id'], 'payment_request', $row['request_uuid']),
                'transaction_type' => $row['purpose'] === 'entry_fee' ? 'entry_fee' : 'contribution',
                'description' => $row['description'],
                'notes' => $data['notes'] ?? null,
                'occurred_at' => $data['received_at'] ?? 'now',
                'posted_by_admin_id' => $postedByAdminId,
                'metadata' => [
                    'payment_request_id' => (int)$row['id'],
                    'received_via_api' => $context !== null,
                    'received_api_app_id' => $context?->appId,
                    'received_api_key_id' => $context?->apiKeyId,
                ],
            ], [
                [
                    'account_id' => $heldAccountId,
                    'direction' => 'debit',
                    'amount' => (int)$row['amount'],
                    'admin_id' => $adminId,
                    'player_rsn' => $row['player_rsn'],
                    'memo' => 'GP held by admin and owed to treasury',
                ],
                [
                    'account_id' => $incomeAccountId,
                    'direction' => 'credit',
                    'amount' => (int)$row['amount'],
                    'player_rsn' => $row['player_rsn'],
                    'memo' => $row['description'],
                ],
            ]);

            $update = $pdo->prepare(
                'UPDATE treasury_payment_requests
                 SET status = "received_by_admin",
                     received_by_admin_id = :admin_id,
                     received_transaction_id = :transaction_id,
                     received_at = :received_at
                 WHERE id = :id'
            );
            $update->execute([
                'admin_id' => $adminId,
                'transaction_id' => $transaction['id'],
                'received_at' => $this->normaliseDateTime($data['received_at'] ?? 'now'),
                'id' => $row['id'],
            ]);

            $pdo->commit();

            $after = $this->getByUuid($uuid);
            AuditService::log('payment_request.received', 'treasury_payment_request', $uuid, $this->format($row), $after, $context, $postedByAdminId);
            (new AdminBalanceOffsetService())->autoOffsetForAdmin($adminId, $postedByAdminId, (string)($data['received_at'] ?? 'now'));
            return $this->getByUuid($uuid);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function shouldAutoReceive(array $data): bool
    {
        return !empty($data['received_by_admin_id'])
            || !empty($data['received_by_admin_rsn'])
            || !empty($data['received_by_rsn'])
            || !empty($data['received_by']);
    }

    private function resolveReceivedByAdminId(array $data): int
    {
        $id = (int)($data['received_by_admin_id'] ?? $data['admin_id'] ?? 0);
        if ($id > 0) {
            $admin = (new AdminService())->get($id);
            if ((int)($admin['is_active'] ?? 0) !== 1) {
                throw new \InvalidArgumentException('Received-by admin is archived. Restore the user before using them in API receipts.');
            }
            return $id;
        }

        $rsn = trim((string)($data['received_by_admin_rsn'] ?? $data['received_by_rsn'] ?? $data['received_by'] ?? ''));
        if ($rsn === '') {
            throw new \InvalidArgumentException('received_by_admin_rsn is required when marking a payment as received via API');
        }

        $admin = (new AdminService())->findByRsn($rsn);
        if (!$admin) {
            throw new \InvalidArgumentException('No active treasury user found for received_by_admin_rsn: ' . $rsn);
        }

        return (int)$admin['id'];
    }

    private function uniqueTransactionSourceId(int $appId, string $sourceType, string $baseSourceId): string
    {
        $baseSourceId = substr($baseSourceId, 0, 92);
        $candidate = $baseSourceId;
        $i = 1;
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM treasury_transactions WHERE app_id = :app_id AND source_type = :source_type AND source_id = :source_id'
        );

        while (true) {
            $stmt->execute([
                'app_id' => $appId,
                'source_type' => $sourceType,
                'source_id' => $candidate,
            ]);
            if ((int)$stmt->fetchColumn() === 0) {
                return $candidate;
            }
            $i++;
            $candidate = substr($baseSourceId, 0, 92) . '-r' . $i;
        }
    }

    private function normaliseCreateData(array $data): array
    {
        if (!isset($data['player_rsn']) && isset($data['payer_rsn'])) {
            $data['player_rsn'] = $data['payer_rsn'];
        }
        if (!isset($data['purpose']) || $data['purpose'] === '') {
            $data['purpose'] = 'other';
        }
        return $data;
    }

    private function validateCreate(array $data): void
    {
        foreach (['source_type', 'source_id', 'player_rsn', 'amount'] as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                throw new \InvalidArgumentException($field . ' is required');
            }
        }

        if ((int)$data['amount'] <= 0) {
            throw new \InvalidArgumentException('amount must be greater than zero');
        }

        if (!in_array($data['purpose'], ['entry_fee', 'clan_contribution', 'other'], true)) {
            throw new \InvalidArgumentException('Invalid payment purpose');
        }

        if (empty($data['revenue_account_id']) && empty($data['revenue_account_code'])) {
            throw new \InvalidArgumentException('revenue_account_code is required');
        }
    }

    private function findBySource(int $appId, string $sourceType, string $sourceId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT pr.*, a.slug AS app_slug, a.name AS app_name,
                    admin.display_name AS received_by_display_name,
                    admin.rsn AS received_by_rsn,
                    revenue.code AS revenue_account_code,
                    revenue.name AS revenue_account_name
             FROM treasury_payment_requests pr
             JOIN treasury_apps a ON a.id = pr.app_id
             LEFT JOIN treasury_admins admin ON admin.id = pr.received_by_admin_id
             LEFT JOIN treasury_accounts revenue ON revenue.id = pr.revenue_account_id
             WHERE pr.app_id = :app_id AND pr.source_type = :source_type AND pr.source_id = :source_id
             LIMIT 1'
        );
        $stmt->execute([
            'app_id' => $appId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->format($row) : null;
    }

    private function lockByUuid(string $uuid): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM treasury_payment_requests WHERE request_uuid = :uuid LIMIT 1 FOR UPDATE');
        $stmt->execute(['uuid' => $uuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \RuntimeException('Payment request not found', 404);
        }
        return $row;
    }

    private function hydrateRow(array $row): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT pr.*, a.slug AS app_slug, a.name AS app_name,
                    admin.display_name AS received_by_display_name,
                    admin.rsn AS received_by_rsn,
                    revenue.code AS revenue_account_code,
                    revenue.name AS revenue_account_name
             FROM treasury_payment_requests pr
             JOIN treasury_apps a ON a.id = pr.app_id
             LEFT JOIN treasury_admins admin ON admin.id = pr.received_by_admin_id
             LEFT JOIN treasury_accounts revenue ON revenue.id = pr.revenue_account_id
             WHERE pr.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => (int)$row['id']]);
        $hydrated = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$hydrated) {
            throw new \RuntimeException('Payment request not found', 404);
        }
        return $hydrated;
    }

    private function format(array $row): array
    {
        return [
            'request_uuid' => $row['request_uuid'],
            'app' => [
                'slug' => $row['app_slug'] ?? null,
                'name' => $row['app_name'] ?? null,
            ],
            'source_type' => $row['source_type'],
            'source_id' => $row['source_id'],
            'payer_rsn' => $row['player_rsn'],
            'player_rsn' => $row['player_rsn'],
            'amount' => (int)$row['amount'],
            'purpose' => $row['purpose'],
            'description' => $row['description'],
            'revenue_account' => !empty($row['revenue_account_id']) ? [
                'id' => (int)$row['revenue_account_id'],
                'code' => $row['revenue_account_code'] ?? null,
                'name' => $row['revenue_account_name'] ?? null,
            ] : null,
            'status' => $row['status'],
            'received_by' => $row['received_by_admin_id'] ? [
                'admin_id' => (int)$row['received_by_admin_id'],
                'display_name' => $row['received_by_display_name'] ?? null,
                'rsn' => $row['received_by_rsn'] ?? null,
            ] : null,
            'received_transaction_id' => isset($row['received_transaction_id']) ? ($row['received_transaction_id'] === null ? null : (int)$row['received_transaction_id']) : null,
            'reconciliation_transaction_id' => isset($row['reconciliation_transaction_id']) ? ($row['reconciliation_transaction_id'] === null ? null : (int)$row['reconciliation_transaction_id']) : null,
            'created_at' => $row['created_at'],
            'received_at' => $row['received_at'] ?? null,
            'reconciled_at' => $row['reconciled_at'] ?? null,
            'metadata' => isset($row['metadata']) && $row['metadata'] !== null ? json_decode((string)$row['metadata'], true) : null,
        ];
    }

    private function normaliseDateTime(string $value): string
    {
        $dt = $value === 'now' ? new \DateTimeImmutable('now') : new \DateTimeImmutable($value);
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
