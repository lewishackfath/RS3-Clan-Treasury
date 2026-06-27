<?php

declare(strict_types=1);

namespace Treasury\Services;

use PDO;
use Treasury\Database;

final class TreasuryQueryService
{
    public function dashboardStats(): array
    {
        $pdo = Database::pdo();
        return [
            'pending_payments' => (int)$pdo->query('SELECT COUNT(*) FROM treasury_payment_requests WHERE status = "pending"')->fetchColumn(),
            'received_unreconciled_payments' => (int)$pdo->query('SELECT COUNT(*) FROM treasury_payment_requests WHERE status = "received_by_admin"')->fetchColumn(),
            'pending_payouts' => (int)$pdo->query('SELECT COUNT(*) FROM treasury_payout_requests WHERE status = "pending"')->fetchColumn(),
            'admin_paid_unreimbursed' => (int)$pdo->query('SELECT COUNT(*) FROM treasury_payout_requests WHERE status = "paid_by_admin"')->fetchColumn(),
        ];
    }

    public function paymentRequests(array $filters = [], int $limit = 100): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'pr.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['app_id'])) {
            $where[] = 'pr.app_id = :app_id';
            $params['app_id'] = (int)$filters['app_id'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(pr.player_rsn LIKE :q OR pr.description LIKE :q OR pr.source_id LIKE :q OR pr.source_type LIKE :q)';
            $params['q'] = '%' . $filters['q'] . '%';
        }

        $sql = 'SELECT pr.*, a.name AS app_name, a.slug AS app_slug,
                       admin.display_name AS received_by_display_name, admin.rsn AS received_by_rsn,
                       revenue.code AS revenue_account_code, revenue.name AS revenue_account_name
                FROM treasury_payment_requests pr
                JOIN treasury_apps a ON a.id = pr.app_id
                LEFT JOIN treasury_admins admin ON admin.id = pr.received_by_admin_id
                LEFT JOIN treasury_accounts revenue ON revenue.id = pr.revenue_account_id';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY pr.created_at DESC LIMIT ' . max(1, min(500, $limit));

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function paymentRequestByUuid(string $uuid): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT pr.*, a.name AS app_name, a.slug AS app_slug,
                    admin.display_name AS received_by_display_name, admin.rsn AS received_by_rsn,
                    revenue.code AS revenue_account_code, revenue.name AS revenue_account_name,
                    COALESCE(settled.settled_amount, 0) AS offset_settled_amount,
                    GREATEST(pr.amount - COALESCE(settled.settled_amount, 0), 0) AS remaining_amount
             FROM treasury_payment_requests pr
             JOIN treasury_apps a ON a.id = pr.app_id
             LEFT JOIN treasury_admins admin ON admin.id = pr.received_by_admin_id
             LEFT JOIN treasury_accounts revenue ON revenue.id = pr.revenue_account_id
             LEFT JOIN (
                 SELECT request_id, SUM(amount) AS settled_amount
                 FROM treasury_request_settlements
                 WHERE request_type = "payment"
                 GROUP BY request_id
             ) settled ON settled.request_id = pr.id
             WHERE pr.request_uuid = :uuid LIMIT 1'
        );
        $stmt->execute(['uuid' => $uuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function payoutRequests(array $filters = [], int $limit = 100): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'pr.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['app_id'])) {
            $where[] = 'pr.app_id = :app_id';
            $params['app_id'] = (int)$filters['app_id'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(pr.payee_rsn LIKE :q OR pr.description LIKE :q OR pr.source_id LIKE :q OR pr.source_type LIKE :q)';
            $params['q'] = '%' . $filters['q'] . '%';
        }

        $sql = 'SELECT pr.*, a.name AS app_name, a.slug AS app_slug,
                       admin.display_name AS paid_by_display_name, admin.rsn AS paid_by_rsn,
                       expense.code AS expense_account_code, expense.name AS expense_account_name
                FROM treasury_payout_requests pr
                JOIN treasury_apps a ON a.id = pr.app_id
                LEFT JOIN treasury_admins admin ON admin.id = pr.paid_by_admin_id
                LEFT JOIN treasury_accounts expense ON expense.id = pr.expense_account_id';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY pr.created_at DESC LIMIT ' . max(1, min(500, $limit));

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function unreconciledPaymentsByAdmin(?int $adminId = null): array
    {
        $params = [];
        $where = 'pr.status = "received_by_admin" AND pr.reconciliation_transaction_id IS NULL';
        if ($adminId !== null && $adminId > 0) {
            $where .= ' AND pr.received_by_admin_id = :admin_id';
            $params['admin_id'] = $adminId;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT pr.*, a.name AS app_name, a.slug AS app_slug,
                    admin.display_name AS received_by_display_name, admin.rsn AS received_by_rsn,
                    revenue.code AS revenue_account_code, revenue.name AS revenue_account_name,
                    COALESCE(settled.settled_amount, 0) AS offset_settled_amount,
                    GREATEST(pr.amount - COALESCE(settled.settled_amount, 0), 0) AS remaining_amount
             FROM treasury_payment_requests pr
             JOIN treasury_apps a ON a.id = pr.app_id
             JOIN treasury_admins admin ON admin.id = pr.received_by_admin_id
             LEFT JOIN treasury_accounts revenue ON revenue.id = pr.revenue_account_id
             LEFT JOIN (
                 SELECT request_id, SUM(amount) AS settled_amount
                 FROM treasury_request_settlements
                 WHERE request_type = "payment"
                 GROUP BY request_id
             ) settled ON settled.request_id = pr.id
             WHERE ' . $where . '
               AND GREATEST(pr.amount - COALESCE(settled.settled_amount, 0), 0) > 0
             ORDER BY admin.display_name ASC, pr.received_at ASC'
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }



    public function handovers(array $filters = [], int $limit = 100): array
    {
        return $this->reconciliations($filters, $limit);
    }

    public function reconciliations(array $filters = [], int $limit = 100): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['admin_id'])) {
            $where[] = 'r.from_admin_id = :admin_id';
            $params['admin_id'] = (int)$filters['admin_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'r.status = :status';
            $params['status'] = $filters['status'];
        }

        $sql = 'SELECT r.*, t.transaction_uuid,
                       from_admin.display_name AS from_admin_display_name, from_admin.rsn AS from_admin_rsn,
                       created_admin.display_name AS created_by_display_name, created_admin.rsn AS created_by_rsn,
                       completed_admin.display_name AS completed_by_display_name, completed_admin.rsn AS completed_by_rsn,
                       COUNT(pr.id) AS linked_payment_count
                FROM treasury_reconciliations r
                LEFT JOIN treasury_transactions t ON t.id = r.transaction_id
                JOIN treasury_admins from_admin ON from_admin.id = r.from_admin_id
                LEFT JOIN treasury_admins created_admin ON created_admin.id = r.created_by_admin_id
                LEFT JOIN treasury_admins completed_admin ON completed_admin.id = r.completed_by_admin_id
                LEFT JOIN treasury_payment_requests pr ON pr.reconciliation_transaction_id = r.transaction_id';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' GROUP BY r.id, t.transaction_uuid, from_admin.display_name, from_admin.rsn, created_admin.display_name, created_admin.rsn, completed_admin.display_name, completed_admin.rsn
                  ORDER BY COALESCE(r.completed_at, r.created_at) DESC, r.id DESC
                  LIMIT ' . max(1, min(500, $limit));

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return [];
        }

        $transactionIds = [];
        foreach ($rows as $row) {
            if (!empty($row['transaction_id'])) {
                $transactionIds[] = (int)$row['transaction_id'];
            }
        }
        $paymentsByTransaction = $this->paymentRequestsByReconciliationTransaction($transactionIds);
        foreach ($rows as &$row) {
            $row['linked_payments'] = $paymentsByTransaction[(int)($row['transaction_id'] ?? 0)] ?? [];
        }
        unset($row);

        return $rows;
    }

    public function paymentRequestsByReconciliationTransaction(array $transactionIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $transactionIds))));
        if (!$ids) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::pdo()->prepare(
            'SELECT pr.*, a.name AS app_name, a.slug AS app_slug,
                    admin.display_name AS received_by_display_name, admin.rsn AS received_by_rsn
             FROM treasury_payment_requests pr
             JOIN treasury_apps a ON a.id = pr.app_id
             LEFT JOIN treasury_admins admin ON admin.id = pr.received_by_admin_id
             WHERE pr.reconciliation_transaction_id IN (' . $placeholders . ')
             ORDER BY pr.received_at ASC, pr.id ASC'
        );
        $stmt->execute($ids);

        $grouped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $grouped[(int)$row['reconciliation_transaction_id']][] = $row;
        }
        return $grouped;
    }

    public function transactions(array $filters = [], int $limit = 100): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['transaction_type'])) {
            $where[] = 't.transaction_type = :transaction_type';
            $params['transaction_type'] = $filters['transaction_type'];
        }
        if (!empty($filters['app_id'])) {
            $where[] = 't.app_id = :app_id';
            $params['app_id'] = (int)$filters['app_id'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(t.description LIKE :q OR t.source_id LIKE :q OR t.source_type LIKE :q OR le.player_rsn LIKE :q)';
            $params['q'] = '%' . $filters['q'] . '%';
        }

        $sql = 'SELECT DISTINCT t.*, a.name AS app_name, admin.display_name AS posted_by_display_name, admin.rsn AS posted_by_rsn,
                       rev.transaction_uuid AS reversal_uuid,
                       related.transaction_uuid AS related_transaction_uuid
                FROM treasury_transactions t
                LEFT JOIN treasury_apps a ON a.id = t.app_id
                LEFT JOIN treasury_admins admin ON admin.id = t.posted_by_admin_id
                LEFT JOIN treasury_ledger_entries le ON le.transaction_id = t.id
                LEFT JOIN treasury_transactions rev ON rev.related_transaction_id = t.id AND rev.transaction_type = "reversal" AND rev.status = "posted"
                LEFT JOIN treasury_transactions related ON related.id = t.related_transaction_id';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY t.occurred_at DESC, t.id DESC LIMIT ' . max(1, min(500, $limit));

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$transactions) {
            return [];
        }

        $ids = array_map(fn(array $row): int => (int)$row['id'], $transactions);
        $lines = $this->ledgerLinesForTransactions($ids);
        foreach ($transactions as &$transaction) {
            $transaction['lines'] = $lines[(int)$transaction['id']] ?? [];
        }
        unset($transaction);

        return $transactions;
    }

    public function ledgerLinesForTransactions(array $transactionIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $transactionIds))));
        if (!$ids) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::pdo()->prepare(
            'SELECT le.*, acc.code AS account_code, acc.name AS account_name,
                    admin.display_name AS admin_display_name, admin.rsn AS admin_rsn
             FROM treasury_ledger_entries le
             JOIN treasury_accounts acc ON acc.id = le.account_id
             LEFT JOIN treasury_admins admin ON admin.id = le.admin_id
             WHERE le.transaction_id IN (' . $placeholders . ')
             ORDER BY le.id ASC'
        );
        $stmt->execute($ids);

        $grouped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
            $grouped[(int)$line['transaction_id']][] = $line;
        }
        return $grouped;
    }



    public function payoutRequestByUuid(string $uuid): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT pr.*, a.name AS app_name, a.slug AS app_slug,
                    admin.display_name AS paid_by_display_name, admin.rsn AS paid_by_rsn,
                    expense.code AS expense_account_code, expense.name AS expense_account_name,
                    COALESCE(settled.settled_amount, 0) AS offset_settled_amount,
                    GREATEST(pr.amount - COALESCE(settled.settled_amount, 0), 0) AS remaining_amount
             FROM treasury_payout_requests pr
             JOIN treasury_apps a ON a.id = pr.app_id
             LEFT JOIN treasury_admins admin ON admin.id = pr.paid_by_admin_id
             LEFT JOIN treasury_accounts expense ON expense.id = pr.expense_account_id
             LEFT JOIN (
                 SELECT request_id, SUM(amount) AS settled_amount
                 FROM treasury_request_settlements
                 WHERE request_type = "payout"
                 GROUP BY request_id
             ) settled ON settled.request_id = pr.id
             WHERE pr.request_uuid = :uuid LIMIT 1'
        );
        $stmt->execute(['uuid' => $uuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function reconciliationByUuid(string $uuid): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT r.*, t.transaction_uuid,
                    from_admin.display_name AS from_admin_display_name, from_admin.rsn AS from_admin_rsn,
                    created_admin.display_name AS created_by_display_name, created_admin.rsn AS created_by_rsn,
                    completed_admin.display_name AS completed_by_display_name, completed_admin.rsn AS completed_by_rsn,
                    COUNT(pr.id) AS linked_payment_count
             FROM treasury_reconciliations r
             LEFT JOIN treasury_transactions t ON t.id = r.transaction_id
             JOIN treasury_admins from_admin ON from_admin.id = r.from_admin_id
             LEFT JOIN treasury_admins created_admin ON created_admin.id = r.created_by_admin_id
             LEFT JOIN treasury_admins completed_admin ON completed_admin.id = r.completed_by_admin_id
             LEFT JOIN treasury_payment_requests pr ON pr.reconciliation_transaction_id = r.transaction_id
             WHERE r.reconciliation_uuid = :uuid
             GROUP BY r.id, t.transaction_uuid, from_admin.display_name, from_admin.rsn, created_admin.display_name, created_admin.rsn, completed_admin.display_name, completed_admin.rsn
             LIMIT 1'
        );
        $stmt->execute(['uuid' => $uuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $linked = $this->paymentRequestsByReconciliationTransaction([(int)($row['transaction_id'] ?? 0)]);
        $row['linked_payments'] = $linked[(int)($row['transaction_id'] ?? 0)] ?? [];
        return $row;
    }

    public function transactionByUuid(string $uuid): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT DISTINCT t.*, a.name AS app_name, admin.display_name AS posted_by_display_name, admin.rsn AS posted_by_rsn,
                    rev.transaction_uuid AS reversal_uuid,
                    related.transaction_uuid AS related_transaction_uuid
             FROM treasury_transactions t
             LEFT JOIN treasury_apps a ON a.id = t.app_id
             LEFT JOIN treasury_admins admin ON admin.id = t.posted_by_admin_id
             LEFT JOIN treasury_transactions rev ON rev.related_transaction_id = t.id AND rev.transaction_type = "reversal" AND rev.status = "posted"
             LEFT JOIN treasury_transactions related ON related.id = t.related_transaction_id
             WHERE t.transaction_uuid = :uuid LIMIT 1'
        );
        $stmt->execute(['uuid' => $uuid]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$transaction) {
            return null;
        }
        $lines = $this->ledgerLinesForTransactions([(int)$transaction['id']]);
        $transaction['lines'] = $lines[(int)$transaction['id']] ?? [];
        return $transaction;
    }

    public function transactionsByIds(array $transactionIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $transactionIds))));
        if (!$ids) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::pdo()->prepare(
            'SELECT DISTINCT t.*, a.name AS app_name, admin.display_name AS posted_by_display_name, admin.rsn AS posted_by_rsn,
                    rev.transaction_uuid AS reversal_uuid,
                    related.transaction_uuid AS related_transaction_uuid
             FROM treasury_transactions t
             LEFT JOIN treasury_apps a ON a.id = t.app_id
             LEFT JOIN treasury_admins admin ON admin.id = t.posted_by_admin_id
             LEFT JOIN treasury_transactions rev ON rev.related_transaction_id = t.id AND rev.transaction_type = "reversal" AND rev.status = "posted"
             LEFT JOIN treasury_transactions related ON related.id = t.related_transaction_id
             WHERE t.id IN (' . $placeholders . ')
             ORDER BY t.occurred_at ASC, t.id ASC'
        );
        $stmt->execute($ids);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$transactions) {
            return [];
        }
        $lines = $this->ledgerLinesForTransactions(array_map(fn(array $row): int => (int)$row['id'], $transactions));
        $byId = [];
        foreach ($transactions as $transaction) {
            $transaction['lines'] = $lines[(int)$transaction['id']] ?? [];
            $byId[(int)$transaction['id']] = $transaction;
        }
        return $byId;
    }

    public function auditLogForEntity(string $entityType, string $entityId, int $limit = 30): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT audit.*, admin.display_name AS actor_admin_name, admin.rsn AS actor_admin_rsn, app.name AS actor_app_name
             FROM treasury_audit_log audit
             LEFT JOIN treasury_admins admin ON admin.id = audit.actor_admin_id
             LEFT JOIN treasury_apps app ON app.id = audit.actor_app_id
             WHERE audit.entity_type = :entity_type AND audit.entity_id = :entity_id
             ORDER BY audit.created_at DESC
             LIMIT ' . max(1, min(100, $limit))
        );
        $stmt->execute([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function auditLog(int $limit = 50): array
    {
        $stmt = Database::pdo()->query(
            'SELECT audit.*, admin.display_name AS actor_admin_name, app.name AS actor_app_name
             FROM treasury_audit_log audit
             LEFT JOIN treasury_admins admin ON admin.id = audit.actor_admin_id
             LEFT JOIN treasury_apps app ON app.id = audit.actor_app_id
             ORDER BY audit.created_at DESC
             LIMIT ' . max(1, min(200, $limit))
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
