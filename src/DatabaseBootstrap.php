<?php

declare(strict_types=1);

namespace Treasury;

use PDO;
use PDOException;
use Treasury\Support\Env;

final class DatabaseBootstrap
{
    private const SCHEMA_VERSION = '2026.06.27.per-admin-payables';

    private const SYSTEM_APPS = [
        [
            'name' => 'Manual Entry',
            'slug' => 'manual_admin',
            'description' => 'Internal source app for transactions created directly in the Treasury web UI.',
        ],
    ];

    private const SYSTEM_ACCOUNTS = [
        ['code' => '1000', 'name' => 'Official Treasury', 'account_type' => 'asset', 'normal_balance' => 'debit', 'parent_code' => null],
        ['code' => '1100', 'name' => 'Admin Funds Owed to Treasury', 'account_type' => 'asset', 'normal_balance' => 'debit', 'parent_code' => null],
        ['code' => '2000', 'name' => 'Admin Funds Owed by Treasury', 'account_type' => 'liability', 'normal_balance' => 'credit', 'parent_code' => null],
        ['code' => '3000', 'name' => 'Opening Balance Equity', 'account_type' => 'equity', 'normal_balance' => 'credit', 'parent_code' => null],
        ['code' => '4000', 'name' => 'Revenue', 'account_type' => 'income', 'normal_balance' => 'credit', 'parent_code' => null],
        ['code' => '5000', 'name' => 'Expenses', 'account_type' => 'expense', 'normal_balance' => 'debit', 'parent_code' => null],
    ];

    public static function run(bool $force = false): void
    {
        if (!Env::bool('DB_BOOTSTRAP_ENABLED', true) && !$force) {
            return;
        }

        self::ensureDatabaseExistsIfAllowed();

        $pdo = Database::pdo();
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

        $lockName = 'rs3_gp_treasury_db_bootstrap';
        $lock = $pdo->prepare('SELECT GET_LOCK(:name, 10)');
        $lock->execute(['name' => $lockName]);
        if ((int)$lock->fetchColumn() !== 1) {
            throw new \RuntimeException('Could not acquire database bootstrap lock.');
        }

        try {
            self::ensureSchemaStateTable($pdo);

            if (!$force && !Env::bool('DB_BOOTSTRAP_RUN_EVERY_REQUEST', false) && self::schemaIsCurrent($pdo)) {
                return;
            }

            self::createTables($pdo);
            self::syncColumns($pdo);
            self::syncIndexes($pdo);
            self::syncRequiredSystemRecords($pdo);
            self::markCurrent($pdo);
        } finally {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
            $release->execute(['name' => $lockName]);
        }
    }

    private static function ensureDatabaseExistsIfAllowed(): void
    {
        if (!Env::bool('DB_BOOTSTRAP_CREATE_DATABASE', false)) {
            return;
        }

        $database = Env::get('DB_NAME', 'rs3_gp_treasury');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $database)) {
            throw new \RuntimeException('DB_NAME may only contain letters, numbers and underscores when DB_BOOTSTRAP_CREATE_DATABASE is enabled.');
        }

        $pdo = Database::serverPdo();
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $database) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    private static function schemaIsCurrent(PDO $pdo): bool
    {
        try {
            $stmt = $pdo->prepare('SELECT value FROM treasury_schema_state WHERE name = "schema_version" LIMIT 1');
            $stmt->execute();
            return (string)$stmt->fetchColumn() === self::SCHEMA_VERSION;
        } catch (PDOException) {
            return false;
        }
    }

    private static function markCurrent(PDO $pdo): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO treasury_schema_state (name, value, updated_at)
             VALUES ("schema_version", :version, NOW())
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()'
        );
        $stmt->execute(['version' => self::SCHEMA_VERSION]);
    }

    private static function ensureSchemaStateTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS treasury_schema_state (
                name VARCHAR(100) NOT NULL PRIMARY KEY,
                value VARCHAR(255) NOT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private static function createTables(PDO $pdo): void
    {
        foreach (self::tableDefinitions() as $sql) {
            $pdo->exec($sql);
        }
    }

    /**
     * @return list<string>
     */
    private static function tableDefinitions(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS treasury_apps (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                slug VARCHAR(100) NOT NULL UNIQUE,
                description TEXT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

            'CREATE TABLE IF NOT EXISTS treasury_api_keys (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                app_id BIGINT UNSIGNED NOT NULL,
                key_name VARCHAR(100) NOT NULL,
                key_hash CHAR(64) NOT NULL UNIQUE,
                scopes JSON NOT NULL,
                last_used_at DATETIME NULL,
                expires_at DATETIME NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_treasury_api_keys_app (app_id),
                INDEX idx_treasury_api_keys_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

            'CREATE TABLE IF NOT EXISTS treasury_admins (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                discord_user_id VARCHAR(32) NULL,
                rsn VARCHAR(20) NOT NULL,
                display_name VARCHAR(100) NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                INDEX idx_treasury_admins_rsn (rsn),
                INDEX idx_treasury_admins_discord (discord_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

            'CREATE TABLE IF NOT EXISTS treasury_admin_rsn_history (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                admin_id BIGINT UNSIGNED NOT NULL,
                rsn VARCHAR(20) NOT NULL,
                effective_from DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                effective_to DATETIME NULL,
                is_current TINYINT(1) NOT NULL DEFAULT 1,
                changed_by_admin_id BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_treasury_admin_rsn_history_admin (admin_id),
                INDEX idx_treasury_admin_rsn_history_rsn (rsn),
                INDEX idx_treasury_admin_rsn_history_current (is_current)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

            'CREATE TABLE IF NOT EXISTS treasury_admin_rsns (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                admin_id BIGINT UNSIGNED NOT NULL,
                rsn VARCHAR(20) NOT NULL,
                is_primary TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                UNIQUE KEY unique_treasury_admin_rsn (admin_id, rsn),
                INDEX idx_treasury_admin_rsns_admin (admin_id),
                INDEX idx_treasury_admin_rsns_rsn (rsn),
                INDEX idx_treasury_admin_rsns_primary (is_primary),
                INDEX idx_treasury_admin_rsns_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

            'CREATE TABLE IF NOT EXISTS treasury_accounts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(50) NOT NULL UNIQUE,
                name VARCHAR(150) NOT NULL,
                account_type ENUM("asset","income","expense","liability","equity","clearing") NOT NULL,
                parent_account_id BIGINT UNSIGNED NULL,
                admin_id BIGINT UNSIGNED NULL,
                app_id BIGINT UNSIGNED NULL,
                normal_balance ENUM("debit","credit") NOT NULL,
                is_system TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_treasury_accounts_type (account_type),
                INDEX idx_treasury_accounts_admin (admin_id),
                INDEX idx_treasury_accounts_app (app_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

            'CREATE TABLE IF NOT EXISTS treasury_transactions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                transaction_uuid CHAR(36) NOT NULL UNIQUE,
                app_id BIGINT UNSIGNED NULL,
                source_type VARCHAR(100) NULL,
                source_id VARCHAR(100) NULL,
                transaction_type ENUM("entry_fee","contribution","prize_payout","expense","admin_reimbursement","reconciliation","adjustment","reversal") NOT NULL,
                status ENUM("draft","posted","voided","reversed") NOT NULL DEFAULT "posted",
                description VARCHAR(255) NOT NULL,
                notes TEXT NULL,
                amount BIGINT UNSIGNED NOT NULL,
                occurred_at DATETIME NOT NULL,
                posted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                posted_by_admin_id BIGINT UNSIGNED NULL,
                related_transaction_id BIGINT UNSIGNED NULL,
                metadata JSON NULL,
                UNIQUE KEY unique_source_transaction (app_id, source_type, source_id),
                INDEX idx_treasury_transactions_type (transaction_type),
                INDEX idx_treasury_transactions_status (status),
                INDEX idx_treasury_transactions_occurred (occurred_at),
                INDEX idx_treasury_transactions_app_source (app_id, source_type, source_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

            'CREATE TABLE IF NOT EXISTS treasury_ledger_entries (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                transaction_id BIGINT UNSIGNED NOT NULL,
                account_id BIGINT UNSIGNED NOT NULL,
                direction ENUM("debit","credit") NOT NULL,
                amount BIGINT UNSIGNED NOT NULL,
                admin_id BIGINT UNSIGNED NULL,
                player_rsn VARCHAR(20) NULL,
                memo VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_treasury_ledger_transaction (transaction_id),
                INDEX idx_treasury_ledger_account (account_id),
                INDEX idx_treasury_ledger_admin (admin_id),
                INDEX idx_treasury_ledger_player (player_rsn)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

            'CREATE TABLE IF NOT EXISTS treasury_payment_requests (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                request_uuid CHAR(36) NOT NULL UNIQUE,
                app_id BIGINT UNSIGNED NOT NULL,
                source_type VARCHAR(100) NOT NULL,
                source_id VARCHAR(100) NOT NULL,
                player_rsn VARCHAR(20) NOT NULL,
                amount BIGINT UNSIGNED NOT NULL,
                purpose ENUM("entry_fee","clan_contribution","other") NOT NULL DEFAULT "other",
                description VARCHAR(255) NOT NULL,
                revenue_account_id BIGINT UNSIGNED NULL,
                status ENUM("pending","received_by_admin","reconciled_to_treasury","cancelled") NOT NULL DEFAULT "pending",
                received_by_admin_id BIGINT UNSIGNED NULL,
                received_transaction_id BIGINT UNSIGNED NULL,
                reconciliation_transaction_id BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                received_at DATETIME NULL,
                reconciled_at DATETIME NULL,
                metadata JSON NULL,
                UNIQUE KEY unique_payment_request (app_id, source_type, source_id),
                INDEX idx_treasury_payment_status (status),
                INDEX idx_treasury_payment_player (player_rsn),
                INDEX idx_treasury_payment_source (app_id, source_type, source_id),
                INDEX idx_treasury_payment_revenue_account (revenue_account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

            'CREATE TABLE IF NOT EXISTS treasury_payout_requests (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                request_uuid CHAR(36) NOT NULL UNIQUE,
                app_id BIGINT UNSIGNED NOT NULL,
                source_type VARCHAR(100) NOT NULL,
                source_id VARCHAR(100) NOT NULL,
                payee_rsn VARCHAR(20) NOT NULL,
                amount BIGINT UNSIGNED NOT NULL,
                payout_type ENUM("prize","expense","admin_reimbursement") NOT NULL DEFAULT "expense",
                description VARCHAR(255) NOT NULL,
                expense_account_id BIGINT UNSIGNED NULL,
                status ENUM("pending","paid_from_treasury","paid_by_admin","reimbursed","cancelled") NOT NULL DEFAULT "pending",
                paid_by_admin_id BIGINT UNSIGNED NULL,
                paid_transaction_id BIGINT UNSIGNED NULL,
                reimbursement_transaction_id BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                paid_at DATETIME NULL,
                reimbursed_at DATETIME NULL,
                metadata JSON NULL,
                UNIQUE KEY unique_payout_request (app_id, source_type, source_id),
                INDEX idx_treasury_payout_status (status),
                INDEX idx_treasury_payout_payee (payee_rsn),
                INDEX idx_treasury_payout_source (app_id, source_type, source_id),
                INDEX idx_treasury_payout_expense_account (expense_account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

            'CREATE TABLE IF NOT EXISTS treasury_reconciliations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                reconciliation_uuid CHAR(36) NOT NULL UNIQUE,
                from_admin_id BIGINT UNSIGNED NOT NULL,
                amount BIGINT UNSIGNED NOT NULL,
                status ENUM("pending","completed","cancelled") NOT NULL DEFAULT "pending",
                transaction_id BIGINT UNSIGNED NULL,
                notes TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                completed_at DATETIME NULL,
                created_by_admin_id BIGINT UNSIGNED NULL,
                completed_by_admin_id BIGINT UNSIGNED NULL,
                INDEX idx_treasury_recon_status (status),
                INDEX idx_treasury_recon_admin (from_admin_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

            'CREATE TABLE IF NOT EXISTS treasury_idempotency_keys (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                app_id BIGINT UNSIGNED NOT NULL,
                idempotency_key VARCHAR(180) NOT NULL,
                request_hash CHAR(64) NOT NULL,
                response_code SMALLINT UNSIGNED NOT NULL,
                response_body JSON NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_app_idempotency_key (app_id, idempotency_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

            'CREATE TABLE IF NOT EXISTS treasury_audit_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                actor_admin_id BIGINT UNSIGNED NULL,
                actor_app_id BIGINT UNSIGNED NULL,
                action VARCHAR(100) NOT NULL,
                entity_type VARCHAR(100) NOT NULL,
                entity_id VARCHAR(100) NOT NULL,
                before_json JSON NULL,
                after_json JSON NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_treasury_audit_entity (entity_type, entity_id),
                INDEX idx_treasury_audit_action (action),
                INDEX idx_treasury_audit_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ];
    }

    private static function syncColumns(PDO $pdo): void
    {
        $columns = [
            'treasury_apps' => [
                'updated_at' => 'DATETIME NULL',
            ],
            'treasury_admins' => [
                'updated_at' => 'DATETIME NULL',
            ],
            'treasury_admin_rsns' => [
                'updated_at' => 'DATETIME NULL',
                'is_primary' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
            ],
            'treasury_accounts' => [
                'normal_balance' => 'ENUM("debit","credit") NOT NULL DEFAULT "debit"',
                'is_system' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
                'parent_account_id' => 'BIGINT UNSIGNED NULL',
                'admin_id' => 'BIGINT UNSIGNED NULL',
                'app_id' => 'BIGINT UNSIGNED NULL',
            ],
            'treasury_payment_requests' => [
                'description' => 'VARCHAR(255) NOT NULL DEFAULT "Money in"',
                'revenue_account_id' => 'BIGINT UNSIGNED NULL',
                'received_by_admin_id' => 'BIGINT UNSIGNED NULL',
                'metadata' => 'JSON NULL',
            ],
            'treasury_payout_requests' => [
                'description' => 'VARCHAR(255) NOT NULL DEFAULT "Money out"',
                'expense_account_id' => 'BIGINT UNSIGNED NULL',
                'metadata' => 'JSON NULL',
            ],
            'treasury_transactions' => [
                'metadata' => 'JSON NULL',
                'related_transaction_id' => 'BIGINT UNSIGNED NULL',
            ],
        ];

        foreach ($columns as $table => $definitions) {
            foreach ($definitions as $column => $definition) {
                self::addColumnIfMissing($pdo, $table, $column, $definition);
            }
        }
    }

    private static function syncIndexes(PDO $pdo): void
    {
        $indexes = [
            ['treasury_api_keys', 'idx_treasury_api_keys_app', 'app_id'],
            ['treasury_api_keys', 'idx_treasury_api_keys_active', 'is_active'],
            ['treasury_admins', 'idx_treasury_admins_rsn', 'rsn'],
            ['treasury_admins', 'idx_treasury_admins_discord', 'discord_user_id'],
            ['treasury_admin_rsn_history', 'idx_treasury_admin_rsn_history_admin', 'admin_id'],
            ['treasury_admin_rsn_history', 'idx_treasury_admin_rsn_history_rsn', 'rsn'],
            ['treasury_admin_rsn_history', 'idx_treasury_admin_rsn_history_current', 'is_current'],
            ['treasury_admin_rsns', 'idx_treasury_admin_rsns_admin', 'admin_id'],
            ['treasury_admin_rsns', 'idx_treasury_admin_rsns_rsn', 'rsn'],
            ['treasury_admin_rsns', 'idx_treasury_admin_rsns_primary', 'is_primary'],
            ['treasury_admin_rsns', 'idx_treasury_admin_rsns_active', 'is_active'],
            ['treasury_accounts', 'idx_treasury_accounts_type', 'account_type'],
            ['treasury_accounts', 'idx_treasury_accounts_admin', 'admin_id'],
            ['treasury_accounts', 'idx_treasury_accounts_app', 'app_id'],
            ['treasury_transactions', 'idx_treasury_transactions_type', 'transaction_type'],
            ['treasury_transactions', 'idx_treasury_transactions_status', 'status'],
            ['treasury_transactions', 'idx_treasury_transactions_occurred', 'occurred_at'],
            ['treasury_ledger_entries', 'idx_treasury_ledger_transaction', 'transaction_id'],
            ['treasury_ledger_entries', 'idx_treasury_ledger_account', 'account_id'],
            ['treasury_payment_requests', 'idx_treasury_payment_revenue_account', 'revenue_account_id'],
            ['treasury_payout_requests', 'idx_treasury_payout_expense_account', 'expense_account_id'],
            ['treasury_audit_log', 'idx_treasury_audit_created', 'created_at'],
        ];

        foreach ($indexes as [$table, $index, $columnList]) {
            self::addIndexIfMissing($pdo, $table, $index, $columnList);
        }
    }

    private static function syncRequiredSystemRecords(PDO $pdo): void
    {
        foreach (self::SYSTEM_APPS as $app) {
            $stmt = $pdo->prepare(
                'INSERT INTO treasury_apps (name, slug, description, is_active)
                 VALUES (:name, :slug, :description, 1)
                 ON DUPLICATE KEY UPDATE
                     name = VALUES(name),
                     description = VALUES(description),
                     is_active = 1,
                     updated_at = NOW()'
            );
            $stmt->execute($app);
        }

        self::syncAdminRsnHistory($pdo);

        foreach (self::SYSTEM_ACCOUNTS as $account) {
            $stmt = $pdo->prepare(
                'INSERT INTO treasury_accounts (code, name, account_type, normal_balance, is_system, is_active)
                 VALUES (:code, :name, :account_type, :normal_balance, 1, 1)
                 ON DUPLICATE KEY UPDATE
                     name = VALUES(name),
                     account_type = VALUES(account_type),
                     normal_balance = VALUES(normal_balance),
                     is_system = 1,
                     is_active = 1'
            );
            $stmt->execute([
                'code' => $account['code'],
                'name' => $account['name'],
                'account_type' => $account['account_type'],
                'normal_balance' => $account['normal_balance'],
            ]);
        }

        self::syncPerAdminSystemAccounts($pdo);
    }

    private static function syncPerAdminSystemAccounts(PDO $pdo): void
    {
        $heldParentId = self::accountIdByCode($pdo, '1100');
        $payableParentId = self::accountIdByCode($pdo, '2000');
        $admins = $pdo->query('SELECT id, rsn, display_name FROM treasury_admins')->fetchAll(PDO::FETCH_ASSOC);

        foreach ($admins as $admin) {
            $adminId = (int)$admin['id'];
            $label = ((string)($admin['display_name'] ?? '') ?: (string)($admin['rsn'] ?? ''));

            $stmt = $pdo->prepare(
                'INSERT INTO treasury_accounts (code, name, account_type, parent_account_id, admin_id, normal_balance, is_system, is_active)
                 VALUES (:code, :name, "asset", :parent_id, :admin_id, "debit", 1, 1)
                 ON DUPLICATE KEY UPDATE name = VALUES(name), parent_account_id = VALUES(parent_account_id), admin_id = VALUES(admin_id), is_system = 1, is_active = 1'
            );
            $stmt->execute([
                'code' => '1100:' . $adminId,
                'name' => 'Funds Owed by Admin - ' . $label,
                'parent_id' => $heldParentId,
                'admin_id' => $adminId,
            ]);

            $stmt = $pdo->prepare(
                'INSERT INTO treasury_accounts (code, name, account_type, parent_account_id, admin_id, normal_balance, is_system, is_active)
                 VALUES (:code, :name, "liability", :parent_id, :admin_id, "credit", 1, 1)
                 ON DUPLICATE KEY UPDATE name = VALUES(name), parent_account_id = VALUES(parent_account_id), admin_id = VALUES(admin_id), is_system = 1, is_active = 1'
            );
            $stmt->execute([
                'code' => '2000:' . $adminId,
                'name' => 'Funds Owed to Admin - ' . $label,
                'parent_id' => $payableParentId,
                'admin_id' => $adminId,
            ]);
        }
    }

    private static function accountIdByCode(PDO $pdo, string $code): int
    {
        $stmt = $pdo->prepare('SELECT id FROM treasury_accounts WHERE code = :code LIMIT 1');
        $stmt->execute(['code' => $code]);
        $id = $stmt->fetchColumn();
        if (!$id) {
            throw new \RuntimeException('Required treasury account missing: ' . $code);
        }
        return (int)$id;
    }

    private static function syncAdminRsnHistory(PDO $pdo): void
    {
        $admins = $pdo->query('SELECT id, rsn FROM treasury_admins')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($admins as $admin) {
            $adminId = (int)$admin['id'];
            $rsn = (string)$admin['rsn'];

            $stmt = $pdo->prepare('SELECT COUNT(*) FROM treasury_admin_rsn_history WHERE admin_id = :admin_id');
            $stmt->execute(['admin_id' => $adminId]);
            if ((int)$stmt->fetchColumn() === 0) {
                $stmt = $pdo->prepare(
                    'INSERT INTO treasury_admin_rsn_history (admin_id, rsn, effective_from, effective_to, is_current, changed_by_admin_id, created_at)
                     VALUES (:admin_id, :rsn, NOW(), NULL, 1, NULL, NOW())'
                );
                $stmt->execute([
                    'admin_id' => $adminId,
                    'rsn' => $rsn,
                ]);
            }

            $stmt = $pdo->prepare('SELECT COUNT(*) FROM treasury_admin_rsns WHERE admin_id = :admin_id');
            $stmt->execute(['admin_id' => $adminId]);
            if ((int)$stmt->fetchColumn() === 0) {
                $stmt = $pdo->prepare(
                    'INSERT INTO treasury_admin_rsns (admin_id, rsn, is_primary, is_active, created_at, updated_at)
                     VALUES (:admin_id, :rsn, 1, 1, NOW(), NOW())'
                );
                $stmt->execute([
                    'admin_id' => $adminId,
                    'rsn' => $rsn,
                ]);
            } else {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM treasury_admin_rsns WHERE admin_id = :admin_id AND is_primary = 1');
                $stmt->execute(['admin_id' => $adminId]);
                if ((int)$stmt->fetchColumn() === 0) {
                    $stmt = $pdo->prepare('UPDATE treasury_admin_rsns SET is_primary = 1, is_active = 1, updated_at = NOW() WHERE admin_id = :admin_id AND LOWER(rsn) = LOWER(:rsn) LIMIT 1');
                    $stmt->execute(['admin_id' => $adminId, 'rsn' => $rsn]);
                    if ($stmt->rowCount() === 0) {
                        $stmt = $pdo->prepare(
                            'INSERT IGNORE INTO treasury_admin_rsns (admin_id, rsn, is_primary, is_active, created_at, updated_at)
                             VALUES (:admin_id, :rsn, 1, 1, NOW(), NOW())'
                        );
                        $stmt->execute(['admin_id' => $adminId, 'rsn' => $rsn]);
                    }
                }
            }
        }
    }

    private static function addColumnIfMissing(PDO $pdo, string $table, string $column, string $definition): void
    {
        if (self::columnExists($pdo, $table, $column)) {
            return;
        }

        $pdo->exec('ALTER TABLE `' . self::safeIdentifier($table) . '` ADD COLUMN `' . self::safeIdentifier($column) . '` ' . $definition);
    }

    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND COLUMN_NAME = :column'
        );
        $stmt->execute(['table' => $table, 'column' => $column]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private static function addIndexIfMissing(PDO $pdo, string $table, string $index, string $columnList): void
    {
        if (self::indexExists($pdo, $table, $index)) {
            return;
        }

        $pdo->exec('ALTER TABLE `' . self::safeIdentifier($table) . '` ADD INDEX `' . self::safeIdentifier($index) . '` (' . $columnList . ')');
    }

    private static function indexExists(PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND INDEX_NAME = :index_name'
        );
        $stmt->execute(['table' => $table, 'index_name' => $index]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private static function safeIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new \InvalidArgumentException('Unsafe SQL identifier: ' . $identifier);
        }
        return $identifier;
    }
}
