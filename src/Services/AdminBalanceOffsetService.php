<?php

declare(strict_types=1);

namespace Treasury\Services;

use PDO;
use Treasury\Database;

final class AdminBalanceOffsetService
{
    public function candidates(?int $adminId = null): array
    {
        $pdo = Database::pdo();
        $params = [];
        $where = '1=1';
        if ($adminId !== null && $adminId > 0) {
            $where .= ' AND admin.id = :admin_id';
            $params['admin_id'] = $adminId;
        }

        $stmt = $pdo->prepare(
            'SELECT admin.id AS admin_id,
                    admin.rsn,
                    admin.display_name,
                    held.id AS held_account_id,
                    payable.id AS payable_account_id,
                    COALESCE(held_bal.balance, 0) AS owed_to_treasury,
                    COALESCE(payable_bal.balance, 0) AS owed_to_admin,
                    COALESCE(payments.amount, 0) AS open_payment_amount,
                    COALESCE(payments.count, 0) AS open_payment_count,
                    COALESCE(payouts.amount, 0) AS open_payout_amount,
                    COALESCE(payouts.count, 0) AS open_payout_count
             FROM treasury_admins admin
             LEFT JOIN treasury_accounts held ON held.admin_id = admin.id AND held.account_type = "asset" AND held.code LIKE "1100:%"
             LEFT JOIN treasury_accounts payable ON payable.admin_id = admin.id AND payable.account_type = "liability" AND payable.code LIKE "2000:%"
             LEFT JOIN (
                 SELECT acc.admin_id,
                        SUM(CASE WHEN le.direction = "debit" THEN le.amount ELSE -le.amount END) AS balance
                 FROM treasury_accounts acc
                 JOIN treasury_ledger_entries le ON le.account_id = acc.id
                 JOIN treasury_transactions t ON t.id = le.transaction_id AND t.status = "posted"
                 WHERE acc.account_type = "asset" AND acc.code LIKE "1100:%"
                 GROUP BY acc.admin_id
             ) held_bal ON held_bal.admin_id = admin.id
             LEFT JOIN (
                 SELECT acc.admin_id,
                        SUM(CASE WHEN le.direction = "credit" THEN le.amount ELSE -le.amount END) AS balance
                 FROM treasury_accounts acc
                 JOIN treasury_ledger_entries le ON le.account_id = acc.id
                 JOIN treasury_transactions t ON t.id = le.transaction_id AND t.status = "posted"
                 WHERE acc.account_type = "liability" AND acc.code LIKE "2000:%"
                 GROUP BY acc.admin_id
             ) payable_bal ON payable_bal.admin_id = admin.id
             LEFT JOIN (
                 SELECT received_by_admin_id AS admin_id, COUNT(*) AS count, COALESCE(SUM(amount), 0) AS amount
                 FROM treasury_payment_requests
                 WHERE status = "received_by_admin" AND reconciliation_transaction_id IS NULL
                 GROUP BY received_by_admin_id
             ) payments ON payments.admin_id = admin.id
             LEFT JOIN (
                 SELECT paid_by_admin_id AS admin_id, COUNT(*) AS count, COALESCE(SUM(amount), 0) AS amount
                 FROM treasury_payout_requests
                 WHERE status = "paid_by_admin" AND reimbursement_transaction_id IS NULL
                 GROUP BY paid_by_admin_id
             ) payouts ON payouts.admin_id = admin.id
             WHERE ' . $where . '
             ORDER BY admin.display_name ASC, admin.rsn ASC'
        );
        $stmt->execute($params);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $owedToTreasury = max(0, (int)$row['owed_to_treasury']);
            $owedToAdmin = max(0, (int)$row['owed_to_admin']);
            $offsetAmount = min($owedToTreasury, $owedToAdmin);
            $openPaymentAmount = (int)$row['open_payment_amount'];
            $openPayoutAmount = (int)$row['open_payout_amount'];
            $canAutoOffset = $offsetAmount > 0
                && $owedToTreasury === $owedToAdmin
                && $openPaymentAmount === $offsetAmount
                && $openPayoutAmount === $offsetAmount;

            if ($owedToTreasury <= 0 && $owedToAdmin <= 0 && $openPaymentAmount <= 0 && $openPayoutAmount <= 0) {
                continue;
            }

            $rows[] = [
                'admin_id' => (int)$row['admin_id'],
                'rsn' => $row['rsn'],
                'display_name' => $row['display_name'],
                'owed_to_treasury' => $owedToTreasury,
                'owed_to_admin' => $owedToAdmin,
                'offset_amount' => $offsetAmount,
                'open_payment_amount' => $openPaymentAmount,
                'open_payment_count' => (int)$row['open_payment_count'],
                'open_payout_amount' => $openPayoutAmount,
                'open_payout_count' => (int)$row['open_payout_count'],
                'can_auto_offset' => $canAutoOffset,
                'blocked_reason' => $this->blockedReason($owedToTreasury, $owedToAdmin, $openPaymentAmount, $openPayoutAmount),
            ];
        }

        return $rows;
    }

    public function offsetEqualAdminBalance(int $adminId, int $postedByAdminId, ?string $notes = null, string $occurredAt = 'now'): array
    {
        if ($adminId <= 0 || $postedByAdminId <= 0) {
            throw new \InvalidArgumentException('Admin and acting admin are required.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $candidate = $this->candidateForUpdate($adminId);
            if (!$candidate) {
                throw new \RuntimeException('No mutual admin balance is available to offset.', 409);
            }

            $amount = (int)$candidate['offset_amount'];
            if ($amount <= 0) {
                throw new \RuntimeException('This admin does not have both money owed to treasury and money owed by treasury.', 409);
            }
            if (!$candidate['can_auto_offset']) {
                throw new \RuntimeException($candidate['blocked_reason'] ?: 'This admin balance cannot be automatically offset yet.', 409);
            }

            $accounts = new AccountService();
            $heldAccountId = $accounts->ensureAdminHeldAccount($adminId);
            $payableAccountId = $accounts->ensureAdminPayableAccount($adminId);
            $occurredUtc = $this->normaliseDateTime($occurredAt);

            $paymentRows = $this->eligiblePaymentsForUpdate($adminId);
            $payoutRows = $this->eligiblePayoutsForUpdate($adminId);
            $paymentTotal = array_sum(array_map(fn(array $row): int => (int)$row['amount'], $paymentRows));
            $payoutTotal = array_sum(array_map(fn(array $row): int => (int)$row['amount'], $payoutRows));
            if ($paymentTotal !== $amount || $payoutTotal !== $amount) {
                throw new \RuntimeException('Open payment and payout request totals no longer match the offset amount. Refresh and try again.', 409);
            }

            $transaction = (new LedgerService())->postTransaction([
                'app_id' => null,
                'source_type' => 'admin_balance_offset',
                'source_id' => 'offset-' . $adminId . '-' . time() . '-' . bin2hex(random_bytes(3)),
                'transaction_type' => 'adjustment',
                'description' => 'Offset mutual admin balance - ' . ($candidate['display_name'] ?: $candidate['rsn']),
                'notes' => $notes,
                'occurred_at' => $occurredAt,
                'posted_by_admin_id' => $postedByAdminId,
                'metadata' => [
                    'manual_entry_type' => 'admin_balance_offset',
                    'offset_admin_id' => $adminId,
                    'offset_amount' => $amount,
                    'payment_request_ids' => array_map(fn(array $row): int => (int)$row['id'], $paymentRows),
                    'payout_request_ids' => array_map(fn(array $row): int => (int)$row['id'], $payoutRows),
                ],
            ], [
                [
                    'account_id' => $payableAccountId,
                    'direction' => 'debit',
                    'amount' => $amount,
                    'admin_id' => $adminId,
                    'memo' => 'Clear treasury amount owed to admin by offset',
                ],
                [
                    'account_id' => $heldAccountId,
                    'direction' => 'credit',
                    'amount' => $amount,
                    'admin_id' => $adminId,
                    'memo' => 'Clear admin amount owed to treasury by offset',
                ],
            ]);

            $paymentIds = array_map(fn(array $row): int => (int)$row['id'], $paymentRows);
            $payoutIds = array_map(fn(array $row): int => (int)$row['id'], $payoutRows);

            if ($paymentIds) {
                $placeholders = implode(',', array_fill(0, count($paymentIds), '?'));
                $stmt = $pdo->prepare(
                    'UPDATE treasury_payment_requests
                     SET status = "reconciled_to_treasury",
                         reconciliation_transaction_id = ?,
                         reconciled_at = ?
                     WHERE id IN (' . $placeholders . ')'
                );
                $stmt->execute([(int)$transaction['id'], $occurredUtc, ...$paymentIds]);
            }

            if ($payoutIds) {
                $placeholders = implode(',', array_fill(0, count($payoutIds), '?'));
                $stmt = $pdo->prepare(
                    'UPDATE treasury_payout_requests
                     SET status = "reimbursed",
                         reimbursement_transaction_id = ?,
                         reimbursed_at = ?
                     WHERE id IN (' . $placeholders . ')'
                );
                $stmt->execute([(int)$transaction['id'], $occurredUtc, ...$payoutIds]);
            }

            $pdo->commit();

            $result = [
                'admin_id' => $adminId,
                'amount' => $amount,
                'transaction_uuid' => $transaction['transaction_uuid'],
                'payment_count' => count($paymentRows),
                'payout_count' => count($payoutRows),
            ];
            AuditService::log('admin_balance.offset', 'treasury_admin', (string)$adminId, $candidate, $result, null, $postedByAdminId);
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function candidateForUpdate(int $adminId): ?array
    {
        foreach ($this->candidates($adminId) as $candidate) {
            return $candidate;
        }
        return null;
    }

    private function eligiblePaymentsForUpdate(int $adminId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, request_uuid, amount
             FROM treasury_payment_requests
             WHERE status = "received_by_admin"
               AND received_by_admin_id = :admin_id
               AND reconciliation_transaction_id IS NULL
             ORDER BY received_at ASC, id ASC
             FOR UPDATE'
        );
        $stmt->execute(['admin_id' => $adminId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function eligiblePayoutsForUpdate(int $adminId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, request_uuid, amount
             FROM treasury_payout_requests
             WHERE status = "paid_by_admin"
               AND paid_by_admin_id = :admin_id
               AND reimbursement_transaction_id IS NULL
             ORDER BY paid_at ASC, id ASC
             FOR UPDATE'
        );
        $stmt->execute(['admin_id' => $adminId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function blockedReason(int $owedToTreasury, int $owedToAdmin, int $openPaymentAmount, int $openPayoutAmount): string
    {
        if ($owedToTreasury <= 0 || $owedToAdmin <= 0) {
            return 'No mutual balance exists for this admin.';
        }
        if ($owedToTreasury !== $owedToAdmin) {
            return 'Automatic offset currently requires the amount owed to treasury and owed to the admin to match exactly.';
        }
        if ($openPaymentAmount !== $owedToTreasury || $openPayoutAmount !== $owedToAdmin) {
            return 'Open request totals do not exactly match the ledger balances. Use normal handover/reimbursement or correct the mismatched records first.';
        }
        return '';
    }

    private function normaliseDateTime(string $value): string
    {
        $dt = $value === 'now' ? new \DateTimeImmutable('now') : new \DateTimeImmutable($value);
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
