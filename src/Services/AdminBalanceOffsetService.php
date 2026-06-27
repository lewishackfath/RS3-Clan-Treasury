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
                    COALESCE(held_bal.balance, 0) AS owed_to_treasury,
                    COALESCE(payable_bal.balance, 0) AS owed_to_admin,
                    COALESCE(payments.amount, 0) AS open_payment_amount,
                    COALESCE(payments.count, 0) AS open_payment_count,
                    COALESCE(payouts.amount, 0) AS open_payout_amount,
                    COALESCE(payouts.count, 0) AS open_payout_count
             FROM treasury_admins admin
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
                 SELECT p.received_by_admin_id AS admin_id,
                        COUNT(*) AS count,
                        COALESCE(SUM(GREATEST(p.amount - COALESCE(s.settled_amount, 0), 0)), 0) AS amount
                 FROM treasury_payment_requests p
                 LEFT JOIN (
                     SELECT request_id, SUM(amount) AS settled_amount
                     FROM treasury_request_settlements
                     WHERE request_type = "payment"
                     GROUP BY request_id
                 ) s ON s.request_id = p.id
                 WHERE p.status = "received_by_admin" AND p.reconciliation_transaction_id IS NULL
                 GROUP BY p.received_by_admin_id
             ) payments ON payments.admin_id = admin.id
             LEFT JOIN (
                 SELECT p.paid_by_admin_id AS admin_id,
                        COUNT(*) AS count,
                        COALESCE(SUM(GREATEST(p.amount - COALESCE(s.settled_amount, 0), 0)), 0) AS amount
                 FROM treasury_payout_requests p
                 LEFT JOIN (
                     SELECT request_id, SUM(amount) AS settled_amount
                     FROM treasury_request_settlements
                     WHERE request_type = "payout"
                     GROUP BY request_id
                 ) s ON s.request_id = p.id
                 WHERE p.status = "paid_by_admin" AND p.reimbursement_transaction_id IS NULL
                 GROUP BY p.paid_by_admin_id
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
            $openPaymentAmount = max(0, (int)$row['open_payment_amount']);
            $openPayoutAmount = max(0, (int)$row['open_payout_amount']);

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
                'can_auto_offset' => $offsetAmount > 0,
                'blocked_reason' => $offsetAmount > 0 ? '' : 'No mutual balance exists for this admin.',
            ];
        }

        return $rows;
    }

    public function offsetEqualAdminBalance(int $adminId, int $postedByAdminId, ?string $notes = null, string $occurredAt = 'now'): array
    {
        return $this->offsetAdminBalance($adminId, $postedByAdminId, null, $notes, $occurredAt, false);
    }

    public function offsetAdminBalance(int $adminId, ?int $postedByAdminId = null, ?int $requestedAmount = null, ?string $notes = null, string $occurredAt = 'now', bool $automatic = false): array
    {
        if ($adminId <= 0) {
            throw new \InvalidArgumentException('Admin is required.');
        }
        if ($postedByAdminId !== null && $postedByAdminId <= 0) {
            $postedByAdminId = null;
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $candidate = $this->candidateForUpdate($adminId);
            if (!$candidate) {
                throw new \RuntimeException('No mutual admin balance is available to offset.', 409);
            }

            $available = (int)$candidate['offset_amount'];
            if ($available <= 0) {
                throw new \RuntimeException('This admin does not have both money owed to treasury and money owed by treasury.', 409);
            }

            $amount = $requestedAmount !== null && $requestedAmount > 0 ? $requestedAmount : $available;
            if ($amount > $available) {
                throw new \RuntimeException('Offset amount cannot be greater than the mutual admin balance available.', 409);
            }

            $accounts = new AccountService();
            $heldAccountId = $accounts->ensureAdminHeldAccount($adminId);
            $payableAccountId = $accounts->ensureAdminPayableAccount($adminId);
            $occurredUtc = $this->normaliseDateTime($occurredAt);

            $transaction = (new LedgerService())->postTransaction([
                'app_id' => null,
                'source_type' => $automatic ? 'automatic_admin_balance_offset' : 'admin_balance_offset',
                'source_id' => 'offset-' . $adminId . '-' . time() . '-' . bin2hex(random_bytes(3)),
                'transaction_type' => 'adjustment',
                'description' => ($automatic ? 'Automatic offset' : 'Offset') . ' mutual admin balance - ' . ($candidate['display_name'] ?: $candidate['rsn']),
                'notes' => $notes,
                'occurred_at' => $occurredAt,
                'posted_by_admin_id' => $postedByAdminId,
                'metadata' => [
                    'manual_entry_type' => $automatic ? 'automatic_admin_balance_offset' : 'admin_balance_offset',
                    'offset_admin_id' => $adminId,
                    'offset_amount' => $amount,
                    'available_offset_before' => $available,
                    'automatic' => $automatic,
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

            $paymentAllocations = $this->allocateRequestSettlements('payment', $adminId, $amount, (int)$transaction['id'], $occurredUtc);
            $payoutAllocations = $this->allocateRequestSettlements('payout', $adminId, $amount, (int)$transaction['id'], $occurredUtc);

            $pdo->commit();

            $result = [
                'admin_id' => $adminId,
                'amount' => $amount,
                'transaction_uuid' => $transaction['transaction_uuid'],
                'payment_count' => count($paymentAllocations),
                'payout_count' => count($payoutAllocations),
                'payment_allocations' => $paymentAllocations,
                'payout_allocations' => $payoutAllocations,
                'automatic' => $automatic,
            ];
            AuditService::log($automatic ? 'admin_balance.auto_offset' : 'admin_balance.offset', 'treasury_admin', (string)$adminId, $candidate, $result, null, $postedByAdminId);
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function autoOffsetForAdmin(int $adminId, ?int $postedByAdminId = null, string $occurredAt = 'now'): ?array
    {
        if ($adminId <= 0) {
            return null;
        }

        try {
            $candidate = $this->candidateForUpdate($adminId);
            if (!$candidate || (int)$candidate['offset_amount'] <= 0) {
                return null;
            }
            return $this->offsetAdminBalance(
                $adminId,
                $postedByAdminId,
                null,
                'Automatically offset matching admin owed/owing balances.',
                $occurredAt,
                true
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private function candidateForUpdate(int $adminId): ?array
    {
        foreach ($this->candidates($adminId) as $candidate) {
            return $candidate;
        }
        return null;
    }

    private function allocateRequestSettlements(string $requestType, int $adminId, int $offsetAmount, int $transactionId, string $settledAt): array
    {
        if ($offsetAmount <= 0) {
            return [];
        }

        $rows = $requestType === 'payment'
            ? $this->eligiblePaymentsForUpdate($adminId)
            : $this->eligiblePayoutsForUpdate($adminId);

        $remainingOffset = $offsetAmount;
        $allocations = [];
        foreach ($rows as $row) {
            if ($remainingOffset <= 0) {
                break;
            }

            $requestId = (int)$row['id'];
            $amount = (int)$row['amount'];
            $settledAlready = $this->settledAmount($requestType, $requestId);
            $remainingRequest = max(0, $amount - $settledAlready);
            if ($remainingRequest <= 0) {
                continue;
            }

            $allocation = min($remainingRequest, $remainingOffset);
            $this->insertSettlement($requestType, $requestId, $transactionId, $allocation);
            $remainingOffset -= $allocation;

            $fullySettled = ($settledAlready + $allocation) >= $amount;
            if ($fullySettled) {
                if ($requestType === 'payment') {
                    $stmt = Database::pdo()->prepare(
                        'UPDATE treasury_payment_requests
                         SET status = "reconciled_to_treasury",
                             reconciliation_transaction_id = :transaction_id,
                             reconciled_at = :settled_at
                         WHERE id = :id'
                    );
                } else {
                    $stmt = Database::pdo()->prepare(
                        'UPDATE treasury_payout_requests
                         SET status = "reimbursed",
                             reimbursement_transaction_id = :transaction_id,
                             reimbursed_at = :settled_at
                         WHERE id = :id'
                    );
                }
                $stmt->execute([
                    'transaction_id' => $transactionId,
                    'settled_at' => $settledAt,
                    'id' => $requestId,
                ]);
            }

            $allocations[] = [
                'request_type' => $requestType,
                'request_id' => $requestId,
                'request_uuid' => $row['request_uuid'],
                'amount' => $allocation,
                'fully_settled' => $fullySettled,
            ];
        }

        return $allocations;
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

    private function settledAmount(string $requestType, int $requestId): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COALESCE(SUM(amount), 0)
             FROM treasury_request_settlements
             WHERE request_type = :request_type AND request_id = :request_id'
        );
        $stmt->execute(['request_type' => $requestType, 'request_id' => $requestId]);
        return (int)$stmt->fetchColumn();
    }

    private function insertSettlement(string $requestType, int $requestId, int $transactionId, int $amount): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO treasury_request_settlements
             (transaction_id, request_type, request_id, settlement_type, amount)
             VALUES
             (:transaction_id, :request_type, :request_id, "admin_balance_offset", :amount)'
        );
        $stmt->execute([
            'transaction_id' => $transactionId,
            'request_type' => $requestType,
            'request_id' => $requestId,
            'amount' => $amount,
        ]);
    }

    private function normaliseDateTime(string $value): string
    {
        $dt = $value === 'now' ? new \DateTimeImmutable('now') : new \DateTimeImmutable($value);
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
