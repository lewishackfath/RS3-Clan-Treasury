<?php

declare(strict_types=1);

namespace Treasury\Services;

use PDO;
use Treasury\Database;

final class AccountService
{
    public function accountIdByCode(string $code): int
    {
        $stmt = Database::pdo()->prepare('SELECT id FROM treasury_accounts WHERE code = :code AND is_active = 1 LIMIT 1');
        $stmt->execute(['code' => $code]);
        $id = $stmt->fetchColumn();
        if (!$id) {
            throw new \RuntimeException('Treasury account not found: ' . $code, 500);
        }

        return (int)$id;
    }

    public function accountById(int $id): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT ta.*, parent.code AS parent_code, parent.name AS parent_name, app.name AS app_name, app.slug AS app_slug
             FROM treasury_accounts ta
             LEFT JOIN treasury_accounts parent ON parent.id = ta.parent_account_id
             LEFT JOIN treasury_apps app ON app.id = ta.app_id
             WHERE ta.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \RuntimeException('Treasury account not found', 404);
        }

        return $row;
    }

    public function requirePostingAccount(int $id, array $allowedTypes): int
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException('Choose a ledger account.');
        }

        $account = $this->accountById($id);
        if (!in_array($account['account_type'], $allowedTypes, true)) {
            throw new \InvalidArgumentException('The selected ledger account cannot be used for this transaction.');
        }
        if ((int)$account['is_active'] !== 1) {
            throw new \InvalidArgumentException('The selected ledger account is inactive.');
        }

        return (int)$account['id'];
    }



    public function requirePostingAccountByCode(string $code, array $allowedTypes): int
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            throw new \InvalidArgumentException('Ledger account code is required.');
        }

        $stmt = Database::pdo()->prepare(
            'SELECT id FROM treasury_accounts WHERE code = :code LIMIT 1'
        );
        $stmt->execute(['code' => $code]);
        $id = $stmt->fetchColumn();
        if (!$id) {
            throw new \InvalidArgumentException('Ledger account not found: ' . $code);
        }

        return $this->requirePostingAccount((int)$id, $allowedTypes);
    }

    public function all(bool $includeInactive = true): array
    {
        $sql = 'SELECT ta.*, parent.code AS parent_code, parent.name AS parent_name,
                       app.name AS app_name, app.slug AS app_slug,
                       COALESCE(ledger_usage.ledger_entry_count, 0) AS ledger_entry_count,
                       COALESCE(payment_usage.payment_request_count, 0) AS payment_request_count,
                       COALESCE(payout_usage.payout_request_count, 0) AS payout_request_count,
                       COALESCE(child_usage.child_account_count, 0) AS child_account_count
                FROM treasury_accounts ta
                LEFT JOIN treasury_accounts parent ON parent.id = ta.parent_account_id
                LEFT JOIN treasury_apps app ON app.id = ta.app_id
                LEFT JOIN (
                    SELECT account_id, COUNT(*) AS ledger_entry_count
                    FROM treasury_ledger_entries
                    GROUP BY account_id
                ) ledger_usage ON ledger_usage.account_id = ta.id
                LEFT JOIN (
                    SELECT revenue_account_id AS account_id, COUNT(*) AS payment_request_count
                    FROM treasury_payment_requests
                    WHERE revenue_account_id IS NOT NULL
                    GROUP BY revenue_account_id
                ) payment_usage ON payment_usage.account_id = ta.id
                LEFT JOIN (
                    SELECT expense_account_id AS account_id, COUNT(*) AS payout_request_count
                    FROM treasury_payout_requests
                    WHERE expense_account_id IS NOT NULL
                    GROUP BY expense_account_id
                ) payout_usage ON payout_usage.account_id = ta.id
                LEFT JOIN (
                    SELECT parent_account_id AS account_id, COUNT(*) AS child_account_count
                    FROM treasury_accounts
                    WHERE parent_account_id IS NOT NULL
                    GROUP BY parent_account_id
                ) child_usage ON child_usage.account_id = ta.id';
        if (!$includeInactive) {
            $sql .= ' WHERE ta.is_active = 1';
        }
        $sql .= ' ORDER BY
                    FIELD(ta.account_type, "asset", "liability", "equity", "income", "expense", "clearing"),
                    ta.code ASC';

        return Database::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function postingAccounts(string $type): array
    {
        if (!in_array($type, ['income', 'expense'], true)) {
            throw new \InvalidArgumentException('Unsupported posting account type.');
        }

        $stmt = Database::pdo()->prepare(
            'SELECT ta.*, app.name AS app_name, app.slug AS app_slug
             FROM treasury_accounts ta
             LEFT JOIN treasury_apps app ON app.id = ta.app_id
             WHERE ta.account_type = :type
               AND ta.is_active = 1
               AND ta.is_system = 0
             ORDER BY ta.code ASC'
        );
        $stmt->execute(['type' => $type]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createPostingAccount(array $data, int $actorAdminId): array
    {
        $type = (string)($data['account_type'] ?? '');
        if (!in_array($type, ['income', 'expense'], true)) {
            throw new \InvalidArgumentException('Only revenue and expense accounts can be created from the admin UI.');
        }

        $code = strtoupper(trim((string)($data['code'] ?? '')));
        $name = trim((string)($data['name'] ?? ''));
        $appId = (int)($data['app_id'] ?? 0);

        if ($code === '' || $name === '') {
            throw new \InvalidArgumentException('Account code and name are required.');
        }
        if (!preg_match('/^[A-Z0-9:_-]{2,50}$/', $code)) {
            throw new \InvalidArgumentException('Account code can only contain letters, numbers, colon, underscore, or dash.');
        }

        $duplicate = Database::pdo()->prepare('SELECT id FROM treasury_accounts WHERE code = :code LIMIT 1');
        $duplicate->execute(['code' => $code]);
        if ($duplicate->fetchColumn()) {
            throw new \InvalidArgumentException('Another ledger account already uses that code.');
        }

        $parentId = $this->accountIdByCode($type === 'income' ? '4000' : '5000');
        $normalBalance = $type === 'income' ? 'credit' : 'debit';

        $stmt = Database::pdo()->prepare(
            'INSERT INTO treasury_accounts
             (code, name, account_type, parent_account_id, app_id, normal_balance, is_system, is_active)
             VALUES
             (:code, :name, :account_type, :parent_account_id, :app_id, :normal_balance, 0, 1)'
        );
        $stmt->execute([
            'code' => $code,
            'name' => $name,
            'account_type' => $type,
            'parent_account_id' => $parentId,
            'app_id' => $appId > 0 ? $appId : null,
            'normal_balance' => $normalBalance,
        ]);

        $created = $this->accountById((int)Database::pdo()->lastInsertId());
        AuditService::log('account.created', 'treasury_account', (string)$created['id'], null, $created, null, $actorAdminId);

        return $created;
    }

    public function updatePostingAccount(int $accountId, array $data, int $actorAdminId): array
    {
        $before = $this->accountById($accountId);
        if ((int)$before['is_system'] === 1) {
            throw new \RuntimeException('System accounts cannot be edited.');
        }
        if (!in_array($before['account_type'], ['income', 'expense'], true)) {
            throw new \RuntimeException('Only revenue and expense accounts can be edited from the admin UI.');
        }

        $code = strtoupper(trim((string)($data['code'] ?? '')));
        $name = trim((string)($data['name'] ?? ''));
        $appId = (int)($data['app_id'] ?? 0);

        if ($code === '' || $name === '') {
            throw new \InvalidArgumentException('Account code and name are required.');
        }
        if (!preg_match('/^[A-Z0-9:_-]{2,50}$/', $code)) {
            throw new \InvalidArgumentException('Account code can only contain letters, numbers, colon, underscore, or dash.');
        }

        $duplicate = Database::pdo()->prepare('SELECT id FROM treasury_accounts WHERE code = :code AND id <> :id LIMIT 1');
        $duplicate->execute(['code' => $code, 'id' => $accountId]);
        if ($duplicate->fetchColumn()) {
            throw new \InvalidArgumentException('Another ledger account already uses that code.');
        }

        $stmt = Database::pdo()->prepare(
            'UPDATE treasury_accounts
             SET code = :code, name = :name, app_id = :app_id
             WHERE id = :id'
        );
        $stmt->execute([
            'code' => $code,
            'name' => $name,
            'app_id' => $appId > 0 ? $appId : null,
            'id' => $accountId,
        ]);

        $after = $this->accountById($accountId);
        AuditService::log('account.updated', 'treasury_account', (string)$accountId, $before, $after, null, $actorAdminId);

        return $after;
    }

    public function deleteUnusedPostingAccount(int $accountId, int $actorAdminId): void
    {
        $before = $this->accountById($accountId);
        if ((int)$before['is_system'] === 1) {
            throw new \RuntimeException('System accounts cannot be deleted.');
        }
        if (!in_array($before['account_type'], ['income', 'expense'], true)) {
            throw new \RuntimeException('Only revenue and expense accounts can be deleted from the admin UI.');
        }

        $usage = $this->usageCounts($accountId);
        if ($usage['total'] > 0) {
            throw new \RuntimeException('This account has ledger history or request references and must be archived instead of deleted.');
        }

        $stmt = Database::pdo()->prepare('DELETE FROM treasury_accounts WHERE id = :id');
        $stmt->execute(['id' => $accountId]);

        AuditService::log('account.deleted', 'treasury_account', (string)$accountId, $before, null, null, $actorAdminId);
    }

    public function usageCounts(int $accountId): array
    {
        $pdo = Database::pdo();
        $ledger = $pdo->prepare('SELECT COUNT(*) FROM treasury_ledger_entries WHERE account_id = :id');
        $ledger->execute(['id' => $accountId]);

        $payments = $pdo->prepare('SELECT COUNT(*) FROM treasury_payment_requests WHERE revenue_account_id = :id');
        $payments->execute(['id' => $accountId]);

        $payouts = $pdo->prepare('SELECT COUNT(*) FROM treasury_payout_requests WHERE expense_account_id = :id');
        $payouts->execute(['id' => $accountId]);

        $children = $pdo->prepare('SELECT COUNT(*) FROM treasury_accounts WHERE parent_account_id = :id');
        $children->execute(['id' => $accountId]);

        $counts = [
            'ledger' => (int)$ledger->fetchColumn(),
            'payments' => (int)$payments->fetchColumn(),
            'payouts' => (int)$payouts->fetchColumn(),
            'children' => (int)$children->fetchColumn(),
        ];
        $counts['total'] = array_sum($counts);

        return $counts;
    }

    public function setActive(int $accountId, bool $active, int $actorAdminId): array
    {
        $before = $this->accountById($accountId);
        if ((int)$before['is_system'] === 1) {
            throw new \RuntimeException('System accounts cannot be archived or restored.');
        }

        $stmt = Database::pdo()->prepare('UPDATE treasury_accounts SET is_active = :active WHERE id = :id');
        $stmt->execute([
            'active' => $active ? 1 : 0,
            'id' => $accountId,
        ]);

        $after = $this->accountById($accountId);
        AuditService::log($active ? 'account.restored' : 'account.archived', 'treasury_account', (string)$accountId, $before, $after, null, $actorAdminId);

        return $after;
    }

    public function defaultRevenueAccountId(int $appId, string $purpose): int
    {
        return $this->firstActivePostingAccountId('income') ?? $this->accountIdByCode('4000');
    }

    public function defaultExpenseAccountId(int $appId, string $payoutType): int
    {
        return $this->firstActivePostingAccountId('expense') ?? $this->accountIdByCode('5000');
    }


    private function firstActivePostingAccountId(string $type): ?int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id FROM treasury_accounts
             WHERE account_type = :type AND is_active = 1 AND is_system = 0
             ORDER BY code ASC
             LIMIT 1'
        );
        $stmt->execute(['type' => $type]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }

    public function ensureAdminHeldAccount(int $adminId): int
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare('SELECT id FROM treasury_accounts WHERE admin_id = :admin_id AND account_type = "asset" AND code LIKE "1100:%" LIMIT 1');
        $stmt->execute(['admin_id' => $adminId]);
        $existing = $stmt->fetchColumn();
        if ($existing) {
            return (int)$existing;
        }

        $adminStmt = $pdo->prepare('SELECT rsn, display_name FROM treasury_admins WHERE id = :id AND is_active = 1 LIMIT 1');
        $adminStmt->execute(['id' => $adminId]);
        $admin = $adminStmt->fetch(PDO::FETCH_ASSOC);
        if (!$admin) {
            throw new \RuntimeException('Treasury admin not found', 404);
        }

        $parentId = $this->accountIdByCode('1100');
        $code = '1100:' . $adminId;
        $name = 'Funds Owed by Admin - ' . ($admin['display_name'] ?: $admin['rsn']);

        $insert = $pdo->prepare(
            'INSERT INTO treasury_accounts
             (code, name, account_type, parent_account_id, admin_id, normal_balance, is_system, is_active)
             VALUES
             (:code, :name, "asset", :parent_id, :admin_id, "debit", 1, 1)'
        );
        $insert->execute([
            'code' => $code,
            'name' => $name,
            'parent_id' => $parentId,
            'admin_id' => $adminId,
        ]);

        return (int)$pdo->lastInsertId();
    }

    private function appSlug(int $appId): ?string
    {
        if ($appId <= 0) {
            return null;
        }

        $stmt = Database::pdo()->prepare('SELECT slug FROM treasury_apps WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $appId]);
        $slug = $stmt->fetchColumn();

        return $slug ? (string)$slug : null;
    }
}
