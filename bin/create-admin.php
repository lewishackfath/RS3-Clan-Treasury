#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use Treasury\Database;
use Treasury\Services\AccountService;

if ($argc < 3) {
    fwrite(STDERR, "Usage: php bin/create-admin.php <rsn> <display_name> [discord_user_id]\n");
    exit(1);
}

$rsn = $argv[1];
$displayName = $argv[2];
$discordUserId = $argv[3] ?? null;

$pdo = Database::pdo();
$stmt = $pdo->prepare(
    'INSERT INTO treasury_admins (rsn, display_name, discord_user_id, is_active)
     VALUES (:rsn, :display_name, :discord_user_id, 1)'
);
$stmt->execute([
    'rsn' => $rsn,
    'display_name' => $displayName,
    'discord_user_id' => $discordUserId,
]);

$adminId = (int)$pdo->lastInsertId();
$accountId = (new AccountService())->ensureAdminHeldAccount($adminId);

echo "Admin created: {$displayName} ({$rsn})\n";
echo "admin_id={$adminId}\n";
echo "admin_held_account_id={$accountId}\n";
