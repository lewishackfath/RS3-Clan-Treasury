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
        $this->validateCreate($data);
        $pdo = Database::pdo();

        $existing = $this->findBySource($context->appId, $data['source_type'], $data['source_id']);
        if ($existing) {
            return $existing;
        }

        $uuid = Uuid::v4();
        $stmt = $pdo->prepare(
            'INSERT INTO treasury_payment_requests
             (request_uuid, app_id, source_type, source_id, player_rsn, amount, purpose, description, metadata)
             VALUES
             (:request_uuid, :app_id, :source_type, :source_id, :player_rsn, :amount, :purpose, :description, :metadata)'
        );
        $stmt->execute([
            'request_uuid' => $uuid,
            'app_id' => $context->appId,
            'source_type' => $data['source_type'],
            'source_id' => $data['source_id'],
            'player_rsn' => $data['player_rsn'],
            'amount' => (int)$data['amount'],
            'purpose' => $data['purpose'],
            'description' => $data['description'] ?? $data['purpose'] . ' from ' . $data['player_rsn'],
            'metadata' => isset($data['metadata']) && is_array($data['metadata']) ? json_encode($data['metadata'], JSON_UNESCAPED_SLASHES) : null,
        ]);

        $created = $this->getByUuid($uuid);
        AuditService::log('payment_request.created', 'treasury_payment_request', $uuid, null, $created, $context);

        return $created;
    }

    public function getByUuid(string $uuid): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT pr.*, a.slug AS app_slug, a.name AS app_name,
                    admin.display_name AS received_by_display_name,
                    admin.rsn AS received_by_rsn
             FROM treasury_payment_requests pr
             JOIN treasury_apps a ON a.id = pr.app_id
             LEFT JOIN treasury_admins admin ON admin.id = pr.received_by_admin_id
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
        if ($adminId <= 0) {
            throw new \InvalidArgumentException('admin_id is required');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $row = $this->lockByUuid($uuid);
            if ($row['status'] !== 'pending') {
                throw new \RuntimeException('Only pending payment requests can be marked as received', 409);
            }

            $accounts = new AccountService();
            $heldAccountId = $accounts->ensureAdminHeldAccount($adminId);
            $incomeAccountId = match ($row['purpose']) {
                'entry_fee' => $accounts->accountIdByCode('4100'),
                'clan_contribution' => $accounts->accountIdByCode('4300'),
                default => $accounts->accountIdByCode('4300'),
            };

            $ledger = new LedgerService();
            $transaction = $ledger->postTransaction([
                'app_id' => (int)$row['app_id'],
                'source_type' => 'payment_request',
                'source_id' => $row['request_uuid'],
                'transaction_type' => $row['purpose'] === 'entry_fee' ? 'entry_fee' : 'contribution',
                'description' => $row['description'],
                'notes' => $data['notes'] ?? null,
                'occurred_at' => $data['received_at'] ?? 'now',
                'posted_by_admin_id' => $adminId,
                'metadata' => ['payment_request_id' => (int)$row['id']],
            ], [
                [
                    'account_id' => $heldAccountId,
                    'direction' => 'debit',
                    'amount' => (int)$row['amount'],
                    'admin_id' => $adminId,
                    'player_rsn' => $row['player_rsn'],
                    'memo' => 'GP received by admin',
                ],
                [
                    'account_id' => $incomeAccountId,
                    'direction' => 'credit',
                    'amount' => (int)$row['amount'],
                    'player_rsn' => $row['player_rsn'],
                    'memo' => $row['purpose'],
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
            AuditService::log('payment_request.received', 'treasury_payment_request', $uuid, $this->format($row), $after, null, $adminId);
            return $after;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function validateCreate(array $data): void
    {
        foreach (['source_type', 'source_id', 'player_rsn', 'amount', 'purpose'] as $field) {
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
    }

    private function findBySource(int $appId, string $sourceType, string $sourceId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT pr.*, a.slug AS app_slug, a.name AS app_name,
                    admin.display_name AS received_by_display_name,
                    admin.rsn AS received_by_rsn
             FROM treasury_payment_requests pr
             JOIN treasury_apps a ON a.id = pr.app_id
             LEFT JOIN treasury_admins admin ON admin.id = pr.received_by_admin_id
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
            'player_rsn' => $row['player_rsn'],
            'amount' => (int)$row['amount'],
            'purpose' => $row['purpose'],
            'description' => $row['description'],
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
