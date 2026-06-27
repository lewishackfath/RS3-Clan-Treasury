<?php

declare(strict_types=1);

namespace Treasury\Auth;

use PDO;
use Treasury\Database;

final class ApiAuth
{
    private static ?ApiContext $lastContext = null;

    public static function lastContext(): ?ApiContext
    {
        return self::$lastContext;
    }

    private static function bearerHeader(): string
    {
        $candidates = [
            $_SERVER['HTTP_AUTHORIZATION'] ?? '',
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '',
            $_SERVER['Authorization'] ?? '',
        ];

        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach ($headers as $name => $value) {
                if (strcasecmp((string)$name, 'Authorization') === 0) {
                    $candidates[] = (string)$value;
                }
            }
        }

        foreach ($candidates as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    public static function requireContext(?string $requiredScope = null): ApiContext
    {
        $header = self::bearerHeader();
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            throw new \RuntimeException('Missing bearer token', 401);
        }

        $token = trim($matches[1]);
        $hash = hash('sha256', $token);

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT k.id AS api_key_id, k.scopes, a.id AS app_id, a.slug, a.name
             FROM treasury_api_keys k
             JOIN treasury_apps a ON a.id = k.app_id
             WHERE k.key_hash = :hash
               AND k.is_active = 1
               AND a.is_active = 1
               AND (k.expires_at IS NULL OR k.expires_at > UTC_TIMESTAMP())
             LIMIT 1'
        );
        $stmt->execute(['hash' => $hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new \RuntimeException('Invalid API key', 401);
        }

        $scopes = json_decode((string)$row['scopes'], true);
        if (!is_array($scopes)) {
            $scopes = [];
        }

        $context = new ApiContext(
            (int)$row['app_id'],
            (string)$row['slug'],
            (string)$row['name'],
            (int)$row['api_key_id'],
            $scopes
        );

        self::$lastContext = $context;

        if ($requiredScope !== null && !$context->can($requiredScope)) {
            throw new \RuntimeException('API key does not have required scope: ' . $requiredScope, 403);
        }

        $update = $pdo->prepare('UPDATE treasury_api_keys SET last_used_at = UTC_TIMESTAMP() WHERE id = :id');
        $update->execute(['id' => $context->apiKeyId]);

        return $context;
    }
}
