# RS3 GP Treasury

Standalone RuneScape 3 GP treasury/ledger application for clan use. It is designed to behave like a small accounting package for RS3 GP, not real-world money.

The application can be used manually through the admin UI before any external app integration is added. Later, Bingo, Runes of Power, and future apps can create payment and payout requests through the API.

## Included

- Admin web interface for day-to-day treasury management.
- Discord OAuth admin login with linked treasury admins, owner IDs, and optional role checks.
- Dashboard showing official treasury, admin-held GP, reimbursements owed, and total clan GP.
- Manual opening balance / treasury adjustment flow.
- Payment request grid for entry fees and clan contributions, including safe cancellation for pending requests.
- Payout request grid for prizes, expenses, and reimbursements, including safe cancellation for pending requests.
- Admin-held reconciliation into the official treasury, including selectable received payments and reconciliation history.
- Manual expenses from the official treasury.
- Manual admin-paid expenses and reimbursements.
- Ledger transaction history with debit/credit lines.
- Ledger correction workflow using linked reversal transactions.
- Source app management for Bingo, Runes of Power, and future apps.
- MySQL schema for apps, API keys, admins, accounts, payment requests, payout requests, reconciliations, ledger transactions, ledger entries, idempotency, and audit logs.
- Minimal API layer, ready to expand after the application workflow is settled.
- CLI helpers to create source apps, API keys, and admins.

## Requirements

- PHP 8.1+
- MySQL 8+
- PDO MySQL extension
- Web server document root pointed to `/public`

## Install

1. Create a database.
2. Import the schema and seed data:

```bash
mysql -u USER -p DATABASE < database/schema.sql
mysql -u USER -p DATABASE < database/seed.sql
```

3. Copy `.env.example` to `.env` and update the DB settings.

```bash
cp .env.example .env
```

4. Set a private fallback admin UI password in `.env`:

```env
ADMIN_PASSWORD_LOGIN_ENABLED=true
ADMIN_UI_PASSWORD=replace_with_a_long_private_password
```

5. Visit the application in your browser and sign in with that password.

6. Create your first treasury admin from **Settings**, or use the CLI helper:

```bash
php bin/create-admin.php "Lewis" "Lewis" "123456789012345678"
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

5. Make sure each treasury admin row has the correct Discord user ID. You can create admins through **Settings** or with:

```bash
php bin/create-admin.php "Lewis" "Lewis" "123456789012345678"
```

When a linked admin signs in with Discord, the app automatically selects that person as the acting admin. Set this if you want to prevent switching to another acting admin:

```env
DISCORD_LOCK_ACTING_ADMIN_TO_LOGIN=true
```

When this is enabled, the header no longer shows the acting-admin dropdown. It shows the linked acting admin as a locked pill instead. If it shows **Not linked**, add the logged-in Discord user ID to that person's `treasury_admins.discord_user_id` value before posting treasury actions.

## Recommended first-use workflow

1. Open **Settings** and create your treasury admins.
2. Confirm the seeded source apps exist: Manual Admin, Bingo, and Runes of Power.
3. Open **Dashboard** and post the current official treasury balance as an opening balance.
4. Create payment requests as players pay entry fees or clan contributions.
5. Mark payments as received by the admin who physically received the GP.
6. Use **Reconciliation** when that admin transfers the GP into the official treasury.
7. Use **Payouts** for prizes and payment obligations.
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

- Sidebar labels now follow the workflow: Overview, Money in, Money out, Bank reconciliation, Ledger, Settings.
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
