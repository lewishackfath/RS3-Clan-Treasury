<?php

declare(strict_types=1);

namespace Treasury\Services;

use PDO;
use Treasury\Database;

final class SettingService
{
    public function get(string $name, ?string $default = null): ?string
    {
        $stmt = Database::pdo()->prepare('SELECT value FROM treasury_settings WHERE name = :name LIMIT 1');
        $stmt->execute(['name' => $name]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : (string)$value;
    }

    public function set(string $name, ?string $value, ?int $actorAdminId = null): void
    {
        $name = trim($name);
        if ($name === '' || !preg_match('/^[a-z0-9_.-]+$/i', $name)) {
            throw new \InvalidArgumentException('Invalid setting name.');
        }

        $before = $this->get($name);
        $stmt = Database::pdo()->prepare(
            'INSERT INTO treasury_settings (name, value, updated_by_admin_id, updated_at)
             VALUES (:name, :value, :updated_by_admin_id, NOW())
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_by_admin_id = VALUES(updated_by_admin_id), updated_at = NOW()'
        );
        $stmt->execute([
            'name' => $name,
            'value' => $value,
            'updated_by_admin_id' => $actorAdminId,
        ]);

        AuditService::log(
            'setting.updated',
            'treasury_setting',
            $name,
            ['value' => $before],
            ['value' => $value],
            null,
            $actorAdminId
        );
    }

    public function all(): array
    {
        return Database::pdo()->query('SELECT * FROM treasury_settings ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
    }
}
