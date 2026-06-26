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
        $name = 'Admin Held Funds - ' . ($admin['display_name'] ?: $admin['rsn']);

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
}
