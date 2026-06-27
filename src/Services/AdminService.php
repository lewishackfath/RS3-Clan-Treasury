<?php

declare(strict_types=1);

namespace Treasury\Services;

use PDO;
use Treasury\Database;

final class AdminService
{
    public function all(bool $activeOnly = true): array
    {
        $sql = 'SELECT ta.*, 
                    (SELECT COUNT(*) FROM treasury_ledger_entries le WHERE le.admin_id = ta.id) AS ledger_entry_count,
                    (SELECT COUNT(*) FROM treasury_transactions tx WHERE tx.posted_by_admin_id = ta.id) AS posted_transaction_count,
                    (SELECT COUNT(*) FROM treasury_reconciliations r WHERE r.from_admin_id = ta.id OR r.created_by_admin_id = ta.id OR r.completed_by_admin_id = ta.id) AS reconciliation_count,
                    (SELECT COUNT(*) FROM treasury_accounts a WHERE a.admin_id = ta.id) AS account_count,
                    (SELECT COUNT(*) FROM treasury_payment_requests pr WHERE pr.received_by_admin_id = ta.id) AS received_payment_count,
                    (SELECT COUNT(*) FROM treasury_payout_requests po WHERE po.paid_by_admin_id = ta.id) AS paid_payout_count
                FROM treasury_admins ta';
        if ($activeOnly) {
            $sql .= ' WHERE ta.is_active = 1';
        }
        $sql .= ' ORDER BY ta.is_active DESC, ta.display_name ASC, ta.rsn ASC';

        return Database::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get(int $id): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM treasury_admins WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \RuntimeException('Treasury user not found', 404);
        }
        return $row;
    }

    public function findByDiscordUserId(string $discordUserId): ?array
    {
        $discordUserId = trim($discordUserId);
        if ($discordUserId === '') {
            return null;
        }

        $stmt = Database::pdo()->prepare('SELECT * FROM treasury_admins WHERE discord_user_id = :discord_user_id AND is_active = 1 LIMIT 1');
        $stmt->execute(['discord_user_id' => $discordUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }


    public function findByRsn(string $rsn): ?array
    {
        $rsn = $this->cleanRsn($rsn);
        if ($rsn === '') {
            return null;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT * FROM treasury_admins
             WHERE LOWER(rsn) = LOWER(:rsn) AND is_active = 1
             ORDER BY id ASC
             LIMIT 1'
        );
        $stmt->execute(['rsn' => $rsn]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function create(array $data, ?int $actorAdminId = null): array
    {
        $rsn = $this->cleanRsn((string)($data['rsn'] ?? ''));
        if ($rsn === '') {
            throw new \InvalidArgumentException('RSN is required.');
        }

        $displayName = trim((string)($data['display_name'] ?? '')) ?: $rsn;
        $discordUserId = $this->cleanDiscordUserId((string)($data['discord_user_id'] ?? ''));

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO treasury_admins (discord_user_id, rsn, display_name, is_active, created_at, updated_at)
                 VALUES (:discord_user_id, :rsn, :display_name, 1, NOW(), NOW())'
            );
            $stmt->execute([
                'discord_user_id' => $discordUserId,
                'rsn' => $rsn,
                'display_name' => $displayName,
            ]);

            $id = (int)$pdo->lastInsertId();
            $this->insertRsnHistory($id, $rsn, $actorAdminId, true);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        (new AccountService())->ensureAdminHeldAccount($id);

        $created = $this->get($id);
        AuditService::log('user.created', 'treasury_admin', (string)$id, null, $created, null, $actorAdminId ?? $id);
        return $created;
    }

    public function update(int $id, array $data, int $actorAdminId): array
    {
        $before = $this->get($id);
        if ((int)$before['is_active'] !== 1) {
            throw new \RuntimeException('Restore this user before editing them.');
        }

        $rsn = $this->cleanRsn((string)($data['rsn'] ?? ''));
        if ($rsn === '') {
            throw new \InvalidArgumentException('RSN is required.');
        }
        $displayName = trim((string)($data['display_name'] ?? '')) ?: $rsn;
        $discordUserId = $this->cleanDiscordUserId((string)($data['discord_user_id'] ?? ''));

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'UPDATE treasury_admins
                 SET rsn = :rsn, display_name = :display_name, discord_user_id = :discord_user_id, updated_at = NOW()
                 WHERE id = :id'
            );
            $stmt->execute([
                'id' => $id,
                'rsn' => $rsn,
                'display_name' => $displayName,
                'discord_user_id' => $discordUserId,
            ]);

            if (strcasecmp((string)$before['rsn'], $rsn) !== 0) {
                $this->closeCurrentRsnHistory($id);
                $this->insertRsnHistory($id, $rsn, $actorAdminId, true);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $after = $this->get($id);
        $this->syncAdminHeldAccountName($id, $after);
        AuditService::log('user.updated', 'treasury_admin', (string)$id, $before, $after, null, $actorAdminId);
        return $after;
    }

    public function setActive(int $id, bool $active, int $actorAdminId): void
    {
        $before = $this->get($id);
        if ($id === $actorAdminId && !$active) {
            throw new \RuntimeException('You cannot archive the acting treasury user.');
        }

        $stmt = Database::pdo()->prepare('UPDATE treasury_admins SET is_active = :active, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $id, 'active' => $active ? 1 : 0]);
        $after = $this->get($id);

        AuditService::log($active ? 'user.restored' : 'user.archived', 'treasury_admin', (string)$id, $before, $after, null, $actorAdminId);
    }

    public function deleteIfUnused(int $id, int $actorAdminId): void
    {
        $before = $this->get($id);
        if ($id === $actorAdminId) {
            throw new \RuntimeException('You cannot delete the acting treasury user.');
        }
        if ($this->usageCount($id) > 0) {
            throw new \RuntimeException('This user has treasury history and must be archived instead of deleted.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('DELETE FROM treasury_admin_rsn_history WHERE admin_id = :id');
            $stmt->execute(['id' => $id]);

            $stmt = $pdo->prepare('DELETE FROM treasury_accounts WHERE admin_id = :id');
            $stmt->execute(['id' => $id]);

            $stmt = $pdo->prepare('DELETE FROM treasury_admins WHERE id = :id');
            $stmt->execute(['id' => $id]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        AuditService::log('user.deleted', 'treasury_admin', (string)$id, $before, null, null, $actorAdminId);
    }

    public function rsnHistory(int $adminId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT h.*, changed.display_name AS changed_by_display_name, changed.rsn AS changed_by_rsn
             FROM treasury_admin_rsn_history h
             LEFT JOIN treasury_admins changed ON changed.id = h.changed_by_admin_id
             WHERE h.admin_id = :admin_id
             ORDER BY h.is_current DESC, h.effective_from DESC, h.id DESC'
        );
        $stmt->execute(['admin_id' => $adminId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int,array> */
    public function rsnHistoryForAdmins(array $adminIds): array
    {
        $adminIds = array_values(array_unique(array_map('intval', $adminIds)));
        if (!$adminIds) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($adminIds), '?'));
        $stmt = Database::pdo()->prepare(
            'SELECT h.*, changed.display_name AS changed_by_display_name, changed.rsn AS changed_by_rsn
             FROM treasury_admin_rsn_history h
             LEFT JOIN treasury_admins changed ON changed.id = h.changed_by_admin_id
             WHERE h.admin_id IN (' . $placeholders . ')
             ORDER BY h.admin_id ASC, h.is_current DESC, h.effective_from DESC, h.id DESC'
        );
        $stmt->execute($adminIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int)$row['admin_id']][] = $row;
        }
        return $grouped;
    }

    public function ensureCurrentRsnHistoryForExistingAdmins(): void
    {
        $pdo = Database::pdo();
        $rows = $pdo->query('SELECT id, rsn FROM treasury_admins')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM treasury_admin_rsn_history WHERE admin_id = :admin_id');
            $stmt->execute(['admin_id' => (int)$row['id']]);
            if ((int)$stmt->fetchColumn() === 0) {
                $this->insertRsnHistory((int)$row['id'], (string)$row['rsn'], null, true);
            }
        }
    }

    private function syncAdminHeldAccountName(int $adminId, array $admin): void
    {
        $name = 'Funds Owed by Admin - ' . ((string)($admin['display_name'] ?? '') ?: (string)($admin['rsn'] ?? ''));
        $stmt = Database::pdo()->prepare('UPDATE treasury_accounts SET name = :name WHERE admin_id = :admin_id AND account_type = "asset" AND code LIKE "1100:%"');
        $stmt->execute(['name' => $name, 'admin_id' => $adminId]);
    }

    private function usageCount(int $id): int
    {
        $pdo = Database::pdo();
        $queries = [
            'SELECT COUNT(*) FROM treasury_ledger_entries WHERE admin_id = :id',
            'SELECT COUNT(*) FROM treasury_ledger_entries le INNER JOIN treasury_accounts a ON a.id = le.account_id WHERE a.admin_id = :id',
            'SELECT COUNT(*) FROM treasury_transactions WHERE posted_by_admin_id = :id',
            'SELECT COUNT(*) FROM treasury_reconciliations WHERE from_admin_id = :id OR created_by_admin_id = :id OR completed_by_admin_id = :id',
            'SELECT COUNT(*) FROM treasury_payment_requests WHERE received_by_admin_id = :id',
            'SELECT COUNT(*) FROM treasury_payout_requests WHERE paid_by_admin_id = :id',
        ];
        $total = 0;
        foreach ($queries as $sql) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            $total += (int)$stmt->fetchColumn();
        }
        return $total;
    }

    private function cleanRsn(string $rsn): string
    {
        return trim(preg_replace('/\s+/', ' ', $rsn) ?? '');
    }

    private function cleanDiscordUserId(string $discordUserId): ?string
    {
        $discordUserId = trim($discordUserId);
        return $discordUserId === '' ? null : $discordUserId;
    }

    private function closeCurrentRsnHistory(int $adminId): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE treasury_admin_rsn_history
             SET is_current = 0, effective_to = NOW()
             WHERE admin_id = :admin_id AND is_current = 1'
        );
        $stmt->execute(['admin_id' => $adminId]);
    }

    private function insertRsnHistory(int $adminId, string $rsn, ?int $actorAdminId, bool $current): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO treasury_admin_rsn_history (admin_id, rsn, effective_from, effective_to, is_current, changed_by_admin_id, created_at)
             VALUES (:admin_id, :rsn, NOW(), NULL, :is_current, :changed_by_admin_id, NOW())'
        );
        $stmt->execute([
            'admin_id' => $adminId,
            'rsn' => $rsn,
            'is_current' => $current ? 1 : 0,
            'changed_by_admin_id' => $actorAdminId,
        ]);
    }
}
