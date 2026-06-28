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
Credit: Admin Funds Owed by Treasury
```

Admin is reimbursed:

```text
Debit:  Admin Funds Owed by Treasury
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
php bin/create-api-key.php bingo "Bingo Production" "payments:create,payments:receive,payments:read,payouts:create,payouts:pay,payouts:read,transactions:read,reconciliation:read"
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
  - `2000` Admin Funds Owed by Treasury
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

### Public API documentation page

A public, login-free API reference is available at:

```text
https://your-treasury-domain.example/api-docs.php
```

Use this page as the live contract when building Bingo, Runes of Power, or future source-app integrations.

API v1 lets source apps create request records, read status, optionally mark Money In as received by a named active treasury user with `payments:receive`, and optionally mark Money Out as paid by a named active treasury user with `payouts:pay`. External apps still cannot reimburse admins, reconcile treasury handovers, reverse records, or post arbitrary ledger transactions.

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

If the source app already knows the GP was physically received by an admin, include `received_by_admin_rsn` and grant the API key both `payments:create` and `payments:receive`:

```json
{
  "source_type": "runes_of_power_entry",
  "source_id": "entry_123",
  "payer_rsn": "PlayerX",
  "amount": 1000000,
  "description": "Runes of Power entry fee",
  "revenue_account_code": "4100",
  "received_by_admin_rsn": "Lodo",
  "received_at": "2026-06-27T10:58:00+10:00",
  "metadata": {
    "draw_id": 8,
    "entry_id": 123
  }
}
```

This creates the request as `received_by_admin`, posts the revenue, and records the GP as owed to treasury by the named admin until it is handed over/reconciled in Treasury.

The older alias `POST /api/v1/payment-requests` is still supported.

### Mark existing Money In request as received

```http
POST /api/v1/money-in-requests/{request_uuid}/receive
```

```json
{
  "received_by_admin_rsn": "Lodo",
  "received_at": "2026-06-27T10:58:00+10:00",
  "notes": "Received in-game by Lodo"
}
```

Required scope: `payments:receive`.

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

If the source app already knows an admin paid the outgoing GP from their own funds, include `paid_by_admin_rsn` and grant the API key both `payouts:create` and `payouts:pay`:

```json
{
  "source_type": "runes_of_power_winnings",
  "source_id": "draw_8_winner_playerx",
  "payee_rsn": "PlayerX",
  "amount": 25000000,
  "description": "Runes of Power draw 8 winnings",
  "expense_account_code": "5100",
  "paid_by_admin_rsn": "Lodo",
  "paid_at": "2026-06-27T10:58:00+10:00",
  "metadata": {
    "draw_id": 8,
    "winner_rsn": "PlayerX"
  }
}
```

This creates the request as `paid_by_admin`, posts the expense, and records the GP as an admin reimbursement payable until the admin is reimbursed from the official treasury.

The older alias `POST /api/v1/payout-requests` is still supported.

### Mark existing Money Out request as paid by admin

```http
POST /api/v1/money-out-requests/{request_uuid}/pay-by-admin
```

```json
{
  "paid_by_admin_rsn": "Lodo",
  "paid_at": "2026-06-27T10:58:00+10:00",
  "notes": "Paid in-game by Lodo"
}
```

Required scope: `payouts:pay`.

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
- If the repeat includes `received_by_admin_rsn` or `paid_by_admin_rsn` and the existing request is still pending, Treasury will post the matching received/paid action.
- `revenue_account_code` and `expense_account_code` must refer to active user-managed GL accounts.
- Use idempotency keys for safe retries on POST requests.


## Apache Authorization header note

If API calls return `Missing bearer token` even though curl is sending `Authorization: Bearer ...`, Apache/FastCGI is probably not passing the `Authorization` header through to PHP.

This build includes a `.htaccess` fix:

```apache
SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
```

The API auth layer also checks `HTTP_AUTHORIZATION`, `REDIRECT_HTTP_AUTHORIZATION`, and `getallheaders()` as fallbacks.


## Source app slugs

When source apps are created from the web UI, the slug is generated automatically from the app name. For example, `Runes of Power` becomes `runes_of_power`. If that slug already exists, the app will use the next available suffix, such as `runes_of_power_2`. Existing source apps can still have their slug edited from the Source Apps table, but avoid changing a slug once external integrations depend on it.


## Admin-held GP wording

Money received by an admin is treated as **money owed by that admin to the official treasury**. Reconciliation/handover should only be recorded when that GP has actually been transferred into the official clan treasury. Treasury users can record GP as being held by another admin, even if that admin does not have app access.

## Integration and API key UI cleanup

This build separates integration management from API key management.

- **Integrations** replaces the old Source Apps page name and manages source apps/integrations.
- **API Keys** is now a separate page for creating, editing, revoking, restoring, deleting, and regenerating API keys.
- API key permissions/scopes and expiry dates can be edited without changing the raw key.
- Regenerating an API key immediately replaces the stored hash and displays the new raw key once. Existing clients using the previous raw key will stop working.
- The sidebar is grouped into workflow categories to make the app easier to navigate.

No database migration is required.

## Multi-RSN users and management screens

This build adds support for multiple RSNs per treasury user. Each user has one primary RSN for display and account naming, plus any number of additional active RSNs that can be matched by API workflows such as `received_by_admin_rsn` and `paid_by_admin_rsn`.

The DB bootstrap creates and backfills the `treasury_admin_rsns` table automatically. Existing `treasury_admins.rsn` values are retained as the primary RSN for backwards compatibility.

UI changes:

- New **Profile** page for the current Discord-linked treasury user.
- Users now use dedicated **New user** and **Edit user** screens.
- User edit screens manage multiple RSNs.
- Integrations now use dedicated **New integration** and **Edit integration** screens.
- API keys now use dedicated **New API key** and **Edit API key** screens.
- Inline editing has been removed from Users, Integrations, and API Keys.


## Profile form alignment hotfix

This build adds a small UI alignment fix for profile and treasury-user edit forms so field labels, inputs, helper text, and save actions line up cleanly.


## Per-admin funds owed to admins

Admin-paid payouts and manual admin-paid expenses now use per-admin liability accounts under `2000 Admin Funds Owed by Treasury`, matching the existing per-admin asset accounts under `1100 Admin Funds Owed to Treasury`.

This means:

- GP received by an admin is tracked against `1100:{admin_id}` until handed over to the official treasury.
- GP paid personally by an admin is tracked against `2000:{admin_id}` until the treasury reimburses them.
- The dashboard shows both "Money owed by admins" and "Money owed to admins" breakdowns.
- Existing older entries against the shared `2000` account are still included in the total as legacy payable balance.

No manual migration is required when DB Bootstrap is enabled.

## Repair legacy shared admin payable entries

If an admin-paid payout or reimbursement was recorded before per-admin payable accounts were added, its ledger entry may still sit on the shared parent account `2000 Admin Funds Owed by Treasury`.

Preview the repair:

```bash
php bin/repair-admin-payables.php --dry-run
```

Apply the repair:

```bash
php bin/repair-admin-payables.php
```

The repair does not change any amounts, transaction dates, statuses, or payout records. It only reclassifies resolvable ledger lines from the shared parent payable account to the correct per-admin child account, such as `2000:12 Funds Owed to Admin - Example`.

## Admin balance offsets

If the same admin owes GP to the treasury and the treasury also owes GP to that admin, the two balances can be offset without moving GP through the official treasury.

Example:

- Lodo owes treasury 1.1m from received contributions.
- Treasury owes Lodo 1.1m from admin-paid payouts.

The Reconciliation page shows a **Mutual admin balance offsets** section. When the amounts and the linked open Money In/Money Out request totals match exactly, the app can post an offset transaction that:

- Debits the per-admin Funds Owed to Admin account.
- Credits the per-admin Funds Owed by Admin account.
- Marks the included Money In requests as settled/reconciled.
- Marks the included Money Out requests as reimbursed.
- Leaves the Official Treasury account unchanged.

The first implementation only auto-offsets exact matching balances. Partial offsets should be handled with normal handover/reimbursement flows or future manual selection support.

## Partial automatic admin balance offsets

Admin balance offsets now support partial clearing. If an admin both owes GP to the treasury and is owed GP by the treasury, the app automatically offsets the overlapping amount whenever relevant Money In or Money Out movements are posted.

Example:

- Lodo owes treasury 2.5m GP
- Treasury owes Lodo 1.1m GP
- The app can automatically offset 1.1m GP
- Lodo still owes treasury 1.4m GP
- Official treasury balance is unchanged

The offset posts a ledger adjustment between the per-admin asset account and the per-admin payable account. If the offset relates to Money In or Money Out requests, partial request allocations are tracked in `treasury_request_settlements`. Fully settled requests are automatically moved to `reconciled_to_treasury` or `reimbursed`; partially settled requests remain open for the remaining amount.

DB Bootstrap creates the new settlement allocation table automatically.

## API Request Log

This build adds an **API Log** page under the Integrations menu.

The API Log records API calls made to `public/api.php`, including:

- Integration/source app identity when authentication succeeds.
- API key record name and ID when authentication succeeds.
- HTTP method, path, query string, status code, and response time.
- Idempotency key, IP address, and user agent.
- Request body and response body, truncated by `API_REQUEST_LOG_BODY_MAX_BYTES`.
- Error message for JSON error responses.

Raw API keys and `Authorization` headers are not stored.

Optional `.env` controls:

```env
API_REQUEST_LOG_ENABLED=true
API_REQUEST_LOG_BODY_MAX_BYTES=20000
```

DB Bootstrap creates the `treasury_api_request_logs` table automatically.


## Branding and logo

The app branding can be customised from `.env` without editing PHP templates:

```env
APP_NAME="RS3 GP Treasury"
APP_TAGLINE="RS3 GP Ledger"
APP_LOGO_URL=assets/logo.png
APP_FAVICON_URL=favicon.ico
```

`APP_LOGO_URL` may be a path inside `public/`, such as `assets/logo.png`, or a full HTTPS image URL. If it is blank, the app falls back to the built-in gold diamond mark.

The supplied `favicon.ico` is included at `public/favicon.ico` and is used by default.

The UI palette has also been softened to use darker parchment surfaces and lower-contrast highlights to reduce eye strain.


## API endpoint: valid admin RSNs

Integrations can fetch the active Treasury user RSNs that are valid for `received_by_admin_rsn` and `paid_by_admin_rsn`.

```http
GET /api/v1/admin-rsns
Authorization: Bearer <api_key>
```

Required scope: one of `admins:read`, `payments:receive`, or `payouts:pay`.

Example response:

```json
{
  "admins": [
    {
      "admin_id": 1,
      "display_name": "Display Name",
      "primary_rsn": "Main RSN",
      "rsns": [
        { "rsn_id": 10, "rsn": "Main RSN", "is_primary": true },
        { "rsn_id": 11, "rsn": "Alt RSN", "is_primary": false }
      ]
    }
  ],
  "rsns": [
    { "rsn": "Main RSN", "admin_id": 1, "display_name": "Display Name", "is_primary": true }
  ],
  "accepted_fields": ["received_by_admin_rsn", "paid_by_admin_rsn"]
}
```

## Discord treasury transaction logging

This build adds Discord bot support for operational treasury logging.

Configure the bot token in `.env`:

```env
DISCORD_BOT_TOKEN=your_discord_bot_token
DISCORD_TREASURY_LOG_ENABLED=true
DISCORD_TREASURY_LOG_CHANNEL_ID=
```

Then open **Settings** in the web UI and select the Discord channel that should receive treasury transaction messages.

The bot needs these permissions in the selected channel:

- View Channel
- Send Messages
- Embed Links

The Settings page will use the bot token to resolve the configured guild name, allowed role names, and available text/announcement channels. If the bot token is not configured or the bot cannot read the server, the app falls back to showing the configured IDs.

A Discord message is sent whenever a posted ledger transaction moves GP into or out of the `1000 Official Treasury` account. Admin-balance offsets do not send messages because no GP moves through the official treasury.


## Darker textured background

This build updates the main application background to a darker brown tone closer to the sidebar, with a subtle textured effect and slightly stronger card contrast for readability.


## Final UI pass

This build performs a final theme cleanup so tiles and nested subtile panels use dark backgrounds with light text consistently across the app, while keeping form fields and action buttons readable.


## Final small UI fixes

This build improves Danger Zone readability, rebuilds status tab colours for contrast, cache-busts the main stylesheet, and hides the locked acting-admin pill when account switching is disabled.

## Clearing test transaction and log data

To wipe test transaction data while keeping users, RSNs, accounts, integrations, API keys, and settings, run:

```bash
php bin/clear-transaction-data.php --dry-run
php bin/clear-transaction-data.php --force
```

This clears ledger transactions, ledger lines, Money In/Out requests, reconciliations, admin balance settlements, idempotency records, audit logs, and API request logs. It also resets API key `last_used_at` timestamps by default without deleting the API keys themselves.

To keep API key `last_used_at` values, run:

```bash
php bin/clear-transaction-data.php --force --keep-api-key-last-used
```

