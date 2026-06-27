<?php

declare(strict_types=1);

namespace Treasury\Services;

use PDO;
use Treasury\Auth\ApiContext;
use Treasury\Database;
use Treasury\Support\Uuid;

final class PayoutRequestService
{
    public function create(ApiContext $context, array $data): array
    {
        $data = $this->normaliseCreateData($data);
        $this->validateCreate($data);
        $existing = $this->findBySource($context->appId, $data['source_type'], $data['source_id']);
        if ($existing) {
            if ($this->shouldAutoPayByAdmin($data) && $existing['status'] === 'pending') {
                return $this->payByAdminFromApi($existing['request_uuid'], $context, $data);
            }
            return $existing;
        }

        $accounts = new AccountService();
        if (!empty($data['expense_account_code'])) {
            $expenseAccountId = $accounts->requirePostingAccountByCode((string)$data['expense_account_code'], ['expense']);
        } elseif (!empty($data['expense_account_id'])) {
            $expenseAccountId = $accounts->requirePostingAccount((int)$data['expense_account_id'], ['expense']);
        } else {
            throw new \InvalidArgumentException('expense_account_code is required');
        }

        $uuid = Uuid::v4();
        $stmt = Database::pdo()->prepare(
            'INSERT INTO treasury_payout_requests
             (request_uuid, app_id, source_type, source_id, payee_rsn, amount, payout_type, description, expense_account_id, metadata)
             VALUES
             (:request_uuid, :app_id, :source_type, :source_id, :payee_rsn, :amount, :payout_type, :description, :expense_account_id, :metadata)'
        );
        $stmt->execute([
            'request_uuid' => $uuid,
            'app_id' => $context->appId,
            'source_type' => $data['source_type'],
            'source_id' => $data['source_id'],
            'payee_rsn' => $data['payee_rsn'],
            'amount' => (int)$data['amount'],
            'payout_type' => $data['payout_type'],
            'description' => $data['description'] ?? 'Money paid to ' . $data['payee_rsn'],
            'expense_account_id' => $expenseAccountId,
            'metadata' => isset($data['metadata']) && is_array($data['metadata']) ? json_encode($data['metadata'], JSON_UNESCAPED_SLASHES) : null,
        ]);

        $created = $this->getByUuid($uuid);
        AuditService::log('payout_request.created', 'treasury_payout_request', $uuid, null, $created, $context);

        if ($this->shouldAutoPayByAdmin($data)) {
            return $this->payByAdminFromApi($uuid, $context, $data);
        }

        return $created;
    }

    public function getByUuid(string $uuid): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT pr.*, a.slug AS app_slug, a.name AS app_name,
                    admin.display_name AS paid_by_display_name,
                    admin.rsn AS paid_by_rsn,
                    expense.code AS expense_account_code,
                    expense.name AS expense_account_name
             FROM treasury_payout_requests pr
             JOIN treasury_apps a ON a.id = pr.app_id
             LEFT JOIN treasury_admins admin ON admin.id = pr.paid_by_admin_id
             LEFT JOIN treasury_accounts expense ON expense.id = pr.expense_account_id
             WHERE pr.request_uuid = :uuid
             LIMIT 1'
        );
        $stmt->execute(['uuid' => $uuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \RuntimeException('Payout request not found', 404);
        }

        return $this->format($row);
    }

    public function payFromTreasury(string $uuid, array $data): array
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
                throw new \RuntimeException('Only pending payout requests can be paid from treasury', 409);
            }

            $accounts = new AccountService();
            $treasuryAccountId = $accounts->accountIdByCode('1000');
            $expenseAccountId = !empty($row['expense_account_id'])
                ? $accounts->requirePostingAccount((int)$row['expense_account_id'], ['expense'])
                : $accounts->defaultExpenseAccountId((int)$row['app_id'], (string)$row['payout_type']);

            $transaction = (new LedgerService())->postTransaction([
                'app_id' => (int)$row['app_id'],
                'source_type' => 'payout_request',
                'source_id' => $this->uniqueTransactionSourceId((int)$row['app_id'], 'payout_request', $row['request_uuid']),
                'transaction_type' => $row['payout_type'] === 'prize' ? 'prize_payout' : 'expense',
                'description' => $row['description'],
                'notes' => $data['notes'] ?? null,
                'occurred_at' => $data['paid_at'] ?? 'now',
                'posted_by_admin_id' => $adminId,
                'metadata' => ['payout_request_id' => (int)$row['id'], 'payment_method' => 'official_treasury'],
            ], [
                [
                    'account_id' => $expenseAccountId,
                    'direction' => 'debit',
                    'amount' => (int)$row['amount'],
                    'player_rsn' => $row['payee_rsn'],
                    'memo' => $row['description'],
                ],
                [
                    'account_id' => $treasuryAccountId,
                    'direction' => 'credit',
                    'amount' => (int)$row['amount'],
                    'player_rsn' => $row['payee_rsn'],
                    'memo' => 'Paid from official treasury',
                ],
            ]);

            $update = $pdo->prepare(
                'UPDATE treasury_payout_requests
                 SET status = "paid_from_treasury",
                     paid_transaction_id = :transaction_id,
                     paid_at = :paid_at
                 WHERE id = :id'
            );
            $update->execute([
                'transaction_id' => $transaction['id'],
                'paid_at' => $this->normaliseDateTime($data['paid_at'] ?? 'now'),
                'id' => $row['id'],
            ]);

            $pdo->commit();
            $after = $this->getByUuid($uuid);
            AuditService::log('payout_request.paid_from_treasury', 'treasury_payout_request', $uuid, $this->format($row), $after, null, $adminId);
            return $after;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function payByAdmin(string $uuid, array $data): array
    {
        $adminId = (int)($data['admin_id'] ?? 0);
        if ($adminId <= 0) {
            throw new \InvalidArgumentException('admin_id is required');
        }

        return $this->payByAdminInternal($uuid, $adminId, $data, $adminId, null);
    }

    public function payByAdminFromApi(string $uuid, ApiContext $context, array $data): array
    {
        if (!$context->can('payouts:pay')) {
            throw new \RuntimeException('API key does not have required scope: payouts:pay', 403);
        }

        $adminId = $this->resolvePaidByAdminId($data);
        return $this->payByAdminInternal($uuid, $adminId, $data, null, $context);
    }

    private function payByAdminInternal(string $uuid, int $adminId, array $data, ?int $postedByAdminId, ?ApiContext $context): array
    {
        if ($adminId <= 0) {
            throw new \InvalidArgumentException('paid_by_admin_id or paid_by_admin_rsn is required');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $row = $this->lockByUuid($uuid);
            if ($row['status'] !== 'pending') {
                if (in_array($row['status'], ['paid_by_admin', 'reimbursed'], true)
                    && (int)($row['paid_by_admin_id'] ?? 0) === $adminId) {
                    $pdo->commit();
                    return $this->getByUuid($uuid);
                }
                throw new \RuntimeException('Only pending payout requests can be marked as paid by admin', 409);
            }

            $accounts = new AccountService();
            $payableAccountId = $accounts->accountIdByCode('2000');
            $expenseAccountId = !empty($row['expense_account_id'])
                ? $accounts->requirePostingAccount((int)$row['expense_account_id'], ['expense'])
                : $accounts->defaultExpenseAccountId((int)$row['app_id'], (string)$row['payout_type']);

            $transaction = (new LedgerService())->postTransaction([
                'app_id' => (int)$row['app_id'],
                'source_type' => 'payout_request',
                'source_id' => $this->uniqueTransactionSourceId((int)$row['app_id'], 'payout_request', $row['request_uuid']),
                'transaction_type' => $row['payout_type'] === 'prize' ? 'prize_payout' : 'expense',
                'description' => $row['description'],
                'notes' => $data['notes'] ?? null,
                'occurred_at' => $data['paid_at'] ?? 'now',
                'posted_by_admin_id' => $postedByAdminId,
                'metadata' => [
                    'payout_request_id' => (int)$row['id'],
                    'payment_method' => 'admin_paid',
                    'paid_via_api' => $context !== null,
                    'paid_api_app_id' => $context?->appId,
                    'paid_api_key_id' => $context?->apiKeyId,
                ],
            ], [
                [
                    'account_id' => $expenseAccountId,
                    'direction' => 'debit',
                    'amount' => (int)$row['amount'],
                    'admin_id' => $adminId,
                    'player_rsn' => $row['payee_rsn'],
                    'memo' => $row['description'],
                ],
                [
                    'account_id' => $payableAccountId,
                    'direction' => 'credit',
                    'amount' => (int)$row['amount'],
                    'admin_id' => $adminId,
                    'player_rsn' => $row['payee_rsn'],
                    'memo' => 'Admin reimbursement owed',
                ],
            ]);

            $update = $pdo->prepare(
                'UPDATE treasury_payout_requests
                 SET status = "paid_by_admin",
                     paid_by_admin_id = :admin_id,
                     paid_transaction_id = :transaction_id,
                     paid_at = :paid_at
                 WHERE id = :id'
            );
            $update->execute([
                'admin_id' => $adminId,
                'transaction_id' => $transaction['id'],
                'paid_at' => $this->normaliseDateTime($data['paid_at'] ?? 'now'),
                'id' => $row['id'],
            ]);

            $pdo->commit();
            $after = $this->getByUuid($uuid);
            AuditService::log('payout_request.paid_by_admin', 'treasury_payout_request', $uuid, $this->format($row), $after, $context, $postedByAdminId);
            return $after;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function reimburseAdmin(string $uuid, array $data): array
    {
        $adminId = (int)($data['admin_id'] ?? 0);
        if ($adminId <= 0) {
            throw new \InvalidArgumentException('admin_id is required');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $row = $this->lockByUuid($uuid);
            if ($row['status'] !== 'paid_by_admin') {
                throw new \RuntimeException('Only admin-paid payout requests can be reimbursed', 409);
            }

            $paidByAdminId = (int)$row['paid_by_admin_id'];
            $accounts = new AccountService();
            $treasuryAccountId = $accounts->accountIdByCode('1000');
            $payableAccountId = $accounts->accountIdByCode('2000');

            $transaction = (new LedgerService())->postTransaction([
                'app_id' => (int)$row['app_id'],
                'source_type' => 'payout_request_reimbursement',
                'source_id' => $this->uniqueTransactionSourceId((int)$row['app_id'], 'payout_request_reimbursement', $row['request_uuid']),
                'transaction_type' => 'admin_reimbursement',
                'description' => 'Reimbursement for: ' . $row['description'],
                'notes' => $data['notes'] ?? null,
                'occurred_at' => $data['reimbursed_at'] ?? 'now',
                'posted_by_admin_id' => $adminId,
                'metadata' => ['payout_request_id' => (int)$row['id'], 'reimbursed_admin_id' => $paidByAdminId],
            ], [
                [
                    'account_id' => $payableAccountId,
                    'direction' => 'debit',
                    'amount' => (int)$row['amount'],
                    'admin_id' => $paidByAdminId,
                    'player_rsn' => $row['payee_rsn'],
                    'memo' => 'Clear reimbursement payable',
                ],
                [
                    'account_id' => $treasuryAccountId,
                    'direction' => 'credit',
                    'amount' => (int)$row['amount'],
                    'admin_id' => $paidByAdminId,
                    'player_rsn' => $row['payee_rsn'],
                    'memo' => 'Reimbursed from official treasury',
                ],
            ]);

            $update = $pdo->prepare(
                'UPDATE treasury_payout_requests
                 SET status = "reimbursed",
                     reimbursement_transaction_id = :transaction_id,
                     reimbursed_at = :reimbursed_at
                 WHERE id = :id'
            );
            $update->execute([
                'transaction_id' => $transaction['id'],
                'reimbursed_at' => $this->normaliseDateTime($data['reimbursed_at'] ?? 'now'),
                'id' => $row['id'],
            ]);

            $pdo->commit();
            $after = $this->getByUuid($uuid);
            AuditService::log('payout_request.reimbursed', 'treasury_payout_request', $uuid, $this->format($row), $after, null, $adminId);
            return $after;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
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
                throw new \RuntimeException('Only pending payout requests can be cancelled. Paid payouts need a ledger correction instead.', 409);
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
                'UPDATE treasury_payout_requests
                 SET status = "cancelled", metadata = :metadata
                 WHERE id = :id'
            );
            $stmt->execute([
                'metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES),
                'id' => (int)$row['id'],
            ]);

            $pdo->commit();
            $after = $this->getByUuid($uuid);
            AuditService::log('payout_request.cancelled', 'treasury_payout_request', $uuid, $before, $after, null, $adminId);
            return $after;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }


    private function shouldAutoPayByAdmin(array $data): bool
    {
        return !empty($data['paid_by_admin_id'])
            || !empty($data['paid_by_admin_rsn'])
            || !empty($data['paid_by_rsn'])
            || !empty($data['paid_by']);
    }

    private function resolvePaidByAdminId(array $data): int
    {
        $id = (int)($data['paid_by_admin_id'] ?? $data['admin_id'] ?? 0);
        if ($id > 0) {
            $admin = (new AdminService())->get($id);
            if ((int)($admin['is_active'] ?? 0) !== 1) {
                throw new \InvalidArgumentException('Paid-by admin is archived. Restore the user before using them in API payouts.');
            }
            return $id;
        }

        $rsn = trim((string)($data['paid_by_admin_rsn'] ?? $data['paid_by_rsn'] ?? $data['paid_by'] ?? ''));
        if ($rsn === '') {
            throw new \InvalidArgumentException('paid_by_admin_rsn is required when marking a payout as paid by admin via API');
        }

        $admin = (new AdminService())->findByRsn($rsn);
        if (!$admin) {
            throw new \InvalidArgumentException('No active treasury user found for paid_by_admin_rsn: ' . $rsn);
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
        if (!isset($data['payout_type']) || $data['payout_type'] === '') {
            $data['payout_type'] = 'expense';
        }
        return $data;
    }

    private function validateCreate(array $data): void
    {
        foreach (['source_type', 'source_id', 'payee_rsn', 'amount'] as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                throw new \InvalidArgumentException($field . ' is required');
            }
        }

        if ((int)$data['amount'] <= 0) {
            throw new \InvalidArgumentException('amount must be greater than zero');
        }

        if (!in_array($data['payout_type'], ['prize', 'expense', 'admin_reimbursement'], true)) {
            throw new \InvalidArgumentException('Invalid payout type');
        }

        if (empty($data['expense_account_id']) && empty($data['expense_account_code'])) {
            throw new \InvalidArgumentException('expense_account_code is required');
        }
    }

    public function getBySource(int $appId, string $sourceType, string $sourceId): array
    {
        $result = $this->findBySource($appId, $sourceType, $sourceId);
        if (!$result) {
            throw new \RuntimeException('Payout request not found', 404);
        }
        return $result;
    }

    private function findBySource(int $appId, string $sourceType, string $sourceId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT pr.*, a.slug AS app_slug, a.name AS app_name,
                    admin.display_name AS paid_by_display_name,
                    admin.rsn AS paid_by_rsn,
                    expense.code AS expense_account_code,
                    expense.name AS expense_account_name
             FROM treasury_payout_requests pr
             JOIN treasury_apps a ON a.id = pr.app_id
             LEFT JOIN treasury_admins admin ON admin.id = pr.paid_by_admin_id
             LEFT JOIN treasury_accounts expense ON expense.id = pr.expense_account_id
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
        $stmt = Database::pdo()->prepare('SELECT * FROM treasury_payout_requests WHERE request_uuid = :uuid LIMIT 1 FOR UPDATE');
        $stmt->execute(['uuid' => $uuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \RuntimeException('Payout request not found', 404);
        }
        return $row;
    }



    private function hydrateRow(array $row): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT pr.*, a.slug AS app_slug, a.name AS app_name,
                    admin.display_name AS paid_by_display_name,
                    admin.rsn AS paid_by_rsn,
                    expense.code AS expense_account_code,
                    expense.name AS expense_account_name
             FROM treasury_payout_requests pr
             JOIN treasury_apps a ON a.id = pr.app_id
             LEFT JOIN treasury_admins admin ON admin.id = pr.paid_by_admin_id
             LEFT JOIN treasury_accounts expense ON expense.id = pr.expense_account_id
             WHERE pr.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => (int)$row['id']]);
        $hydrated = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$hydrated) {
            throw new \RuntimeException('Payout request not found', 404);
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
            'payee_rsn' => $row['payee_rsn'],
            'amount' => (int)$row['amount'],
            'payout_type' => $row['payout_type'],
            'description' => $row['description'],
            'expense_account' => !empty($row['expense_account_id']) ? [
                'id' => (int)$row['expense_account_id'],
                'code' => $row['expense_account_code'] ?? null,
                'name' => $row['expense_account_name'] ?? null,
            ] : null,
            'status' => $row['status'],
            'paid_by_admin' => $row['paid_by_admin_id'] ? [
                'admin_id' => (int)$row['paid_by_admin_id'],
                'display_name' => $row['paid_by_display_name'] ?? null,
                'rsn' => $row['paid_by_rsn'] ?? null,
            ] : null,
            'paid_transaction_id' => isset($row['paid_transaction_id']) ? ($row['paid_transaction_id'] === null ? null : (int)$row['paid_transaction_id']) : null,
            'reimbursement_transaction_id' => isset($row['reimbursement_transaction_id']) ? ($row['reimbursement_transaction_id'] === null ? null : (int)$row['reimbursement_transaction_id']) : null,
            'created_at' => $row['created_at'],
            'paid_at' => $row['paid_at'] ?? null,
            'reimbursed_at' => $row['reimbursed_at'] ?? null,
            'metadata' => isset($row['metadata']) && $row['metadata'] !== null ? json_decode((string)$row['metadata'], true) : null,
        ];
    }

    private function normaliseDateTime(string $value): string
    {
        $dt = $value === 'now' ? new \DateTimeImmutable('now') : new \DateTimeImmutable($value);
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
