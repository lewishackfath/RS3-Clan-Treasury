# RS3 GP Treasury

Standalone RuneScape 3 GP treasury/ledger application for clan use. It is designed to behave like a small accounting package for RS3 GP, not real-world money.

The application can be used manually through the admin UI before any external app integration is added. Later, Bingo, Runes of Power, and future apps can create payment and payout requests through the API.

## Included

- Admin web interface for day-to-day treasury management.
- Discord OAuth admin login with linked treasury users, owner IDs, and optional role checks.
- Dashboard showing official treasury, admin-held GP, reimbursements owed, and total clan GP.
- Manual opening balance / treasury adjustment flow.
- Payment request grid for entry fees and clan contributions, including safe cancellation for pending requests.
- Payout request grid for prizes, expenses, and reimbursements, including safe cancellation for pending requests.
- Admin-held reconciliation into the official treasury, including selectable received payments and reconciliation history.
- Manual expenses from the official treasury.
- Manual admin-paid expenses and reimbursements.
- Ledger transaction history with debit/credit lines.
- Ledger correction workflow using linked reversal transactions.
- Source app management for future API integrations.
- Automatic database bootstrap for required tables, columns, indexes, and system records.
- Minimal API layer, ready to expand after the application workflow is settled.
- CLI helpers to create source apps, API keys, and treasury users.

## Requirements

- PHP 8.1+
- MySQL 8+
- PDO MySQL extension
- Web server document root pointed to `/public`

## Install

1. Create an empty database, or set `DB_BOOTSTRAP_CREATE_DATABASE=true` if your DB user is allowed to create databases.
2. Copy `.env.example` to `.env` and update the DB settings.

```bash
cp .env.example .env
```

3. The application now bootstraps the database automatically on load. It creates/updates the required tables, columns, indexes, and system records.

You can also run the bootstrap manually:

```bash
php db_bootstrap.php
```

Manual SQL imports are no longer required for fresh installs. `database/schema.sql` is retained as a reference/export only, and `database/seed.sql` is intentionally empty.

4. Set a private fallback admin UI password in `.env`:

```env
ADMIN_PASSWORD_LOGIN_ENABLED=true
ADMIN_UI_PASSWORD=replace_with_a_long_private_password
```

5. Visit the application in your browser and sign in with that password.

6. Create your first treasury user from **Users**, or use the CLI helper:

```bash
php bin/create-admin.php "First Name" "RuneScape Name" "123456789012345678"
```

7. Select an **Acting admin** in the top-right of the UI before posting treasury actions.


### Disable fallback password login

After confirming Discord OAuth works, set:

```env
ADMIN_PASSWORD_LOGIN_ENABLED=false
```

Keep `ADMIN_UI_PASSWORD` set to a long private value even when password login is disabled, but it will no longer be accepted by the login handler.

## Discord OAuth setup

The app keeps the password login as a fallback by default, but it can be disabled with `ADMIN_PASSWORD_LOGIN_ENABLED=false` once Discord OAuth is working.

1. Create or open a Discord application in the Discord Developer Portal.
2. Add this exact redirect URL to the app OAuth2 redirect list:

```text
https://your-treasury-domain.example.com/?page=discord_callback
```

3. Update `.env`:

```env
DISCORD_OAUTH_ENABLED=true
DISCORD_CLIENT_ID=your_discord_client_id
DISCORD_CLIENT_SECRET=your_discord_client_secret
DISCORD_REDIRECT_URI=https://your-treasury-domain.example.com/?page=discord_callback
```

4. Choose one or more authorisation methods:

```env
# Always allowed users, comma-separated Discord user IDs.
DISCORD_OWNER_USER_IDS=123456789012345678

# Allow rows in treasury_admins where discord_user_id matches the Discord account.
DISCORD_ALLOW_LINKED_TREASURY_ADMINS=true

# Optional role-based access. Requires the user to be in this server and hold one of these roles.
DISCORD_GUILD_ID=123456789012345678
DISCORD_ADMIN_ROLE_IDS=111111111111111111,222222222222222222
```

5. Make sure each treasury user row has the correct Discord user ID. You can create users through **Users** or with:

```bash
php bin/create-admin.php "First Name" "RuneScape Name" "123456789012345678"
```

When a linked admin signs in with Discord, the app automatically selects that person as the acting admin. Set this if you want to prevent switching to another acting admin:

```env
DISCORD_LOCK_ACTING_ADMIN_TO_LOGIN=true
```

When this is enabled, the header no longer shows the acting-admin dropdown. It shows the linked acting admin as a locked pill instead. If it shows **Not linked**, add the logged-in Discord user ID to that person's `treasury_admins.discord_user_id` value before posting treasury actions.

## Recommended first-use workflow

1. Open **Users** and create your treasury users.
2. Open **Chart of Accounts** and create your own Revenue and Expense GL accounts, such as Bingo Entry Fees, Runes of Power Contributions, Prize Payouts, or Giveaways.
3. Open **Dashboard** and post the current official treasury balance as an opening balance.
4. Create Money In requests as players pay entry fees or clan contributions.
5. Mark payments as received by the admin who physically received the GP.
6. Use **Bank Reconciliation** when that admin transfers the GP into the official treasury.
7. Use **Money Out** for prizes and payment obligations.
8. Use **Ledger** to review the immutable debit/credit history.




## Ledger corrections and reversals

Posted ledger transactions are not edited or deleted. If a mistake is made, open **Ledger**, expand the transaction, and use **Reverse this transaction**.

A reversal creates a new posted transaction with the opposite debit/credit lines, then marks the original transaction as `reversed`. This keeps the audit trail intact.

Linked workflow behaviour is conservative:

- A received payment can be reversed only while it is still `received_by_admin`. The payment request returns to `pending`.
- A reconciled payment receipt cannot be reversed until the reconciliation transaction is reversed first.
- Reversing a reconciliation returns the linked payments to `received_by_admin` and marks the reconciliation record `cancelled`.
- A payout paid from treasury or paid by an admin can be reversed while it has not progressed further. The payout request returns to `pending`.
- An admin-paid payout that has already been reimbursed must have the reimbursement reversed before the original payout can be reversed.
- Reversing a payout reimbursement returns the payout request to `paid_by_admin`.

After a request is returned to `pending`, it can be received or paid again. The app safely generates a new internal transaction source ID so the original reversed transaction remains traceable.

## Reconciliation workflow

The reconciliation page is now payment-specific:

1. Filter to the admin who physically holds the GP.
2. Review the received-but-unreconciled payments for that admin.
3. Select the payments that were actually moved into the official treasury.
4. Post the reconciliation.

The app totals the selected payment requests server-side and posts one balanced reconciliation transaction. It then marks those payment requests as `reconciled_to_treasury` and links them back to the reconciliation transaction.

The same page also shows reconciliation history, including the payment requests included in each completed reconciliation.

## Cancelling requests

Pending payment and payout requests can be cancelled from their grids. This is intended for mistakes before GP has moved.

Once a payment has been received or a payout has been paid, it should not be cancelled directly. Use a ledger correction/reversal workflow instead.

## Accounting model

Balances are calculated from ledger entries, not stored manually.

Examples:

Admin receives an entry fee:

```text
Debit:  Admin Held Funds - Admin
Credit: Entry Fee Income
```

Admin reconciles held GP into the official treasury:

```text
Debit:  Official Treasury
Credit: Admin Held Funds - Admin
```

Prize paid from official treasury:

```text
Debit:  Prize Expenses
Credit: Official Treasury
```

Admin pays a prize or expense personally:

```text
Debit:  Prize/General Expense
Credit: Admin Reimbursements Payable
```

Admin is reimbursed:

```text
Debit:  Admin Reimbursements Payable
Credit: Official Treasury
```

## Amounts

All GP values are stored as whole integers using `BIGINT UNSIGNED`.

The admin UI accepts shorthand such as:

```text
10m
1.25b
500k
10000000
```

## API authentication

The API is present but should be treated as secondary until the manual application workflow is confirmed.

Source apps use:

```http
Authorization: Bearer <source_app_api_key>
Idempotency-Key: <unique-key-per-write-request>
```

Admin-only API endpoints use:

```http
X-Admin-Token: <ADMIN_API_TOKEN from .env>
```

Create an API key when you are ready to integrate a source app:

```bash
php bin/create-api-key.php bingo "Bingo Production" "payments:create,payouts:create,transactions:read,reconciliation:read"
```

## Important rule

Posted transactions are immutable. Corrections should be handled with reversal transactions or correcting transactions, not edits or deletes.

## UI flow update

This package includes a cleaner finance-app style admin layout:

- Sidebar labels now follow the workflow: Overview, Money in, Money out, Bank reconciliation, Ledger, Chart of Accounts, Users, Settings.
- Overview focuses on cash position, things to do, and the three main workflows.
- Money in and Money out use status summaries, status tabs, a filter panel, and a right-hand creation panel.
- Bank reconciliation remains payment-specific and keeps reconciliation history visible.
- Manual adjustments are still available, but they are grouped as quick manual actions rather than being the main dashboard focus.

No database migration is required for the UI update.


## RuneScape-themed Xero-style palette

This build keeps the cleaner finance-style layout, but swaps the default blue palette for warm RuneScape-inspired browns, parchment tones, and gold accents. No database migration is required.

## New menu and request page split

Money In and Money Out now focus on reviewing, filtering, and acting on existing requests.

Creation forms have been moved to their own pages and are available from the sidebar **New** flyout:

- Money-in request
- Money-out request
- Treasury adjustment
- Treasury expense
- Admin-paid expense
- Admin reimbursement

No database migration is required.


## Chart of Accounts and manual request flow

The web UI is now manual-accounting first:

- Money In uses payer, description, amount, and a Revenue GL account.
- Money Out uses payee, description, amount, and an Expense GL account.
- Source app/source ID fields are hidden from the web UI and are reserved for later API integrations.
- Manual web entries automatically use the internal `Manual Entry` source app.
- User-created revenue and expense accounts can be edited/renamed.
- Accounts with no ledger entries, request references, or child accounts can be deleted.
- Accounts with ledger/request history should be archived instead.
- System accounts remain locked because they power the ledger mechanics.
- No default posting accounts are created automatically. Create the GL accounts you want to report against from **Chart of Accounts**.

## Automatic database bootstrap

This build adds `db_bootstrap.php` and `Treasury\DatabaseBootstrap`. The app runs the bootstrapper during normal loading when `DB_BOOTSTRAP_ENABLED=true`.

The bootstrapper is intentionally strict about what it creates. It creates only:

- Required tables, columns, and indexes
- The internal `Manual Entry` source app used by web-created transactions
- Required locked system accounts:
  - `1000` Official Treasury
  - `1100` Admin Held Funds
  - `2000` Admin Reimbursements Payable
  - `3000` Opening Balance Equity
  - `4000` Revenue
  - `5000` Expenses

It does **not** create sample admins, API keys, Bingo/Runes of Power source apps, or posting GL accounts such as Entry Fees, Clan Contributions, or Prize Expenses. Those should be created manually from the UI so your reporting categories match how your clan actually runs treasury.

Useful `.env` settings:

```env
DB_BOOTSTRAP_ENABLED=true
DB_BOOTSTRAP_CREATE_DATABASE=false
DB_BOOTSTRAP_RUN_EVERY_REQUEST=false
```

For a full reset:

```sql
DROP DATABASE your_treasury_db;
CREATE DATABASE your_treasury_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Then load the app or run:

```bash
php db_bootstrap.php
```

After the app loads, create your treasury users and GL accounts from the UI.


## Users and RSN management

Treasury users are managed from the dedicated **Users** page instead of Settings. Use this page to create users, link Discord user IDs, edit display names, and update current RSNs when RuneScape names change.

When a current RSN is changed, the previous RSN is retained in `treasury_admin_rsn_history` so older audit and ledger records remain understandable. Users with no treasury activity can be deleted. Users with activity should be archived so the audit trail remains intact.

The database bootstrap creates and keeps the RSN history table in sync automatically. No sample users are created.


## First Discord sign-in RSN setup

When a Discord-authorised user signs in for the first time and their Discord user ID is not yet linked to a treasury user, the app now forces them through a short RSN setup page before they can access the treasury. This creates their treasury user, links their Discord user ID, records their current RSN, and sets them as the acting admin.

## Transaction detail pages

This build adds drill-down pages for key treasury records:

- Money In request details
- Money Out request details
- Reconciliation details
- Ledger transaction details

The list pages now link through to the relevant detail pages. Detail pages show the request/reconciliation summary, linked ledger transactions, ledger lines, audit events, and safe correction/reversal controls where applicable.

No database migration is required for this update.

## Reports

This build adds a dedicated Reports section. The reports are ledger-driven and exclude transactions marked as reversed as well as reversal transactions, so correction entries do not distort operational totals.

Included reports:

- Revenue & expenses: profit-and-loss style summary by GL account.
- Treasury movement: official treasury opening balance, GP moved in, GP paid out, and closing balance.
- Admin-held funds: current held GP per admin, plus received/reconciled activity for the selected period.
- Account activity: detailed debit/credit activity for any ledger account.

No database migration is required for this update.

## Source Apps & API Keys

This build adds a dedicated **Source Apps** page for integration management before the API layer is enabled.

The page supports:

- Creating source apps for external integrations such as Bingo and Runes of Power.
- Editing source app name, slug, and description.
- Archiving/restoring source apps with history.
- Deleting source apps only when they have no API keys, requests, ledger transactions, or linked accounts.
- Generating API keys for active source apps.
- Storing only SHA-256 hashes of API keys.
- Displaying generated API keys once only.
- Revoking/restoring API keys.
- Deleting API keys that have never been used.
- Showing last-used and expiry metadata.

`Manual Entry` is a locked internal source app. Manual web transactions always use this source and API keys cannot be generated for it.

No database migration is required if the DB bootstrap system is enabled, because the required `treasury_apps` and `treasury_api_keys` tables are already part of the bootstrap schema.


## API v1

API v1 lets source apps create request records and read status only. External apps cannot receive GP, pay prizes, reconcile, reverse, or post ledger transactions directly.

Authenticate with:

```http
Authorization: Bearer <api_key>
Idempotency-Key: <stable unique key for POST retries>
Content-Type: application/json
```

### Check identity

```http
GET /api/v1/me
```

### Create Money In request

```http
POST /api/v1/money-in-requests
```

```json
{
  "source_type": "bingo_entry",
  "source_id": "game_12_team_4_player_lodo",
  "payer_rsn": "Lodo",
  "amount": 10000000,
  "description": "Bingo entry fee - Game 12",
  "revenue_account_code": "4100",
  "metadata": {
    "game_id": 12,
    "team_id": 4
  }
}
```

Required scope: `payments:create`.

The older alias `POST /api/v1/payment-requests` is still supported.

### Read Money In request

```http
GET /api/v1/money-in-requests/{request_uuid}
GET /api/v1/money-in-requests/by-source/{source_type}/{source_id}
```

Required scope: `payments:read`.

### Create Money Out request

```http
POST /api/v1/money-out-requests
```

```json
{
  "source_type": "bingo_prize",
  "source_id": "game_12_line_row_3",
  "payee_rsn": "K3 K",
  "amount": 50000000,
  "description": "Bingo line prize - Game 12",
  "expense_account_code": "5100",
  "metadata": {
    "game_id": 12,
    "line": "row_3"
  }
}
```

Required scope: `payouts:create`.

The older alias `POST /api/v1/payout-requests` is still supported.

### Read Money Out request

```http
GET /api/v1/money-out-requests/{request_uuid}
GET /api/v1/money-out-requests/by-source/{source_type}/{source_id}
```

Required scope: `payouts:read`.

### Read balances

```http
GET /api/v1/balances
```

Required scope: `balances:read`.

### Notes

- `source_type` + `source_id` must be unique per source app.
- Repeating the same source reference returns the existing request instead of creating a duplicate.
- `revenue_account_code` and `expense_account_code` must refer to active user-managed GL accounts.
- Use idempotency keys for safe retries on POST requests.
