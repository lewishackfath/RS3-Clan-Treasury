#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use Treasury\Database;
use Treasury\Support\Env;

$pdo = Database::pdo();
$dbName = Env::get('DB_NAME', 'rs3_gp_treasury');

function columnExists(PDO $pdo, string $dbName, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table AND COLUMN_NAME = :column'
    );
    $stmt->execute(['db' => $dbName, 'table' => $table, 'column' => $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function indexExists(PDO $pdo, string $dbName, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table AND INDEX_NAME = :index_name'
    );
    $stmt->execute(['db' => $dbName, 'table' => $table, 'index_name' => $index]);
    return (int)$stmt->fetchColumn() > 0;
}

function fkExists(PDO $pdo, string $dbName, string $table, string $constraint): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = :db
           AND TABLE_NAME = :table
           AND CONSTRAINT_NAME = :constraint_name
           AND CONSTRAINT_TYPE = "FOREIGN KEY"'
    );
    $stmt->execute(['db' => $dbName, 'table' => $table, 'constraint_name' => $constraint]);
    return (int)$stmt->fetchColumn() > 0;
}

function execStep(PDO $pdo, string $label, string $sql): void
{
    echo $label . "... ";
    $pdo->exec($sql);
    echo "done\n";
}

try {
    $pdo->beginTransaction();

    if (!columnExists($pdo, $dbName, 'treasury_payment_requests', 'revenue_account_id')) {
        execStep($pdo, 'Adding treasury_payment_requests.revenue_account_id',
            'ALTER TABLE treasury_payment_requests ADD COLUMN revenue_account_id BIGINT UNSIGNED NULL AFTER description'
        );
    } else {
        echo "treasury_payment_requests.revenue_account_id already exists\n";
    }

    if (!columnExists($pdo, $dbName, 'treasury_payout_requests', 'expense_account_id')) {
        execStep($pdo, 'Adding treasury_payout_requests.expense_account_id',
            'ALTER TABLE treasury_payout_requests ADD COLUMN expense_account_id BIGINT UNSIGNED NULL AFTER description'
        );
    } else {
        echo "treasury_payout_requests.expense_account_id already exists\n";
    }

    execStep($pdo, 'Renaming manual source app', <<<'SQL'
UPDATE treasury_apps
SET name = 'Manual Entry', description = 'Manual treasury administration actions'
WHERE slug = 'manual_admin'
SQL);

    execStep($pdo, 'Backfilling payment request revenue accounts', <<<'SQL'
UPDATE treasury_payment_requests pr
JOIN treasury_accounts acc ON acc.code = CASE
    WHEN pr.purpose = 'clan_contribution' THEN '4300'
    WHEN pr.purpose = 'entry_fee' THEN '4100'
    ELSE '4300'
END
SET pr.revenue_account_id = acc.id
WHERE pr.revenue_account_id IS NULL
SQL);

    execStep($pdo, 'Backfilling payout request expense accounts', <<<'SQL'
UPDATE treasury_payout_requests pr
JOIN treasury_accounts acc ON acc.code = CASE
    WHEN pr.payout_type = 'prize' THEN '5100'
    ELSE '6000'
END
SET pr.expense_account_id = acc.id
WHERE pr.expense_account_id IS NULL
SQL);

    if (!indexExists($pdo, $dbName, 'treasury_payment_requests', 'idx_treasury_payment_revenue_account')) {
        execStep($pdo, 'Adding payment request revenue index',
            'ALTER TABLE treasury_payment_requests ADD INDEX idx_treasury_payment_revenue_account (revenue_account_id)'
        );
    } else {
        echo "idx_treasury_payment_revenue_account already exists\n";
    }

    if (!indexExists($pdo, $dbName, 'treasury_payout_requests', 'idx_treasury_payout_expense_account')) {
        execStep($pdo, 'Adding payout request expense index',
            'ALTER TABLE treasury_payout_requests ADD INDEX idx_treasury_payout_expense_account (expense_account_id)'
        );
    } else {
        echo "idx_treasury_payout_expense_account already exists\n";
    }

    if (!fkExists($pdo, $dbName, 'treasury_payment_requests', 'fk_treasury_payment_revenue_account')) {
        execStep($pdo, 'Adding payment request revenue foreign key',
            'ALTER TABLE treasury_payment_requests ADD CONSTRAINT fk_treasury_payment_revenue_account FOREIGN KEY (revenue_account_id) REFERENCES treasury_accounts(id)'
        );
    } else {
        echo "fk_treasury_payment_revenue_account already exists\n";
    }

    if (!fkExists($pdo, $dbName, 'treasury_payout_requests', 'fk_treasury_payout_expense_account')) {
        execStep($pdo, 'Adding payout request expense foreign key',
            'ALTER TABLE treasury_payout_requests ADD CONSTRAINT fk_treasury_payout_expense_account FOREIGN KEY (expense_account_id) REFERENCES treasury_accounts(id)'
        );
    } else {
        echo "fk_treasury_payout_expense_account already exists\n";
    }

    $pdo->commit();
    echo "\nChart of Accounts repair completed successfully. Default starter accounts are no longer auto-created; manage custom GL accounts from the web UI.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "\nRepair failed: " . $e->getMessage() . "\n");
    exit(1);
}
