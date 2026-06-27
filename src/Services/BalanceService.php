<?php

declare(strict_types=1);

namespace Treasury\Services;

use Treasury\Database;

final class BalanceService
{
    public function summary(): array
    {
        $official = $this->accountBalanceByCode('1000');
        $adminHeld = $this->adminHeldBalances();
        $reimbursementBreakdown = $this->adminReimbursementBalances();
        $legacyReimbursements = $this->legacyReimbursementBalance();
        $reimbursements = $legacyReimbursements + array_sum(array_column($reimbursementBreakdown, 'balance'));

        $adminHeldTotal = array_sum(array_column($adminHeld, 'balance'));

        return [
            'official_treasury' => $official,
            'admin_held_pending' => $adminHeldTotal,
            'admin_reimbursements_payable' => $reimbursements,
            'total_clan_gp' => $official + $adminHeldTotal - $reimbursements,
            'admin_held_breakdown' => $adminHeld,
            'admin_reimbursements_breakdown' => $reimbursementBreakdown,
            'legacy_admin_reimbursements_payable' => $legacyReimbursements,
        ];
    }

    private function accountBalanceByCode(string $code): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COALESCE(SUM(
                CASE
                    WHEN t.status = "posted" AND a.normal_balance = "debit" AND le.direction = "debit" THEN le.amount
                    WHEN t.status = "posted" AND a.normal_balance = "debit" AND le.direction = "credit" THEN -le.amount
                    WHEN t.status = "posted" AND a.normal_balance = "credit" AND le.direction = "credit" THEN le.amount
                    WHEN t.status = "posted" AND a.normal_balance = "credit" AND le.direction = "debit" THEN -le.amount
                    ELSE 0
                END
            ), 0) AS balance
             FROM treasury_accounts a
             LEFT JOIN treasury_ledger_entries le ON le.account_id = a.id
             LEFT JOIN treasury_transactions t ON t.id = le.transaction_id AND t.status = "posted"
             WHERE a.code = :code'
        );
        $stmt->execute(['code' => $code]);
        return (int)$stmt->fetchColumn();
    }

    private function legacyReimbursementBalance(): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COALESCE(SUM(
                CASE
                    WHEN t.status = "posted" AND le.direction = "credit" THEN le.amount
                    WHEN t.status = "posted" AND le.direction = "debit" THEN -le.amount
                    ELSE 0
                END
            ), 0) AS balance
             FROM treasury_accounts a
             LEFT JOIN treasury_ledger_entries le ON le.account_id = a.id
             LEFT JOIN treasury_transactions t ON t.id = le.transaction_id AND t.status = "posted"
             WHERE a.code = "2000"'
        );
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    private function adminReimbursementBalances(): array
    {
        $stmt = Database::pdo()->query(
            'SELECT admin.id AS admin_id,
                    admin.rsn,
                    admin.display_name,
                    a.code AS account_code,
                    COALESCE(SUM(
                        CASE
                            WHEN t.status = "posted" AND le.direction = "credit" THEN le.amount
                            WHEN t.status = "posted" AND le.direction = "debit" THEN -le.amount
                            ELSE 0
                        END
                    ), 0) AS balance
             FROM treasury_accounts a
             JOIN treasury_admins admin ON admin.id = a.admin_id
             LEFT JOIN treasury_ledger_entries le ON le.account_id = a.id
             LEFT JOIN treasury_transactions t ON t.id = le.transaction_id AND t.status = "posted"
             WHERE a.code LIKE "2000:%"
             GROUP BY admin.id, admin.rsn, admin.display_name, a.code
             ORDER BY balance DESC, admin.rsn ASC'
        );

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $balance = (int)$row['balance'];
            if ($balance === 0) {
                continue;
            }
            $rows[] = [
                'admin_id' => (int)$row['admin_id'],
                'rsn' => $row['rsn'],
                'display_name' => $row['display_name'],
                'account_code' => $row['account_code'],
                'balance' => $balance,
            ];
        }

        return $rows;
    }

    private function adminHeldBalances(): array
    {
        $stmt = Database::pdo()->query(
            'SELECT admin.id AS admin_id,
                    admin.rsn,
                    admin.display_name,
                    a.code AS account_code,
                    COALESCE(SUM(
                        CASE
                            WHEN t.status = "posted" AND le.direction = "debit" THEN le.amount
                            WHEN t.status = "posted" AND le.direction = "credit" THEN -le.amount
                            ELSE 0
                        END
                    ), 0) AS balance
             FROM treasury_accounts a
             JOIN treasury_admins admin ON admin.id = a.admin_id
             LEFT JOIN treasury_ledger_entries le ON le.account_id = a.id
             LEFT JOIN treasury_transactions t ON t.id = le.transaction_id AND t.status = "posted"
             WHERE a.code LIKE "1100:%"
             GROUP BY admin.id, admin.rsn, admin.display_name, a.code
             ORDER BY balance DESC, admin.rsn ASC'
        );

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'admin_id' => (int)$row['admin_id'],
                'rsn' => $row['rsn'],
                'display_name' => $row['display_name'],
                'account_code' => $row['account_code'],
                'balance' => (int)$row['balance'],
            ];
        }

        return $rows;
    }
}
