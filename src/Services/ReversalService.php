<?php

declare(strict_types=1);

namespace Treasury\Services;

use PDO;
use Treasury\Database;

final class ReversalService
{
    public function reverse(string $transactionUuid, int $adminId, ?string $reason = null, string $occurredAt = 'now'): array
    {
        $transactionUuid = trim($transactionUuid);
        $reason = trim((string)$reason);

        if ($transactionUuid === '') {
            throw new \InvalidArgumentException('Transaction UUID is required.');
        }
        if ($adminId <= 0) {
            throw new \InvalidArgumentException('Acting admin is required.');
        }
        if ($reason === '') {
            throw new \InvalidArgumentException('A reversal reason is required.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $original = $this->lockTransaction($transactionUuid);

            if ($original['status'] !== 'posted') {
                throw new \RuntimeException('Only posted transactions can be reversed.', 409);
            }
            if ($original['transaction_type'] === 'reversal') {
                throw new \RuntimeException('Reversal transactions cannot be reversed. Reverse the original transaction chain manually with a correcting transaction instead.', 409);
            }

            $existing = $pdo->prepare(
                'SELECT transaction_uuid
                 FROM treasury_transactions
                 WHERE related_transaction_id = :transaction_id
                   AND transaction_type = "reversal"
                   AND status = "posted"
                 LIMIT 1
                 FOR UPDATE'
            );
            $existing->execute(['transaction_id' => (int)$original['id']]);
            $existingUuid = $existing->fetchColumn();
            if ($existingUuid) {
                throw new \RuntimeException('This transaction has already been reversed by ' . $existingUuid . '.', 409);
            }

            $this->prepareLinkedEntityStateChanges($original);

            $lines = $this->ledgerLines((int)$original['id']);
            if (count($lines) < 2) {
                throw new \RuntimeException('The original transaction has no balanced ledger lines to reverse.', 409);
            }

            $reversalLines = [];
            foreach ($lines as $line) {
                $memo = trim((string)($line['memo'] ?? ''));
                $reversalLines[] = [
                    'account_id' => (int)$line['account_id'],
                    'direction' => $line['direction'] === 'debit' ? 'credit' : 'debit',
                    'amount' => (int)$line['amount'],
                    'admin_id' => $line['admin_id'] === null ? null : (int)$line['admin_id'],
                    'player_rsn' => $line['player_rsn'] ?: null,
                    'memo' => $memo === '' ? 'Reversal' : substr('Reversal of: ' . $memo, 0, 255),
                ];
            }

            $reversal = (new LedgerService())->postTransaction([
                'app_id' => $original['app_id'] === null ? null : (int)$original['app_id'],
                'source_type' => 'reversal',
                'source_id' => $original['transaction_uuid'],
                'transaction_type' => 'reversal',
                'description' => substr('Reversal: ' . $original['description'], 0, 255),
                'notes' => $reason,
                'occurred_at' => $occurredAt ?: 'now',
                'posted_by_admin_id' => $adminId,
                'related_transaction_id' => (int)$original['id'],
                'metadata' => [
                    'reverses_transaction_uuid' => $original['transaction_uuid'],
                    'reversal_reason' => $reason,
                ],
            ], $reversalLines);

            $updateOriginal = $pdo->prepare('UPDATE treasury_transactions SET status = "reversed" WHERE id = :id');
            $updateOriginal->execute(['id' => (int)$original['id']]);

            $this->applyLinkedEntityStateChanges($original, (int)$reversal['id']);

            $pdo->commit();

            $result = [
                'original_transaction_uuid' => $original['transaction_uuid'],
                'reversal_transaction_uuid' => $reversal['transaction_uuid'],
                'amount' => (int)$original['amount'],
                'reason' => $reason,
            ];

            AuditService::log('transaction.reversed', 'treasury_transaction', $original['transaction_uuid'], $original, $result, null, $adminId);
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function lockTransaction(string $transactionUuid): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM treasury_transactions WHERE transaction_uuid = :uuid LIMIT 1 FOR UPDATE');
        $stmt->execute(['uuid' => $transactionUuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \RuntimeException('Transaction not found.', 404);
        }
        return $row;
    }

    private function ledgerLines(int $transactionId): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM treasury_ledger_entries WHERE transaction_id = :id ORDER BY id ASC');
        $stmt->execute(['id' => $transactionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function prepareLinkedEntityStateChanges(array $original): void
    {
        $this->guardPaymentReceipt($original);
        $this->guardPayoutPayment($original);
    }

    private function applyLinkedEntityStateChanges(array $original, int $reversalTransactionId): void
    {
        $transactionId = (int)$original['id'];
        $pdo = Database::pdo();

        // If a received payment is reversed before reconciliation, put the request back to pending.
        $paymentRows = $this->paymentRowsByReceivedTransaction($transactionId);
        foreach ($paymentRows as $payment) {
            if ($payment['status'] === 'received_by_admin') {
                $stmt = $pdo->prepare(
                    'UPDATE treasury_payment_requests
                     SET status = "pending",
                         received_by_admin_id = NULL,
                         received_transaction_id = NULL,
                         received_at = NULL
                     WHERE id = :id'
                );
                $stmt->execute(['id' => (int)$payment['id']]);
            }
        }

        // If a reconciliation is reversed, return the linked payments to received-by-admin.
        $reconRows = $this->reconciliationsByTransaction($transactionId);
        foreach ($reconRows as $recon) {
            if ($recon['status'] === 'completed') {
                $stmt = $pdo->prepare('UPDATE treasury_reconciliations SET status = "cancelled" WHERE id = :id');
                $stmt->execute(['id' => (int)$recon['id']]);

                $payments = $pdo->prepare(
                    'UPDATE treasury_payment_requests
                     SET status = "received_by_admin",
                         reconciliation_transaction_id = NULL,
                         reconciled_at = NULL
                     WHERE reconciliation_transaction_id = :transaction_id
                       AND status = "reconciled_to_treasury"'
                );
                $payments->execute(['transaction_id' => $transactionId]);
            }
        }

        // If a payout payment is reversed before reimbursement, return the payout request to pending.
        $payoutPaymentRows = $this->payoutRowsByPaidTransaction($transactionId);
        foreach ($payoutPaymentRows as $payout) {
            if (in_array($payout['status'], ['paid_from_treasury', 'paid_by_admin'], true)) {
                $stmt = $pdo->prepare(
                    'UPDATE treasury_payout_requests
                     SET status = "pending",
                         paid_by_admin_id = NULL,
                         paid_transaction_id = NULL,
                         paid_at = NULL
                     WHERE id = :id'
                );
                $stmt->execute(['id' => (int)$payout['id']]);
            }
        }

        // If a reimbursement is reversed, return the payout to the admin-paid waiting-for-reimbursement state.
        $payoutReimbursementRows = $this->payoutRowsByReimbursementTransaction($transactionId);
        foreach ($payoutReimbursementRows as $payout) {
            if ($payout['status'] === 'reimbursed') {
                $stmt = $pdo->prepare(
                    'UPDATE treasury_payout_requests
                     SET status = "paid_by_admin",
                         reimbursement_transaction_id = NULL,
                         reimbursed_at = NULL
                     WHERE id = :id'
                );
                $stmt->execute(['id' => (int)$payout['id']]);
            }
        }
    }

    private function guardPaymentReceipt(array $original): void
    {
        foreach ($this->paymentRowsByReceivedTransaction((int)$original['id']) as $payment) {
            if ($payment['status'] === 'reconciled_to_treasury') {
                throw new \RuntimeException('This payment has already been reconciled into the official treasury. Reverse the reconciliation first, then reverse the received payment if needed.', 409);
            }
            if ($payment['status'] !== 'received_by_admin') {
                throw new \RuntimeException('This payment request is not in a reversible received state.', 409);
            }
        }
    }

    private function guardPayoutPayment(array $original): void
    {
        foreach ($this->payoutRowsByPaidTransaction((int)$original['id']) as $payout) {
            if ($payout['status'] === 'reimbursed') {
                throw new \RuntimeException('This admin-paid payout has already been reimbursed. Reverse the reimbursement first, then reverse the original payout if needed.', 409);
            }
            if (!in_array($payout['status'], ['paid_from_treasury', 'paid_by_admin'], true)) {
                throw new \RuntimeException('This payout request is not in a reversible paid state.', 409);
            }
        }
    }

    private function paymentRowsByReceivedTransaction(int $transactionId): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM treasury_payment_requests WHERE received_transaction_id = :transaction_id FOR UPDATE');
        $stmt->execute(['transaction_id' => $transactionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function reconciliationsByTransaction(int $transactionId): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM treasury_reconciliations WHERE transaction_id = :transaction_id FOR UPDATE');
        $stmt->execute(['transaction_id' => $transactionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function payoutRowsByPaidTransaction(int $transactionId): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM treasury_payout_requests WHERE paid_transaction_id = :transaction_id FOR UPDATE');
        $stmt->execute(['transaction_id' => $transactionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function payoutRowsByReimbursementTransaction(int $transactionId): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM treasury_payout_requests WHERE reimbursement_transaction_id = :transaction_id FOR UPDATE');
        $stmt->execute(['transaction_id' => $transactionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
