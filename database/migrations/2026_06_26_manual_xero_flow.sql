-- Manual Xero-style request flow cleanup.
-- Safe to run once after deploying this build.

UPDATE treasury_apps
SET name = 'Manual Entry', description = 'Manual treasury administration actions'
WHERE slug = 'manual_admin';

-- Earlier builds may have created app-specific starter posting accounts.
-- They are intentionally left user-managed: delete them from Chart of Accounts if unused,
-- or archive them if they have request/ledger history.
