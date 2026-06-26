<?php

declare(strict_types=1);

namespace Treasury\Services;

use Treasury\Support\GP;

final class ManualLedgerService
{
    public function openingBalance(array $data): array
    {
        $adminId = (int)($data['admin_id'] ?? 0);
        $amount = GP::parse($data['amount'] ?? null);
        if ($adminId <= 0) {
            throw new \InvalidArgumentException('Acting admin is required.');
        }

        $accounts = new AccountService();
        return (new LedgerService())->postTransaction([
            'app_id' => null,
            'source_type' => 'manual_opening_balance',
            'source_id' => 'opening-' . time() . '-' . bin2hex(random_bytes(3)),
            'transaction_type' => 'adjustment',
            'description' => trim((string)($data['description'] ?? 'Opening official treasury balance')) ?: 'Opening official treasury balance',
            'notes' => $data['notes'] ?? null,
            'occurred_at' => $data['occurred_at'] ?? 'now',
            'posted_by_admin_id' => $adminId,
            'metadata' => ['manual_entry_type' => 'opening_balance'],
        ], [
            [
                'account_id' => $accounts->accountIdByCode('1000'),
                'direction' => 'debit',
                'amount' => $amount,
                'admin_id' => $adminId,
                'memo' => 'Official treasury opening balance',
            ],
            [
                'account_id' => $accounts->accountIdByCode('3000'),
                'direction' => 'credit',
                'amount' => $amount,
                'admin_id' => $adminId,
                'memo' => 'Opening balance equity',
            ],
        ]);
    }

    public function expenseFromTreasury(array $data): array
    {
        $adminId = (int)($data['admin_id'] ?? 0);
        $amount = GP::parse($data['amount'] ?? null);
        if ($adminId <= 0) {
            throw new \InvalidArgumentException('Acting admin is required.');
        }

        $accounts = new AccountService();
        return (new LedgerService())->postTransaction([
            'app_id' => null,
            'source_type' => 'manual_expense',
            'source_id' => 'expense-' . time() . '-' . bin2hex(random_bytes(3)),
            'transaction_type' => 'expense',
            'description' => trim((string)($data['description'] ?? 'Manual treasury expense')) ?: 'Manual treasury expense',
            'notes' => $data['notes'] ?? null,
            'occurred_at' => $data['occurred_at'] ?? 'now',
            'posted_by_admin_id' => $adminId,
            'metadata' => ['manual_entry_type' => 'expense_from_treasury'],
        ], [
            [
                'account_id' => $accounts->accountIdByCode('6000'),
                'direction' => 'debit',
                'amount' => $amount,
                'admin_id' => $adminId,
                'player_rsn' => trim((string)($data['player_rsn'] ?? '')) ?: null,
                'memo' => 'Manual expense',
            ],
            [
                'account_id' => $accounts->accountIdByCode('1000'),
                'direction' => 'credit',
                'amount' => $amount,
                'admin_id' => $adminId,
                'player_rsn' => trim((string)($data['player_rsn'] ?? '')) ?: null,
                'memo' => 'Paid from official treasury',
            ],
        ]);
    }

    public function adminPaidExpense(array $data): array
    {
        $postedByAdminId = (int)($data['posted_by_admin_id'] ?? 0);
        $paidByAdminId = (int)($data['paid_by_admin_id'] ?? 0);
        $amount = GP::parse($data['amount'] ?? null);
        if ($postedByAdminId <= 0 || $paidByAdminId <= 0) {
            throw new \InvalidArgumentException('Acting admin and paid-by admin are required.');
        }

        $accounts = new AccountService();
        return (new LedgerService())->postTransaction([
            'app_id' => null,
            'source_type' => 'manual_admin_paid_expense',
            'source_id' => 'admin-expense-' . time() . '-' . bin2hex(random_bytes(3)),
            'transaction_type' => 'expense',
            'description' => trim((string)($data['description'] ?? 'Admin-paid expense')) ?: 'Admin-paid expense',
            'notes' => $data['notes'] ?? null,
            'occurred_at' => $data['occurred_at'] ?? 'now',
            'posted_by_admin_id' => $postedByAdminId,
            'metadata' => ['manual_entry_type' => 'admin_paid_expense', 'paid_by_admin_id' => $paidByAdminId],
        ], [
            [
                'account_id' => $accounts->accountIdByCode('6000'),
                'direction' => 'debit',
                'amount' => $amount,
                'admin_id' => $paidByAdminId,
                'player_rsn' => trim((string)($data['player_rsn'] ?? '')) ?: null,
                'memo' => 'Manual admin-paid expense',
            ],
            [
                'account_id' => $accounts->accountIdByCode('2000'),
                'direction' => 'credit',
                'amount' => $amount,
                'admin_id' => $paidByAdminId,
                'player_rsn' => trim((string)($data['player_rsn'] ?? '')) ?: null,
                'memo' => 'Admin reimbursement owed',
            ],
        ]);
    }

    public function reimburseAdmin(array $data): array
    {
        $postedByAdminId = (int)($data['posted_by_admin_id'] ?? 0);
        $reimbursedAdminId = (int)($data['reimbursed_admin_id'] ?? 0);
        $amount = GP::parse($data['amount'] ?? null);
        if ($postedByAdminId <= 0 || $reimbursedAdminId <= 0) {
            throw new \InvalidArgumentException('Acting admin and reimbursed admin are required.');
        }

        $accounts = new AccountService();
        return (new LedgerService())->postTransaction([
            'app_id' => null,
            'source_type' => 'manual_admin_reimbursement',
            'source_id' => 'reimbursement-' . time() . '-' . bin2hex(random_bytes(3)),
            'transaction_type' => 'admin_reimbursement',
            'description' => trim((string)($data['description'] ?? 'Manual admin reimbursement')) ?: 'Manual admin reimbursement',
            'notes' => $data['notes'] ?? null,
            'occurred_at' => $data['occurred_at'] ?? 'now',
            'posted_by_admin_id' => $postedByAdminId,
            'metadata' => ['manual_entry_type' => 'admin_reimbursement', 'reimbursed_admin_id' => $reimbursedAdminId],
        ], [
            [
                'account_id' => $accounts->accountIdByCode('2000'),
                'direction' => 'debit',
                'amount' => $amount,
                'admin_id' => $reimbursedAdminId,
                'memo' => 'Clear reimbursement payable',
            ],
            [
                'account_id' => $accounts->accountIdByCode('1000'),
                'direction' => 'credit',
                'amount' => $amount,
                'admin_id' => $reimbursedAdminId,
                'memo' => 'Reimbursed from official treasury',
            ],
        ]);
    }
}
