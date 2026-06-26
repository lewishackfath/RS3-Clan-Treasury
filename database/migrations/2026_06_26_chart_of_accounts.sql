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

-- Existing open requests are attached to broad system fallback accounts for history only.
-- Create custom revenue/expense accounts from the web UI for new posting.

-- Attach existing open requests to sensible defaults.
UPDATE treasury_payment_requests pr
JOIN treasury_accounts acc ON acc.code = CASE
    WHEN pr.purpose = 'clan_contribution' THEN '4300'
    WHEN pr.purpose = 'entry_fee' THEN '4100'
    ELSE '4300'
END
SET pr.revenue_account_id = acc.id
WHERE pr.revenue_account_id IS NULL;

UPDATE treasury_payout_requests pr
JOIN treasury_accounts acc ON acc.code = CASE
    WHEN pr.payout_type = 'prize' THEN '5100'
    ELSE '6000'
END
SET pr.expense_account_id = acc.id
WHERE pr.expense_account_id IS NULL;
