<?php

declare(strict_types=1);

namespace Treasury\Services;

use PDO;
use Treasury\Database;
use Treasury\Support\Uuid;

final class ReconciliationService
{
    public function complete(array $data): array
    {
        $fromAdminId = (int)($data['from_admin_id'] ?? 0);
        $completedByAdminId = (int)($data['completed_by_admin_id'] ?? $fromAdminId);
        $amount = (int)($data['amount'] ?? 0);

        if ($fromAdminId <= 0) {
            throw new \InvalidArgumentException('from_admin_id is required');
        }
        if ($completedByAdminId <= 0) {
            throw new \InvalidArgumentException('completed_by_admin_id is required');
        }
        if ($amount <= 0) {
            throw new \InvalidArgumentException('amount must be greater than zero');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $accounts = new AccountService();
            $heldAccountId = $accounts->ensureAdminHeldAccount($fromAdminId);
            $treasuryAccountId = $accounts->accountIdByCode('1000');

            $reconUuid = Uuid::v4();
            $insert = $pdo->prepare(
                'INSERT INTO treasury_reconciliations
                 (reconciliation_uuid, from_admin_id, amount, status, notes, created_by_admin_id, completed_by_admin_id, completed_at)
                 VALUES
                 (:uuid, :from_admin_id, :amount, "completed", :notes, :created_by_admin_id, :completed_by_admin_id, :completed_at)'
            );
            $completedAt = $this->normaliseDateTime($data['completed_at'] ?? 'now');
            $insert->execute([
                'uuid' => $reconUuid,
                'from_admin_id' => $fromAdminId,
                'amount' => $amount,
                'notes' => $data['notes'] ?? null,
                'created_by_admin_id' => $completedByAdminId,
                'completed_by_admin_id' => $completedByAdminId,
                'completed_at' => $completedAt,
            ]);
            $reconId = (int)$pdo->lastInsertId();

            $transaction = (new LedgerService())->postTransaction([
                'app_id' => null,
                'source_type' => 'reconciliation',
                'source_id' => $reconUuid,
                'transaction_type' => 'reconciliation',
                'description' => $data['description'] ?? 'Admin-held GP paid into official treasury',
                'notes' => $data['notes'] ?? null,
                'occurred_at' => $data['completed_at'] ?? 'now',
                'posted_by_admin_id' => $completedByAdminId,
                'metadata' => ['reconciliation_id' => $reconId],
            ], [
                [
                    'account_id' => $treasuryAccountId,
                    'direction' => 'debit',
                    'amount' => $amount,
                    'admin_id' => $fromAdminId,
                    'memo' => 'Received into official treasury',
                ],
                [
                    'account_id' => $heldAccountId,
                    'direction' => 'credit',
                    'amount' => $amount,
                    'admin_id' => $fromAdminId,
                    'memo' => 'Cleared amount owed by admin',
                ],
            ]);

            $update = $pdo->prepare('UPDATE treasury_reconciliations SET transaction_id = :transaction_id WHERE id = :id');
            $update->execute([
                'transaction_id' => $transaction['id'],
                'id' => $reconId,
            ]);

            $linkedPaymentRequests = [];
            if (!empty($data['payment_request_uuids']) && is_array($data['payment_request_uuids'])) {
                $linkedPaymentRequests = $this->linkPaymentRequests(
                    $data['payment_request_uuids'],
                    $fromAdminId,
                    $amount,
                    (int)$transaction['id'],
                    $completedAt
                );
            }

            $pdo->commit();

            $result = [
                'reconciliation_uuid' => $reconUuid,
                'status' => 'completed',
                'from_admin_id' => $fromAdminId,
                'amount' => $amount,
                'transaction_uuid' => $transaction['transaction_uuid'],
                'completed_at' => $completedAt,
                'linked_payment_requests' => $linkedPaymentRequests,
            ];

            AuditService::log('reconciliation.completed', 'treasury_reconciliation', $reconUuid, null, $result, null, $completedByAdminId);
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }


    private function linkPaymentRequests(array $requestUuids, int $fromAdminId, int $amount, int $transactionId, string $completedAt): array
    {
        $requestUuids = array_values(array_unique(array_filter(array_map('strval', $requestUuids))));
        if (!$requestUuids) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($requestUuids), '?'));
        $stmt = Database::pdo()->prepare(
            'SELECT id, request_uuid, amount
             FROM treasury_payment_requests
             WHERE request_uuid IN (' . $placeholders . ')
               AND status = "received_by_admin"
               AND received_by_admin_id = ?
               AND reconciliation_transaction_id IS NULL
             FOR UPDATE'
        );
        $params = [...$requestUuids, $fromAdminId];
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($rows) !== count($requestUuids)) {
            throw new \RuntimeException('One or more payment requests cannot be paid into treasury for this admin', 409);
        }

        $selectedTotal = array_sum(array_map(fn(array $row): int => (int)$row['amount'], $rows));
        if ($selectedTotal !== $amount) {
            throw new \RuntimeException('Selected payment request total does not match handover amount', 409);
        }

        $ids = array_map(fn(array $row): int => (int)$row['id'], $rows);
        $idPlaceholders = implode(',', array_fill(0, count($ids), '?'));
        $update = Database::pdo()->prepare(
            'UPDATE treasury_payment_requests
             SET status = "reconciled_to_treasury",
                 reconciliation_transaction_id = ?,
                 reconciled_at = ?
             WHERE id IN (' . $idPlaceholders . ')'
        );
        $update->execute([$transactionId, $completedAt, ...$ids]);

        return array_map(fn(array $row): string => $row['request_uuid'], $rows);
    }

    private function normaliseDateTime(string $value): string
    {
        $dt = $value === 'now' ? new \DateTimeImmutable('now') : new \DateTimeImmutable($value);
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
