#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use Treasury\Database;

if ($argc < 3) {
    fwrite(STDERR, "Usage: php bin/create-app.php <slug> <name> [description]\n");
    exit(1);
}

$slug = $argv[1];
$name = $argv[2];
$description = $argv[3] ?? null;

if (!preg_match('/^[a-z0-9_\-]+$/', $slug)) {
    fwrite(STDERR, "Slug may only contain lowercase letters, numbers, underscores, and dashes.\n");
    exit(1);
}

$pdo = Database::pdo();
$stmt = $pdo->prepare(
    'INSERT INTO treasury_apps (slug, name, description, is_active)
     VALUES (:slug, :name, :description, 1)
     ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), is_active = 1'
);
$stmt->execute([
    'slug' => $slug,
    'name' => $name,
    'description' => $description,
]);

echo "App ready: {$slug} ({$name})\n";
