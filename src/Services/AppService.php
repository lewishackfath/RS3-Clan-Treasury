<?php

declare(strict_types=1);

namespace Treasury\Services;

use PDO;
use Treasury\Database;

final class AppService
{
    public function all(bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM treasury_apps';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY name ASC';

        return Database::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get(int $id): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM treasury_apps WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \RuntimeException('Source app not found', 404);
        }
        return $row;
    }

    public function manualContext(?int $appId = null): \Treasury\Auth\ApiContext
    {
        $app = $appId && $appId > 0 ? $this->get($appId) : $this->manualApp();
        return new \Treasury\Auth\ApiContext(
            (int)$app['id'],
            (string)$app['slug'],
            (string)$app['name'],
            0,
            ['*']
        );
    }

    public function manualApp(): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM treasury_apps WHERE slug = "manual_admin" LIMIT 1');
        $stmt->execute();
        $app = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($app) {
            return $app;
        }

        return $this->create([
            'name' => 'Manual Entry',
            'slug' => 'manual_admin',
            'description' => 'Manual treasury administration actions',
        ]);
    }

    public function create(array $data): array
    {
        $name = trim((string)($data['name'] ?? ''));
        $slug = trim((string)($data['slug'] ?? ''));

        if ($name === '') {
            throw new \InvalidArgumentException('App name is required.');
        }
        if ($slug === '') {
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name) ?? '');
            $slug = trim($slug, '_');
        }
        if (!preg_match('/^[a-z0-9_\-]+$/', $slug)) {
            throw new \InvalidArgumentException('Slug may only contain letters, numbers, underscores and dashes.');
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO treasury_apps (name, slug, description, is_active)
             VALUES (:name, :slug, :description, 1)
             ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), is_active = 1, updated_at = NOW()'
        );
        $stmt->execute([
            'name' => $name,
            'slug' => $slug,
            'description' => trim((string)($data['description'] ?? '')) ?: null,
        ]);

        $find = Database::pdo()->prepare('SELECT * FROM treasury_apps WHERE slug = :slug LIMIT 1');
        $find->execute(['slug' => $slug]);
        return $find->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}
