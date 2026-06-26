<?php

declare(strict_types=1);

namespace Treasury\Services;

use PDO;
use Treasury\Database;

final class ReportService
{
    public function profitAndLoss(?string $fromUtc = null, ?string $toUtc = null): array
    {
        [$dateWhere, $params] = $this->dateWhere('t', $fromUtc, $toUtc);

        $stmt = Database::pdo()->prepare(
            'SELECT acc.id, acc.code, acc.name, acc.account_type, acc.normal_balance,
                    SUM(CASE WHEN le.direction = "debit" THEN le.amount ELSE 0 END) AS debits,
                    SUM(CASE WHEN le.direction = "credit" THEN le.amount ELSE 0 END) AS credits,
                    SUM(' . $this->normalBalanceExpression('acc', 'le') . ') AS movement
             FROM treasury_ledger_entries le
             JOIN treasury_transactions t ON t.id = le.transaction_id
             JOIN treasury_accounts acc ON acc.id = le.account_id
             WHERE acc.account_type IN ("income", "expense")
               AND ' . $this->reportableTransactionWhere('t') . $dateWhere . '
             GROUP BY acc.id, acc.code, acc.name, acc.account_type, acc.normal_balance
             HAVING movement <> 0
             ORDER BY FIELD(acc.account_type, "income", "expense"), acc.code ASC'
        );
        $stmt->execute($params);

        $income = [];
        $expenses = [];
        $incomeTotal = 0;
        $expenseTotal = 0;

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['debits'] = (int)$row['debits'];
            $row['credits'] = (int)$row['credits'];
            $row['movement'] = (int)$row['movement'];

            if ($row['account_type'] === 'income') {
                $income[] = $row;
                $incomeTotal += (int)$row['movement'];
            } else {
                $expenses[] = $row;
                $expenseTotal += (int)$row['movement'];
            }
        }

        return [
            'income' => $income,
            'expenses' => $expenses,
            'income_total' => $incomeTotal,
            'expense_total' => $expenseTotal,
            'net_movement' => $incomeTotal - $expenseTotal,
        ];
    }

    public function treasuryMovement(?string $fromUtc = null, ?string $toUtc = null): array
    {
        $accountId = (new AccountService())->accountIdByCode('1000');
        $activity = $this->accountActivity($accountId, $fromUtc, $toUtc);

        $moneyIn = 0;
        $moneyOut = 0;
        foreach ($activity['rows'] as $row) {
            $moneyIn += (int)$row['debit_amount'];
            $moneyOut += (int)$row['credit_amount'];
        }

        $activity['money_in'] = $moneyIn;
        $activity['money_out'] = $moneyOut;
        $activity['net_movement'] = $moneyIn - $moneyOut;

        return $activity;
    }

    public function accountActivity(int $accountId, ?string $fromUtc = null, ?string $toUtc = null): array
    {
        $account = (new AccountService())->accountById($accountId);
        $opening = $fromUtc ? $this->accountBalanceBefore($accountId, $fromUtc) : 0;
        [$dateWhere, $params] = $this->dateWhere('t', $fromUtc, $toUtc);
        $params['account_id'] = $accountId;

        $stmt = Database::pdo()->prepare(
            'SELECT t.id AS transaction_id, t.transaction_uuid, t.transaction_type, t.description, t.occurred_at, t.status,
                    app.name AS app_name,
                    le.id AS ledger_entry_id, le.direction, le.amount, le.memo, le.player_rsn,
                    admin.display_name AS admin_display_name, admin.rsn AS admin_rsn,
                    CASE WHEN le.direction = "debit" THEN le.amount ELSE 0 END AS debit_amount,
                    CASE WHEN le.direction = "credit" THEN le.amount ELSE 0 END AS credit_amount,
                    ' . $this->normalBalanceExpression('acc', 'le') . ' AS movement
             FROM treasury_ledger_entries le
             JOIN treasury_transactions t ON t.id = le.transaction_id
             JOIN treasury_accounts acc ON acc.id = le.account_id
             LEFT JOIN treasury_apps app ON app.id = t.app_id
             LEFT JOIN treasury_admins admin ON admin.id = le.admin_id
             WHERE le.account_id = :account_id
               AND ' . $this->reportableTransactionWhere('t') . $dateWhere . '
             ORDER BY t.occurred_at DESC, t.id DESC, le.id DESC'
        );
        $stmt->execute($params);

        $rows = [];
        $periodMovement = 0;
        $debits = 0;
        $credits = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['amount'] = (int)$row['amount'];
            $row['debit_amount'] = (int)$row['debit_amount'];
            $row['credit_amount'] = (int)$row['credit_amount'];
            $row['movement'] = (int)$row['movement'];
            $periodMovement += (int)$row['movement'];
            $debits += (int)$row['debit_amount'];
            $credits += (int)$row['credit_amount'];
            $rows[] = $row;
        }

        return [
            'account' => $account,
            'opening_balance' => $opening,
            'period_movement' => $periodMovement,
            'closing_balance' => $opening + $periodMovement,
            'debits' => $debits,
            'credits' => $credits,
            'rows' => $rows,
        ];
    }

    public function adminHeldFunds(?string $fromUtc = null, ?string $toUtc = null): array
    {
        $pdo = Database::pdo();
        $admins = $pdo->query(
            'SELECT id, rsn, display_name, is_active
             FROM treasury_admins
             ORDER BY is_active DESC, COALESCE(NULLIF(display_name, ""), rsn) ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $heldBalances = $this->adminHeldCurrentBalances();
        $received = $this->paymentsReceivedByAdmin($fromUtc, $toUtc);
        $reconciled = $this->reconciliationsByAdmin($fromUtc, $toUtc);

        $rows = [];
        $totals = [
            'current_held' => 0,
            'received_amount' => 0,
            'received_count' => 0,
            'reconciled_amount' => 0,
            'reconciled_count' => 0,
        ];

        foreach ($admins as $admin) {
            $adminId = (int)$admin['id'];
            $row = [
                'admin_id' => $adminId,
                'display_name' => $admin['display_name'],
                'rsn' => $admin['rsn'],
                'is_active' => (int)$admin['is_active'],
                'current_held' => $heldBalances[$adminId] ?? 0,
                'received_amount' => $received[$adminId]['amount'] ?? 0,
                'received_count' => $received[$adminId]['count'] ?? 0,
                'reconciled_amount' => $reconciled[$adminId]['amount'] ?? 0,
                'reconciled_count' => $reconciled[$adminId]['count'] ?? 0,
                'last_reconciled_at' => $reconciled[$adminId]['last_reconciled_at'] ?? null,
            ];
            $rows[] = $row;

            foreach ($totals as $key => $_) {
                $totals[$key] += (int)$row[$key];
            }
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    public function reportAccounts(): array
    {
        $stmt = Database::pdo()->query(
            'SELECT id, code, name, account_type, is_system, is_active
             FROM treasury_accounts
             WHERE account_type IN ("asset", "income", "expense", "liability", "equity")
             ORDER BY FIELD(account_type, "asset", "liability", "equity", "income", "expense"), code ASC'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function accountBalanceBefore(int $accountId, string $beforeUtc): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COALESCE(SUM(' . $this->normalBalanceExpression('acc', 'le') . '), 0) AS balance
             FROM treasury_ledger_entries le
             JOIN treasury_transactions t ON t.id = le.transaction_id
             JOIN treasury_accounts acc ON acc.id = le.account_id
             WHERE le.account_id = :account_id
               AND t.occurred_at < :before_utc
               AND ' . $this->reportableTransactionWhere('t')
        );
        $stmt->execute(['account_id' => $accountId, 'before_utc' => $beforeUtc]);
        return (int)$stmt->fetchColumn();
    }

    private function adminHeldCurrentBalances(): array
    {
        $stmt = Database::pdo()->query(
            'SELECT acc.admin_id,
                    COALESCE(SUM(CASE
                        WHEN t.id IS NULL THEN 0
                        WHEN le.direction = "debit" THEN le.amount
                        ELSE -le.amount
                    END), 0) AS balance
             FROM treasury_accounts acc
             LEFT JOIN treasury_ledger_entries le ON le.account_id = acc.id
             LEFT JOIN treasury_transactions t ON t.id = le.transaction_id
                  AND t.status = "posted"
                  AND t.transaction_type <> "reversal"
             WHERE acc.account_type = "asset"
               AND acc.admin_id IS NOT NULL
               AND acc.code LIKE "1100:%"
             GROUP BY acc.admin_id'
        );

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int)$row['admin_id']] = (int)$row['balance'];
        }
        return $out;
    }

    private function paymentsReceivedByAdmin(?string $fromUtc, ?string $toUtc): array
    {
        $where = 'received_by_admin_id IS NOT NULL AND status IN ("received_by_admin", "reconciled_to_treasury")';
        $params = [];
        if ($fromUtc) {
            $where .= ' AND received_at >= :from_utc';
            $params['from_utc'] = $fromUtc;
        }
        if ($toUtc) {
            $where .= ' AND received_at < :to_utc';
            $params['to_utc'] = $toUtc;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT received_by_admin_id AS admin_id, COUNT(*) AS count, COALESCE(SUM(amount), 0) AS amount
             FROM treasury_payment_requests
             WHERE ' . $where . '
             GROUP BY received_by_admin_id'
        );
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int)$row['admin_id']] = ['count' => (int)$row['count'], 'amount' => (int)$row['amount']];
        }
        return $out;
    }

    private function reconciliationsByAdmin(?string $fromUtc, ?string $toUtc): array
    {
        $where = 'status = "completed"';
        $params = [];
        if ($fromUtc) {
            $where .= ' AND completed_at >= :from_utc';
            $params['from_utc'] = $fromUtc;
        }
        if ($toUtc) {
            $where .= ' AND completed_at < :to_utc';
            $params['to_utc'] = $toUtc;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT from_admin_id AS admin_id, COUNT(*) AS count, COALESCE(SUM(amount), 0) AS amount, MAX(completed_at) AS last_reconciled_at
             FROM treasury_reconciliations
             WHERE ' . $where . '
             GROUP BY from_admin_id'
        );
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int)$row['admin_id']] = [
                'count' => (int)$row['count'],
                'amount' => (int)$row['amount'],
                'last_reconciled_at' => $row['last_reconciled_at'],
            ];
        }
        return $out;
    }

    private function dateWhere(string $transactionAlias, ?string $fromUtc, ?string $toUtc): array
    {
        $where = '';
        $params = [];
        if ($fromUtc) {
            $where .= ' AND ' . $transactionAlias . '.occurred_at >= :from_utc';
            $params['from_utc'] = $fromUtc;
        }
        if ($toUtc) {
            $where .= ' AND ' . $transactionAlias . '.occurred_at < :to_utc';
            $params['to_utc'] = $toUtc;
        }
        return [$where, $params];
    }

    private function reportableTransactionWhere(string $transactionAlias): string
    {
        return $transactionAlias . '.status = "posted" AND ' . $transactionAlias . '.transaction_type <> "reversal"';
    }

    private function normalBalanceExpression(string $accountAlias, string $entryAlias): string
    {
        return 'CASE
            WHEN ' . $accountAlias . '.normal_balance = "debit" THEN
                CASE WHEN ' . $entryAlias . '.direction = "debit" THEN ' . $entryAlias . '.amount ELSE -' . $entryAlias . '.amount END
            ELSE
                CASE WHEN ' . $entryAlias . '.direction = "credit" THEN ' . $entryAlias . '.amount ELSE -' . $entryAlias . '.amount END
        END';
    }
}
