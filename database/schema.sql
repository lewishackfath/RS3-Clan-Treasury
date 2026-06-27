CREATE TABLE treasury_apps (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE treasury_api_keys (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    app_id BIGINT UNSIGNED NOT NULL,
    key_name VARCHAR(100) NOT NULL,
    key_hash CHAR(64) NOT NULL UNIQUE,
    scopes JSON NOT NULL,
    last_used_at DATETIME NULL,
    expires_at DATETIME NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (app_id) REFERENCES treasury_apps(id),
    INDEX idx_treasury_api_keys_app (app_id),
    INDEX idx_treasury_api_keys_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE treasury_admins (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    discord_user_id VARCHAR(32) NULL,
    rsn VARCHAR(20) NOT NULL,
    display_name VARCHAR(100) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    INDEX idx_treasury_admins_rsn (rsn),
    INDEX idx_treasury_admins_discord (discord_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE treasury_admin_rsn_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id BIGINT UNSIGNED NOT NULL,
    rsn VARCHAR(20) NOT NULL,
    effective_from DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    effective_to DATETIME NULL,
    is_current TINYINT(1) NOT NULL DEFAULT 1,
    changed_by_admin_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES treasury_admins(id),
    FOREIGN KEY (changed_by_admin_id) REFERENCES treasury_admins(id),
    INDEX idx_treasury_admin_rsn_history_admin (admin_id),
    INDEX idx_treasury_admin_rsn_history_rsn (rsn),
    INDEX idx_treasury_admin_rsn_history_current (is_current)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE treasury_admin_rsns (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id BIGINT UNSIGNED NOT NULL,
    rsn VARCHAR(20) NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    FOREIGN KEY (admin_id) REFERENCES treasury_admins(id),
    UNIQUE KEY unique_treasury_admin_rsn (admin_id, rsn),
    INDEX idx_treasury_admin_rsns_admin (admin_id),
    INDEX idx_treasury_admin_rsns_rsn (rsn),
    INDEX idx_treasury_admin_rsns_primary (is_primary),
    INDEX idx_treasury_admin_rsns_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE treasury_accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    account_type ENUM('asset','income','expense','liability','equity','clearing') NOT NULL,
    parent_account_id BIGINT UNSIGNED NULL,
    admin_id BIGINT UNSIGNED NULL,
    app_id BIGINT UNSIGNED NULL,
    normal_balance ENUM('debit','credit') NOT NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_account_id) REFERENCES treasury_accounts(id),
    FOREIGN KEY (admin_id) REFERENCES treasury_admins(id),
    FOREIGN KEY (app_id) REFERENCES treasury_apps(id),
    INDEX idx_treasury_accounts_type (account_type),
    INDEX idx_treasury_accounts_admin (admin_id),
    INDEX idx_treasury_accounts_app (app_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE treasury_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transaction_uuid CHAR(36) NOT NULL UNIQUE,
    app_id BIGINT UNSIGNED NULL,
    source_type VARCHAR(100) NULL,
    source_id VARCHAR(100) NULL,
    transaction_type ENUM('entry_fee','contribution','prize_payout','expense','admin_reimbursement','reconciliation','adjustment','reversal') NOT NULL,
    status ENUM('draft','posted','voided','reversed') NOT NULL DEFAULT 'posted',
    description VARCHAR(255) NOT NULL,
    notes TEXT NULL,
    amount BIGINT UNSIGNED NOT NULL,
    occurred_at DATETIME NOT NULL,
    posted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    posted_by_admin_id BIGINT UNSIGNED NULL,
    related_transaction_id BIGINT UNSIGNED NULL,
    metadata JSON NULL,
    FOREIGN KEY (app_id) REFERENCES treasury_apps(id),
    FOREIGN KEY (posted_by_admin_id) REFERENCES treasury_admins(id),
    FOREIGN KEY (related_transaction_id) REFERENCES treasury_transactions(id),
    UNIQUE KEY unique_source_transaction (app_id, source_type, source_id),
    INDEX idx_treasury_transactions_type (transaction_type),
    INDEX idx_treasury_transactions_status (status),
    INDEX idx_treasury_transactions_occurred (occurred_at),
    INDEX idx_treasury_transactions_app_source (app_id, source_type, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE treasury_ledger_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transaction_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    direction ENUM('debit','credit') NOT NULL,
    amount BIGINT UNSIGNED NOT NULL,
    admin_id BIGINT UNSIGNED NULL,
    player_rsn VARCHAR(20) NULL,
    memo VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (transaction_id) REFERENCES treasury_transactions(id),
    FOREIGN KEY (account_id) REFERENCES treasury_accounts(id),
    FOREIGN KEY (admin_id) REFERENCES treasury_admins(id),
    INDEX idx_treasury_ledger_transaction (transaction_id),
    INDEX idx_treasury_ledger_account (account_id),
    INDEX idx_treasury_ledger_admin (admin_id),
    INDEX idx_treasury_ledger_player (player_rsn)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE treasury_payment_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_uuid CHAR(36) NOT NULL UNIQUE,
    app_id BIGINT UNSIGNED NOT NULL,
    source_type VARCHAR(100) NOT NULL,
    source_id VARCHAR(100) NOT NULL,
    player_rsn VARCHAR(20) NOT NULL,
    amount BIGINT UNSIGNED NOT NULL,
    purpose ENUM('entry_fee','clan_contribution','other') NOT NULL,
    description VARCHAR(255) NOT NULL,
    revenue_account_id BIGINT UNSIGNED NULL,
    status ENUM('pending','received_by_admin','reconciled_to_treasury','cancelled') NOT NULL DEFAULT 'pending',
    received_by_admin_id BIGINT UNSIGNED NULL,
    received_transaction_id BIGINT UNSIGNED NULL,
    reconciliation_transaction_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    received_at DATETIME NULL,
    reconciled_at DATETIME NULL,
    metadata JSON NULL,
    FOREIGN KEY (app_id) REFERENCES treasury_apps(id),
    FOREIGN KEY (revenue_account_id) REFERENCES treasury_accounts(id),
    FOREIGN KEY (received_by_admin_id) REFERENCES treasury_admins(id),
    FOREIGN KEY (received_transaction_id) REFERENCES treasury_transactions(id),
    FOREIGN KEY (reconciliation_transaction_id) REFERENCES treasury_transactions(id),
    UNIQUE KEY unique_payment_request (app_id, source_type, source_id),
    INDEX idx_treasury_payment_status (status),
    INDEX idx_treasury_payment_player (player_rsn),
    INDEX idx_treasury_payment_source (app_id, source_type, source_id),
    INDEX idx_treasury_payment_revenue_account (revenue_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE treasury_payout_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_uuid CHAR(36) NOT NULL UNIQUE,
    app_id BIGINT UNSIGNED NOT NULL,
    source_type VARCHAR(100) NOT NULL,
    source_id VARCHAR(100) NOT NULL,
    payee_rsn VARCHAR(20) NOT NULL,
    amount BIGINT UNSIGNED NOT NULL,
    payout_type ENUM('prize','expense','admin_reimbursement') NOT NULL,
    description VARCHAR(255) NOT NULL,
    expense_account_id BIGINT UNSIGNED NULL,
    status ENUM('pending','paid_from_treasury','paid_by_admin','reimbursed','cancelled') NOT NULL DEFAULT 'pending',
    paid_by_admin_id BIGINT UNSIGNED NULL,
    paid_transaction_id BIGINT UNSIGNED NULL,
    reimbursement_transaction_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    paid_at DATETIME NULL,
    reimbursed_at DATETIME NULL,
    metadata JSON NULL,
    FOREIGN KEY (app_id) REFERENCES treasury_apps(id),
    FOREIGN KEY (expense_account_id) REFERENCES treasury_accounts(id),
    FOREIGN KEY (paid_by_admin_id) REFERENCES treasury_admins(id),
    FOREIGN KEY (paid_transaction_id) REFERENCES treasury_transactions(id),
    FOREIGN KEY (reimbursement_transaction_id) REFERENCES treasury_transactions(id),
    UNIQUE KEY unique_payout_request (app_id, source_type, source_id),
    INDEX idx_treasury_payout_status (status),
    INDEX idx_treasury_payout_payee (payee_rsn),
    INDEX idx_treasury_payout_source (app_id, source_type, source_id),
    INDEX idx_treasury_payout_expense_account (expense_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE treasury_reconciliations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reconciliation_uuid CHAR(36) NOT NULL UNIQUE,
    from_admin_id BIGINT UNSIGNED NOT NULL,
    amount BIGINT UNSIGNED NOT NULL,
    status ENUM('pending','completed','cancelled') NOT NULL DEFAULT 'pending',
    transaction_id BIGINT UNSIGNED NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    created_by_admin_id BIGINT UNSIGNED NULL,
    completed_by_admin_id BIGINT UNSIGNED NULL,
    FOREIGN KEY (from_admin_id) REFERENCES treasury_admins(id),
    FOREIGN KEY (transaction_id) REFERENCES treasury_transactions(id),
    FOREIGN KEY (created_by_admin_id) REFERENCES treasury_admins(id),
    FOREIGN KEY (completed_by_admin_id) REFERENCES treasury_admins(id),
    INDEX idx_treasury_recon_status (status),
    INDEX idx_treasury_recon_admin (from_admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE treasury_idempotency_keys (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    app_id BIGINT UNSIGNED NOT NULL,
    idempotency_key VARCHAR(180) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    response_code SMALLINT UNSIGNED NOT NULL,
    response_body JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (app_id) REFERENCES treasury_apps(id),
    UNIQUE KEY unique_app_idempotency_key (app_id, idempotency_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE treasury_request_settlements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transaction_id BIGINT UNSIGNED NOT NULL,
    request_type ENUM('payment','payout') NOT NULL,
    request_id BIGINT UNSIGNED NOT NULL,
    settlement_type ENUM('admin_balance_offset') NOT NULL DEFAULT 'admin_balance_offset',
    amount BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (transaction_id) REFERENCES treasury_transactions(id),
    INDEX idx_treasury_request_settlements_transaction (transaction_id),
    INDEX idx_treasury_request_settlements_request (request_type, request_id),
    INDEX idx_treasury_request_settlements_type (settlement_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE treasury_audit_log (
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
    FOREIGN KEY (actor_admin_id) REFERENCES treasury_admins(id),
    FOREIGN KEY (actor_app_id) REFERENCES treasury_apps(id),
    INDEX idx_treasury_audit_entity (entity_type, entity_id),
    INDEX idx_treasury_audit_action (action),
    INDEX idx_treasury_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
