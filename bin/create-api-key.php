#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use Treasury\Database;

if ($argc < 4) {
    fwrite(STDERR, "Usage: php bin/create-api-key.php <app_slug> <key_name> <comma_scopes> [expires_at_utc]\n");
    fwrite(STDERR, "Example: php bin/create-api-key.php bingo \"Bingo Production\" \"payments:create,payouts:create,transactions:read\"\n");
    exit(1);
}

$appSlug = $argv[1];
$keyName = $argv[2];
$scopes = array_values(array_filter(array_map('trim', explode(',', $argv[3]))));
$expiresAt = $argv[4] ?? null;

$pdo = Database::pdo();
$stmt = $pdo->prepare('SELECT id FROM treasury_apps WHERE slug = :slug AND is_active = 1 LIMIT 1');
$stmt->execute(['slug' => $appSlug]);
$appId = $stmt->fetchColumn();

if (!$appId) {
    fwrite(STDERR, "Active app not found: {$appSlug}\n");
    exit(1);
}

$rawKey = 'trsy_' . bin2hex(random_bytes(32));
$keyHash = hash('sha256', $rawKey);

$insert = $pdo->prepare(
    'INSERT INTO treasury_api_keys (app_id, key_name, key_hash, scopes, expires_at, is_active)
     VALUES (:app_id, :key_name, :key_hash, :scopes, :expires_at, 1)'
);
$insert->execute([
    'app_id' => (int)$appId,
    'key_name' => $keyName,
    'key_hash' => $keyHash,
    'scopes' => json_encode($scopes, JSON_UNESCAPED_SLASHES),
    'expires_at' => $expiresAt,
]);

echo "API key created for {$appSlug}. Store this now; it will not be shown again.\n";
echo $rawKey . "\n";
