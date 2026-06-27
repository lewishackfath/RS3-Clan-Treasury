<?php

declare(strict_types=1);

namespace Treasury\Services;

use PDO;
use Treasury\Auth\ApiContext;
use Treasury\Database;

final class AppService
{
    public const SYSTEM_MANUAL_SLUG = 'manual_admin';

    public const AVAILABLE_SCOPES = [
        'payments:create' => 'Create money-in requests',
        'payments:receive' => 'Mark money-in requests as received by an admin',
        'payments:read' => 'Read money-in request status',
        'payouts:create' => 'Create money-out requests',
        'payouts:pay' => 'Mark money-out requests as paid by an admin',
        'payouts:read' => 'Read money-out request status',
        'admins:read' => 'Read valid treasury admin RSNs for assignment',
        'transactions:read' => 'Read linked transaction details',
        'balances:read' => 'Read treasury balances',
        'reports:read' => 'Read report summaries',
        'reconciliation:read' => 'Read reconciliation status',
    ];

    public function all(bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM treasury_apps';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY name ASC';

        return Database::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function allWithUsage(bool $includeInactive = true): array
    {
        $sql = 'SELECT a.*,
                       COALESCE(keys_all.api_key_count, 0) AS api_key_count,
                       COALESCE(keys_active.active_api_key_count, 0) AS active_api_key_count,
                       COALESCE(payment_usage.payment_request_count, 0) AS payment_request_count,
                       COALESCE(payout_usage.payout_request_count, 0) AS payout_request_count,
                       COALESCE(transaction_usage.transaction_count, 0) AS transaction_count,
                       COALESCE(account_usage.account_count, 0) AS account_count
                FROM treasury_apps a
                LEFT JOIN (
                    SELECT app_id, COUNT(*) AS api_key_count
                    FROM treasury_api_keys
                    GROUP BY app_id
                ) keys_all ON keys_all.app_id = a.id
                LEFT JOIN (
                    SELECT app_id, COUNT(*) AS active_api_key_count
                    FROM treasury_api_keys
                    WHERE is_active = 1
                    GROUP BY app_id
                ) keys_active ON keys_active.app_id = a.id
                LEFT JOIN (
                    SELECT app_id, COUNT(*) AS payment_request_count
                    FROM treasury_payment_requests
                    GROUP BY app_id
                ) payment_usage ON payment_usage.app_id = a.id
                LEFT JOIN (
                    SELECT app_id, COUNT(*) AS payout_request_count
                    FROM treasury_payout_requests
                    GROUP BY app_id
                ) payout_usage ON payout_usage.app_id = a.id
                LEFT JOIN (
                    SELECT app_id, COUNT(*) AS transaction_count
                    FROM treasury_transactions
                    WHERE app_id IS NOT NULL
                    GROUP BY app_id
                ) transaction_usage ON transaction_usage.app_id = a.id
                LEFT JOIN (
                    SELECT app_id, COUNT(*) AS account_count
                    FROM treasury_accounts
                    WHERE app_id IS NOT NULL
                    GROUP BY app_id
                ) account_usage ON account_usage.app_id = a.id';
        if (!$includeInactive) {
            $sql .= ' WHERE a.is_active = 1';
        }
        $sql .= ' ORDER BY FIELD(a.slug, "' . self::SYSTEM_MANUAL_SLUG . '") DESC, a.name ASC';

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

    public function manualContext(?int $appId = null): ApiContext
    {
        $app = $appId && $appId > 0 ? $this->get($appId) : $this->manualApp();
        return new ApiContext(
            (int)$app['id'],
            (string)$app['slug'],
            (string)$app['name'],
            0,
            ['*']
        );
    }

    public function manualApp(): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM treasury_apps WHERE slug = "' . self::SYSTEM_MANUAL_SLUG . '" LIMIT 1');
        $stmt->execute();
        $app = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($app) {
            return $app;
        }

        return $this->create([
            'name' => 'Manual Entry',
            'slug' => self::SYSTEM_MANUAL_SLUG,
            'description' => 'Internal source app for transactions created directly in the Treasury web UI.',
        ]);
    }

    public function create(array $data): array
    {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('App name is required.');
        }

        $requestedSlug = trim((string)($data['slug'] ?? ''));
        $slug = $requestedSlug === ''
            ? $this->uniqueSlugForName($name)
            : $this->normaliseSlug($requestedSlug, $name);

        if ($requestedSlug !== '') {
            $existing = Database::pdo()->prepare('SELECT id FROM treasury_apps WHERE slug = :slug LIMIT 1');
            $existing->execute(['slug' => $slug]);
            if ($existing->fetchColumn()) {
                throw new \InvalidArgumentException('Another source app already uses that slug.');
            }
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO treasury_apps (name, slug, description, is_active)
             VALUES (:name, :slug, :description, 1)'
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

    public function update(int $appId, array $data, int $actorAdminId): array
    {
        $before = $this->get($appId);
        if ($this->isManualApp($before)) {
            throw new \RuntimeException('Manual Entry is a required system source and cannot be edited.');
        }

        $name = trim((string)($data['name'] ?? ''));
        $slug = $this->normaliseSlug((string)($data['slug'] ?? ''), $name);
        if ($name === '') {
            throw new \InvalidArgumentException('App name is required.');
        }

        $duplicate = Database::pdo()->prepare('SELECT id FROM treasury_apps WHERE slug = :slug AND id <> :id LIMIT 1');
        $duplicate->execute(['slug' => $slug, 'id' => $appId]);
        if ($duplicate->fetchColumn()) {
            throw new \InvalidArgumentException('Another source app already uses that slug.');
        }

        $stmt = Database::pdo()->prepare(
            'UPDATE treasury_apps
             SET name = :name, slug = :slug, description = :description, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'name' => $name,
            'slug' => $slug,
            'description' => trim((string)($data['description'] ?? '')) ?: null,
            'id' => $appId,
        ]);

        $after = $this->get($appId);
        AuditService::log('source_app.updated', 'treasury_app', (string)$appId, $before, $after, null, $actorAdminId);
        return $after;
    }

    public function setActive(int $appId, bool $active, int $actorAdminId): void
    {
        $before = $this->get($appId);
        if ($this->isManualApp($before)) {
            throw new \RuntimeException('Manual Entry is a required system source and cannot be archived.');
        }

        Database::pdo()->prepare('UPDATE treasury_apps SET is_active = :active, updated_at = NOW() WHERE id = :id')
            ->execute(['active' => $active ? 1 : 0, 'id' => $appId]);
        $after = $this->get($appId);
        AuditService::log($active ? 'source_app.restored' : 'source_app.archived', 'treasury_app', (string)$appId, $before, $after, null, $actorAdminId);
    }

    public function deleteIfUnused(int $appId, int $actorAdminId): void
    {
        $before = $this->get($appId);
        if ($this->isManualApp($before)) {
            throw new \RuntimeException('Manual Entry is a required system source and cannot be deleted.');
        }

        $usage = $this->usageCounts($appId);
        if (array_sum($usage) > 0) {
            throw new \RuntimeException('This source app has related records. Archive it instead.');
        }

        Database::pdo()->prepare('DELETE FROM treasury_apps WHERE id = :id')->execute(['id' => $appId]);
        AuditService::log('source_app.deleted', 'treasury_app', (string)$appId, $before, null, null, $actorAdminId);
    }

    public function apiKeys(?int $appId = null): array
    {
        $sql = 'SELECT k.*, a.name AS app_name, a.slug AS app_slug
                FROM treasury_api_keys k
                JOIN treasury_apps a ON a.id = k.app_id';
        $params = [];
        if ($appId !== null && $appId > 0) {
            $sql .= ' WHERE k.app_id = :app_id';
            $params['app_id'] = $appId;
        }
        $sql .= ' ORDER BY a.name ASC, k.created_at DESC, k.id DESC';

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $decoded = json_decode((string)$row['scopes'], true);
            $row['scopes_array'] = is_array($decoded) ? $decoded : [];
        }
        return $rows;
    }

    public function createApiKey(array $data, int $actorAdminId): array
    {
        $appId = (int)($data['app_id'] ?? 0);
        $app = $this->get($appId);
        if ($this->isManualApp($app)) {
            throw new \RuntimeException('API keys cannot be created for the internal Manual Entry source.');
        }
        if ((int)$app['is_active'] !== 1) {
            throw new \RuntimeException('API keys can only be created for active source apps.');
        }

        $keyName = trim((string)($data['key_name'] ?? ''));
        if ($keyName === '') {
            throw new \InvalidArgumentException('API key name is required.');
        }

        $scopes = $this->normaliseScopes($data['scopes'] ?? []);
        if (!$scopes) {
            throw new \InvalidArgumentException('Choose at least one API scope.');
        }

        $expiresAt = $this->normaliseExpiry((string)($data['expires_at'] ?? ''));
        $rawKey = 'trsy_' . bin2hex(random_bytes(32));
        $keyHash = hash('sha256', $rawKey);

        $stmt = Database::pdo()->prepare(
            'INSERT INTO treasury_api_keys (app_id, key_name, key_hash, scopes, expires_at, is_active)
             VALUES (:app_id, :key_name, :key_hash, :scopes, :expires_at, 1)'
        );
        $stmt->execute([
            'app_id' => $appId,
            'key_name' => $keyName,
            'key_hash' => $keyHash,
            'scopes' => json_encode($scopes, JSON_UNESCAPED_SLASHES),
            'expires_at' => $expiresAt,
        ]);

        $keyId = (int)Database::pdo()->lastInsertId();
        $record = $this->apiKey($keyId);
        AuditService::log('api_key.created', 'treasury_api_key', (string)$keyId, null, [
            'id' => $keyId,
            'app_id' => $appId,
            'key_name' => $keyName,
            'scopes' => $scopes,
            'expires_at' => $expiresAt,
        ], null, $actorAdminId);

        return ['raw_key' => $rawKey, 'record' => $record];
    }

    public function setApiKeyActive(int $keyId, bool $active, int $actorAdminId): void
    {
        $before = $this->apiKey($keyId);
        Database::pdo()->prepare('UPDATE treasury_api_keys SET is_active = :active WHERE id = :id')
            ->execute(['active' => $active ? 1 : 0, 'id' => $keyId]);
        $after = $this->apiKey($keyId);
        AuditService::log($active ? 'api_key.restored' : 'api_key.revoked', 'treasury_api_key', (string)$keyId, $before, $after, null, $actorAdminId);
    }

    public function updateApiKey(int $keyId, array $data, int $actorAdminId): array
    {
        $before = $this->apiKey($keyId);
        $keyName = trim((string)($data['key_name'] ?? ''));
        if ($keyName === '') {
            throw new \InvalidArgumentException('API key name is required.');
        }

        $scopes = $this->normaliseScopes($data['scopes'] ?? []);
        if (!$scopes) {
            throw new \InvalidArgumentException('Choose at least one API scope.');
        }

        $expiresAt = $this->normaliseExpiry((string)($data['expires_at'] ?? ''));
        Database::pdo()->prepare(
            'UPDATE treasury_api_keys
             SET key_name = :key_name, scopes = :scopes, expires_at = :expires_at
             WHERE id = :id'
        )->execute([
            'key_name' => $keyName,
            'scopes' => json_encode($scopes, JSON_UNESCAPED_SLASHES),
            'expires_at' => $expiresAt,
            'id' => $keyId,
        ]);

        $after = $this->apiKey($keyId);
        AuditService::log('api_key.updated', 'treasury_api_key', (string)$keyId, $before, $after, null, $actorAdminId);
        return $after;
    }

    public function regenerateApiKey(int $keyId, int $actorAdminId): array
    {
        $before = $this->apiKey($keyId);
        $rawKey = 'trsy_' . bin2hex(random_bytes(32));
        $keyHash = hash('sha256', $rawKey);

        Database::pdo()->prepare(
            'UPDATE treasury_api_keys
             SET key_hash = :key_hash, is_active = 1
             WHERE id = :id'
        )->execute([
            'key_hash' => $keyHash,
            'id' => $keyId,
        ]);

        $after = $this->apiKey($keyId);
        AuditService::log('api_key.regenerated', 'treasury_api_key', (string)$keyId, $before, $after, null, $actorAdminId);
        return ['raw_key' => $rawKey, 'record' => $after];
    }

    public function deleteApiKey(int $keyId, int $actorAdminId): void
    {
        $before = $this->apiKey($keyId);
        if (!empty($before['last_used_at'])) {
            throw new \RuntimeException('API keys that have been used should be revoked instead of deleted.');
        }

        Database::pdo()->prepare('DELETE FROM treasury_api_keys WHERE id = :id')->execute(['id' => $keyId]);
        AuditService::log('api_key.deleted', 'treasury_api_key', (string)$keyId, $before, null, null, $actorAdminId);
    }

    public function apiKey(int $keyId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT k.*, a.name AS app_name, a.slug AS app_slug
             FROM treasury_api_keys k
             JOIN treasury_apps a ON a.id = k.app_id
             WHERE k.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $keyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \RuntimeException('API key not found', 404);
        }
        $decoded = json_decode((string)$row['scopes'], true);
        $row['scopes_array'] = is_array($decoded) ? $decoded : [];
        return $row;
    }

    public function isManualApp(array $app): bool
    {
        return (string)($app['slug'] ?? '') === self::SYSTEM_MANUAL_SLUG;
    }

    private function usageCounts(int $appId): array
    {
        $tables = [
            'api_keys' => 'SELECT COUNT(*) FROM treasury_api_keys WHERE app_id = :id',
            'payments' => 'SELECT COUNT(*) FROM treasury_payment_requests WHERE app_id = :id',
            'payouts' => 'SELECT COUNT(*) FROM treasury_payout_requests WHERE app_id = :id',
            'transactions' => 'SELECT COUNT(*) FROM treasury_transactions WHERE app_id = :id',
            'accounts' => 'SELECT COUNT(*) FROM treasury_accounts WHERE app_id = :id',
        ];
        $counts = [];
        foreach ($tables as $key => $sql) {
            $stmt = Database::pdo()->prepare($sql);
            $stmt->execute(['id' => $appId]);
            $counts[$key] = (int)$stmt->fetchColumn();
        }
        return $counts;
    }

    private function uniqueSlugForName(string $name): string
    {
        $base = $this->normaliseSlug('', $name);
        $slug = $base;
        $counter = 2;

        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM treasury_apps WHERE slug = :slug');
        while (true) {
            $stmt->execute(['slug' => $slug]);
            if ((int)$stmt->fetchColumn() === 0) {
                return $slug;
            }
            $slug = $base . '_' . $counter;
            $counter++;
        }
    }

    private function normaliseSlug(string $slug, string $fallbackName): string
    {
        $slug = trim($slug);
        if ($slug === '') {
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $fallbackName) ?? '');
            $slug = trim($slug, '_');
        } else {
            $slug = strtolower($slug);
            $slug = preg_replace('/[^a-z0-9_\-]+/i', '_', $slug) ?? '';
            $slug = trim($slug, '_-');
        }
        if ($slug === '') {
            throw new \InvalidArgumentException('Source app name must contain at least one letter or number for the generated slug.');
        }
        if (!preg_match('/^[a-z0-9_\-]+$/', $slug)) {
            throw new \InvalidArgumentException('Slug may only contain letters, numbers, underscores and dashes.');
        }
        return $slug;
    }

    private function normaliseScopes(mixed $value): array
    {
        if (is_string($value)) {
            $items = array_map('trim', explode(',', $value));
        } elseif (is_array($value)) {
            $items = array_map('trim', array_map('strval', $value));
        } else {
            $items = [];
        }

        $allowed = array_keys(self::AVAILABLE_SCOPES);
        $scopes = [];
        foreach ($items as $scope) {
            if ($scope === '') {
                continue;
            }
            if (!in_array($scope, $allowed, true)) {
                throw new \InvalidArgumentException('Unsupported API scope: ' . $scope);
            }
            $scopes[] = $scope;
        }
        return array_values(array_unique($scopes));
    }

    private function normaliseExpiry(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new \InvalidArgumentException('Expiry must be a valid date.');
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));
        if (!checkdate($month, $day, $year)) {
            throw new \InvalidArgumentException('Expiry must be a valid date.');
        }
        return $value . ' 23:59:59';
    }
}
