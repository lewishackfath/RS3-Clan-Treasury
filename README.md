# RS3 GP Treasury

Standalone RuneScape 3 GP treasury/ledger application for clan use. It is designed to behave like a small accounting package for RS3 GP, not real-world money.

The application can be used manually through the admin UI before any external app integration is added. Later, Bingo, Runes of Power, and future apps can create payment and payout requests through the API.

## Included

- Admin web interface for day-to-day treasury management.
- Discord OAuth admin login with linked treasury admins, owner IDs, and optional role checks.
- Dashboard showing official treasury, admin-held GP, reimbursements owed, and total clan GP.
- Manual opening balance / treasury adjustment flow.
- Payment request grid for entry fees and clan contributions.
- Payout request grid for prizes, expenses, and reimbursements.
- Admin-held reconciliation into the official treasury.
- Manual expenses from the official treasury.
- Manual admin-paid expenses and reimbursements.
- Ledger transaction history with debit/credit lines.
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
ADMIN_UI_PASSWORD=replace_with_a_long_private_password
```

5. Visit the application in your browser and sign in with that password.

6. Create your first treasury admin from **Settings**, or use the CLI helper:

```bash
php bin/create-admin.php "Lewis" "Lewis" "123456789012345678"
```

7. Select an **Acting admin** in the top-right of the UI before posting treasury actions.

## Discord OAuth setup

The app keeps the password login as a fallback, but Discord OAuth can be enabled once your Discord application is configured.

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

## Recommended first-use workflow

1. Open **Settings** and create your treasury admins.
2. Confirm the seeded source apps exist: Manual Admin, Bingo, and Runes of Power.
3. Open **Dashboard** and post the current official treasury balance as an opening balance.
4. Create payment requests as players pay entry fees or clan contributions.
5. Mark payments as received by the admin who physically received the GP.
6. Use **Reconciliation** when that admin transfers the GP into the official treasury.
7. Use **Payouts** for prizes and payment obligations.
8. Use **Ledger** to review the immutable debit/credit history.

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
