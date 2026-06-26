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
                       admin.display_name AS received_by_display_name, admin.rsn AS received_by_rsn
                FROM treasury_payment_requests pr
                JOIN treasury_apps a ON a.id = pr.app_id
                LEFT JOIN treasury_admins admin ON admin.id = pr.received_by_admin_id';
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
                    admin.display_name AS received_by_display_name, admin.rsn AS received_by_rsn
             FROM treasury_payment_requests pr
             JOIN treasury_apps a ON a.id = pr.app_id
             LEFT JOIN treasury_admins admin ON admin.id = pr.received_by_admin_id
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
                       admin.display_name AS paid_by_display_name, admin.rsn AS paid_by_rsn
                FROM treasury_payout_requests pr
                JOIN treasury_apps a ON a.id = pr.app_id
                LEFT JOIN treasury_admins admin ON admin.id = pr.paid_by_admin_id';
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
                    admin.display_name AS received_by_display_name, admin.rsn AS received_by_rsn
             FROM treasury_payment_requests pr
             JOIN treasury_apps a ON a.id = pr.app_id
             JOIN treasury_admins admin ON admin.id = pr.received_by_admin_id
             WHERE ' . $where . '
             ORDER BY admin.display_name ASC, pr.received_at ASC'
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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

        $sql = 'SELECT DISTINCT t.*, a.name AS app_name, admin.display_name AS posted_by_display_name, admin.rsn AS posted_by_rsn
                FROM treasury_transactions t
                LEFT JOIN treasury_apps a ON a.id = t.app_id
                LEFT JOIN treasury_admins admin ON admin.id = t.posted_by_admin_id
                LEFT JOIN treasury_ledger_entries le ON le.transaction_id = t.id';
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
