<?php

declare(strict_types=1);

namespace Treasury\Services;

use PDO;
use Treasury\Database;

final class AdminService
{
    public function all(bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM treasury_admins';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY display_name ASC, rsn ASC';

        return Database::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get(int $id): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM treasury_admins WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \RuntimeException('Treasury admin not found', 404);
        }
        return $row;
    }

    public function create(array $data): array
    {
        $rsn = trim((string)($data['rsn'] ?? ''));
        if ($rsn === '') {
            throw new \InvalidArgumentException('RSN is required.');
        }

        $displayName = trim((string)($data['display_name'] ?? '')) ?: $rsn;
        $discordUserId = trim((string)($data['discord_user_id'] ?? '')) ?: null;

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO treasury_admins (discord_user_id, rsn, display_name, is_active)
             VALUES (:discord_user_id, :rsn, :display_name, 1)'
        );
        $stmt->execute([
            'discord_user_id' => $discordUserId,
            'rsn' => $rsn,
            'display_name' => $displayName,
        ]);

        $id = (int)$pdo->lastInsertId();
        (new AccountService())->ensureAdminHeldAccount($id);

        $created = $this->get($id);
        AuditService::log('admin.created', 'treasury_admin', (string)$id, null, $created, null, $id);
        return $created;
    }
}
