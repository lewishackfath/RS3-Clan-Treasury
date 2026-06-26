-- Chart of Accounts upgrade for existing RS3 GP Treasury installs.
-- Run this once after deploying this build:
--   mysql -u USER -p DATABASE < database/migrations/2026_06_26_chart_of_accounts.sql

ALTER TABLE treasury_payment_requests
    ADD COLUMN revenue_account_id BIGINT UNSIGNED NULL AFTER description,
    ADD CONSTRAINT fk_treasury_payment_revenue_account FOREIGN KEY (revenue_account_id) REFERENCES treasury_accounts(id),
    ADD INDEX idx_treasury_payment_revenue_account (revenue_account_id);

ALTER TABLE treasury_payout_requests
    ADD COLUMN expense_account_id BIGINT UNSIGNED NULL AFTER description,
    ADD CONSTRAINT fk_treasury_payout_expense_account FOREIGN KEY (expense_account_id) REFERENCES treasury_accounts(id),
    ADD INDEX idx_treasury_payout_expense_account (expense_account_id);

-- Seed app-specific posting accounts.
INSERT INTO treasury_accounts (code, name, account_type, parent_account_id, app_id, normal_balance, is_system, is_active)
SELECT '4110', 'Bingo Entry Fees', 'income', parent.id, app.id, 'credit', 0, 1
FROM treasury_accounts parent
JOIN treasury_apps app ON app.slug = 'bingo'
WHERE parent.code = '4000'
ON DUPLICATE KEY UPDATE name = VALUES(name), account_type = VALUES(account_type), parent_account_id = VALUES(parent_account_id), app_id = VALUES(app_id), normal_balance = VALUES(normal_balance);

INSERT INTO treasury_accounts (code, name, account_type, parent_account_id, app_id, normal_balance, is_system, is_active)
SELECT '4210', 'Runes of Power Entry Fees', 'income', parent.id, app.id, 'credit', 0, 1
FROM treasury_accounts parent
JOIN treasury_apps app ON app.slug = 'runes_of_power'
WHERE parent.code = '4000'
ON DUPLICATE KEY UPDATE name = VALUES(name), account_type = VALUES(account_type), parent_account_id = VALUES(parent_account_id), app_id = VALUES(app_id), normal_balance = VALUES(normal_balance);

INSERT INTO treasury_accounts (code, name, account_type, parent_account_id, app_id, normal_balance, is_system, is_active)
SELECT '5110', 'Bingo Prize Payouts', 'expense', parent.id, app.id, 'debit', 0, 1
FROM treasury_accounts parent
JOIN treasury_apps app ON app.slug = 'bingo'
WHERE parent.code = '5000'
ON DUPLICATE KEY UPDATE name = VALUES(name), account_type = VALUES(account_type), parent_account_id = VALUES(parent_account_id), app_id = VALUES(app_id), normal_balance = VALUES(normal_balance);

INSERT INTO treasury_accounts (code, name, account_type, parent_account_id, app_id, normal_balance, is_system, is_active)
SELECT '5210', 'Runes of Power Prize Payouts', 'expense', parent.id, app.id, 'debit', 0, 1
FROM treasury_accounts parent
JOIN treasury_apps app ON app.slug = 'runes_of_power'
WHERE parent.code = '5000'
ON DUPLICATE KEY UPDATE name = VALUES(name), account_type = VALUES(account_type), parent_account_id = VALUES(parent_account_id), app_id = VALUES(app_id), normal_balance = VALUES(normal_balance);

-- Attach existing open requests to sensible defaults.
UPDATE treasury_payment_requests pr
JOIN treasury_accounts acc ON acc.code = CASE
    WHEN pr.purpose = 'clan_contribution' THEN '4300'
    WHEN pr.purpose = 'entry_fee' AND pr.app_id = (SELECT id FROM treasury_apps WHERE slug = 'bingo' LIMIT 1) THEN '4110'
    WHEN pr.purpose = 'entry_fee' AND pr.app_id = (SELECT id FROM treasury_apps WHERE slug = 'runes_of_power' LIMIT 1) THEN '4210'
    WHEN pr.purpose = 'entry_fee' THEN '4100'
    ELSE '4300'
END
SET pr.revenue_account_id = acc.id
WHERE pr.revenue_account_id IS NULL;

UPDATE treasury_payout_requests pr
JOIN treasury_accounts acc ON acc.code = CASE
    WHEN pr.payout_type = 'prize' AND pr.app_id = (SELECT id FROM treasury_apps WHERE slug = 'bingo' LIMIT 1) THEN '5110'
    WHEN pr.payout_type = 'prize' AND pr.app_id = (SELECT id FROM treasury_apps WHERE slug = 'runes_of_power' LIMIT 1) THEN '5210'
    WHEN pr.payout_type = 'prize' THEN '5100'
    ELSE '6000'
END
SET pr.expense_account_id = acc.id
WHERE pr.expense_account_id IS NULL;
