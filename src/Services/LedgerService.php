<?php

declare(strict_types=1);

namespace Treasury\Services;

use Treasury\Database;
use Treasury\Support\Uuid;

final class LedgerService
{
    public function postTransaction(array $header, array $lines): array
    {
        if (count($lines) < 2) {
            throw new \InvalidArgumentException('A posted transaction requires at least two ledger lines');
        }

        $debitTotal = 0;
        $creditTotal = 0;

        foreach ($lines as $line) {
            $amount = (int)($line['amount'] ?? 0);
            if ($amount <= 0) {
                throw new \InvalidArgumentException('Ledger line amount must be greater than zero');
            }

            $direction = $line['direction'] ?? '';
            if ($direction === 'debit') {
                $debitTotal += $amount;
            } elseif ($direction === 'credit') {
                $creditTotal += $amount;
            } else {
                throw new \InvalidArgumentException('Ledger line direction must be debit or credit');
            }
        }

        if ($debitTotal !== $creditTotal) {
            throw new \InvalidArgumentException('Ledger transaction is not balanced');
        }

        $pdo = Database::pdo();
        $uuid = Uuid::v4();
        $metadata = isset($header['metadata']) && is_array($header['metadata'])
            ? json_encode($header['metadata'], JSON_UNESCAPED_SLASHES)
            : null;

        $stmt = $pdo->prepare(
            'INSERT INTO treasury_transactions
             (transaction_uuid, app_id, source_type, source_id, transaction_type, status, description, notes, amount, occurred_at, posted_by_admin_id, related_transaction_id, metadata)
             VALUES
             (:transaction_uuid, :app_id, :source_type, :source_id, :transaction_type, "posted", :description, :notes, :amount, :occurred_at, :posted_by_admin_id, :related_transaction_id, :metadata)'
        );

        $stmt->execute([
            'transaction_uuid' => $uuid,
            'app_id' => $header['app_id'] ?? null,
            'source_type' => $header['source_type'] ?? null,
            'source_id' => $header['source_id'] ?? null,
            'transaction_type' => $header['transaction_type'],
            'description' => $header['description'],
            'notes' => $header['notes'] ?? null,
            'amount' => $debitTotal,
            'occurred_at' => $this->normaliseDateTime($header['occurred_at'] ?? 'now'),
            'posted_by_admin_id' => $header['posted_by_admin_id'] ?? null,
            'related_transaction_id' => $header['related_transaction_id'] ?? null,
            'metadata' => $metadata,
        ]);

        $transactionId = (int)$pdo->lastInsertId();

        $lineStmt = $pdo->prepare(
            'INSERT INTO treasury_ledger_entries
             (transaction_id, account_id, direction, amount, admin_id, player_rsn, memo)
             VALUES
             (:transaction_id, :account_id, :direction, :amount, :admin_id, :player_rsn, :memo)'
        );

        foreach ($lines as $line) {
            $lineStmt->execute([
                'transaction_id' => $transactionId,
                'account_id' => (int)$line['account_id'],
                'direction' => $line['direction'],
                'amount' => (int)$line['amount'],
                'admin_id' => $line['admin_id'] ?? null,
                'player_rsn' => $line['player_rsn'] ?? null,
                'memo' => $line['memo'] ?? null,
            ]);
        }

        return [
            'id' => $transactionId,
            'transaction_uuid' => $uuid,
            'amount' => $debitTotal,
        ];
    }

    private function normaliseDateTime(string $value): string
    {
        $dt = $value === 'now' ? new \DateTimeImmutable('now') : new \DateTimeImmutable($value);
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
