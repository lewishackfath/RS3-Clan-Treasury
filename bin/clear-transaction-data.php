#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use Treasury\Database;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$options = [
    'dry-run' => in_array('--dry-run', $argv, true),
    'force' => in_array('--force', $argv, true) || in_array('--yes', $argv, true),
    'keep-api-key-last-used' => in_array('--keep-api-key-last-used', $argv, true),
];

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo <<<TXT
Usage:
  php bin/clear-transaction-data.php --dry-run
  php bin/clear-transaction-data.php --force

Options:
  --dry-run                  Show what would be cleared without deleting anything.
  --force, --yes             Run without the interactive CLEAR confirmation.
  --keep-api-key-last-used   Do not reset treasury_api_keys.last_used_at.
  --help                     Show this help text.

This clears transaction/request/log data only. It does not delete users, RSNs,
accounts, integrations/source apps, API keys, Discord settings, or app settings.

TXT;
    exit(0);
}

$pdo = Database::pdo();

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
           FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = :table'
    );
    $stmt->execute(['table' => $table]);
    return (int)$stmt->fetchColumn() > 0;
}

function tableCount(PDO $pdo, string $table): int
{
    if (!tableExists($pdo, $table)) {
        return 0;
    }
    $stmt = $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`');
    return (int)$stmt->fetchColumn();
}

$tablesToClear = [
    // Logs/cache first.
    'treasury_api_request_logs',
    'treasury_audit_log',
    'treasury_idempotency_keys',

    // Offset/reconciliation/request data.
    'treasury_request_settlements',
    'treasury_ledger_entries',
    'treasury_payment_requests',
    'treasury_payout_requests',
    'treasury_reconciliations',

    // Ledger transaction headers last.
    'treasury_transactions',
];

$counts = [];
$totalRows = 0;
foreach ($tablesToClear as $table) {
    if (!tableExists($pdo, $table)) {
        $counts[$table] = null;
        continue;
    }
    $count = tableCount($pdo, $table);
    $counts[$table] = $count;
    $totalRows += $count;
}

$resetApiKeyLastUsed = !$options['keep-api-key-last-used'] && tableExists($pdo, 'treasury_api_keys');
$apiKeysWithLastUsed = 0;
if ($resetApiKeyLastUsed) {
    $stmt = $pdo->query('SELECT COUNT(*) FROM treasury_api_keys WHERE last_used_at IS NOT NULL');
    $apiKeysWithLastUsed = (int)$stmt->fetchColumn();
}

echo "RS3 GP Treasury transaction/log data cleanup\n";
echo "================================================\n\n";
echo "The following data will be cleared:\n";
foreach ($counts as $table => $count) {
    if ($count === null) {
        echo "  - {$table}: table not present, skipped\n";
    } else {
        echo "  - {$table}: " . number_format($count) . " rows\n";
    }
}
if ($resetApiKeyLastUsed) {
    echo "  - treasury_api_keys.last_used_at: " . number_format($apiKeysWithLastUsed) . " values reset\n";
}

echo "\nThis will NOT delete:\n";
echo "  - treasury users/admins and RSNs\n";
echo "  - chart of accounts / GL accounts\n";
echo "  - integrations/source apps\n";
echo "  - API keys themselves\n";
echo "  - Discord/app settings\n";
echo "  - DB bootstrap/schema state\n\n";

if ($options['dry-run']) {
    echo "Dry run only. No data was changed.\n";
    exit(0);
}

if (!$options['force']) {
    echo "Type CLEAR to permanently delete the transaction/log rows listed above: ";
    $handle = fopen('php://stdin', 'r');
    $confirmation = $handle ? trim((string)fgets($handle)) : '';
    if ($confirmation !== 'CLEAR') {
        echo "Aborted. No data was changed.\n";
        exit(1);
    }
}

try {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

    foreach ($tablesToClear as $table) {
        if (!tableExists($pdo, $table)) {
            continue;
        }
        $safeTable = '`' . str_replace('`', '``', $table) . '`';
        $pdo->exec('DELETE FROM ' . $safeTable);
        $pdo->exec('ALTER TABLE ' . $safeTable . ' AUTO_INCREMENT = 1');
    }

    if ($resetApiKeyLastUsed) {
        $pdo->exec('UPDATE treasury_api_keys SET last_used_at = NULL');
    }
} finally {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

echo "\nCleanup complete. Removed " . number_format($totalRows) . " transaction/log rows.";
if ($resetApiKeyLastUsed) {
    echo ' Reset ' . number_format($apiKeysWithLastUsed) . " API key last-used values.";
}
echo "\n";
