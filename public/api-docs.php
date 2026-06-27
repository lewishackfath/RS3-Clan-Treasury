<?php

declare(strict_types=1);

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'your-treasury-domain.example';
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$basePath = $scriptDir === '' ? '' : $scriptDir;
$baseUrl = $scheme . '://' . $host . $basePath;
$apiBase = $baseUrl . '/api/v1';
?>
<!doctype html>
<html lang="en-AU">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>RS3 GP Treasury API Documentation</title>
    <link rel="icon" href="favicon.ico">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="api-docs-page">
<main class="api-docs-shell">
    <header class="api-docs-hero">
        <div>
            <a class="api-docs-back" href="index.php">← Treasury app</a>
            <h1>RS3 GP Treasury API</h1>
            <p>Public integration reference for Bingo, Runes of Power, and future clan apps.</p>
        </div>
        <div class="api-docs-version">
            <span>Version</span>
            <strong>v1</strong>
        </div>
    </header>

    <section class="api-docs-grid">
        <aside class="api-docs-toc card">
            <h2>Contents</h2>
            <a href="#overview">Overview</a>
            <a href="#auth">Authentication</a>
            <a href="#scopes">Scopes</a>
            <a href="#idempotency">Idempotency</a>
            <a href="#money-in">Money In</a>
            <a href="#money-out">Money Out</a>
            <a href="#admin-rsns">Admin RSNs</a>
            <a href="#balances">Balances</a>
            <a href="#statuses">Statuses</a>
            <a href="#errors">Errors</a>
            <a href="#integration-checklist">Integration checklist</a>
        </aside>

        <div class="api-docs-content">
            <section id="overview" class="card api-doc-section">
                <h2>Overview</h2>
                <p>The Treasury API lets external apps create request records and read status. External apps cannot mark GP as received, pay prizes, reconcile, reverse, or post ledger transactions directly.</p>
                <dl class="api-kv">
                    <div><dt>Base URL</dt><dd><code><?= h($apiBase) ?></code></dd></div>
                    <div><dt>Format</dt><dd>JSON requests and JSON responses</dd></div>
                    <div><dt>Amounts</dt><dd>Integer GP only. <code>10000000</code> means 10m GP.</dd></div>
                    <div><dt>Source references</dt><dd><code>source_type</code> + <code>source_id</code> must be unique per source app.</dd></div>
                </dl>
            </section>

            <section id="auth" class="card api-doc-section">
                <h2>Authentication</h2>
                <p>Use an API key generated from <strong>Source Apps</strong>. Raw API keys are shown once only and stored as hashes.</p>
                <pre><code>Authorization: Bearer &lt;api_key&gt;
Content-Type: application/json</code></pre>

                <h3>Check identity</h3>
                <pre><code>GET <?= h($apiBase) ?>/me</code></pre>
                <p>Returns the authenticated source app and granted scopes.</p>
            </section>

            <section id="scopes" class="card api-doc-section">
                <h2>Scopes</h2>
                <table>
                    <thead><tr><th>Scope</th><th>Allows</th></tr></thead>
                    <tbody>
                        <tr><td><code>payments:create</code></td><td>Create Money In requests.</td></tr>
                        <tr><td><code>payments:receive</code></td><td>Mark Money In requests as received by an admin/API-reported holder.</td></tr>
                        <tr><td><code>payments:read</code></td><td>Read Money In request status.</td></tr>
                        <tr><td><code>payouts:create</code></td><td>Create Money Out requests.</td></tr>
                        <tr><td><code>payouts:pay</code></td><td>Mark Money Out requests as paid by an admin from their own GP.</td></tr>
                        <tr><td><code>payouts:read</code></td><td>Read Money Out request status.</td></tr>
                        <tr><td><code>balances:read</code></td><td>Read current treasury balances.</td></tr>
                        <tr><td><code>admins:read</code></td><td>Read valid treasury admin RSNs for received-by-admin and paid-by-admin integrations.</td></tr>
                    </tbody>
                </table>
            </section>

            <section id="idempotency" class="card api-doc-section">
                <h2>Idempotency</h2>
                <p>For all POST requests, send a stable idempotency key so retries do not create duplicates.</p>
                <pre><code>Idempotency-Key: bingo-entry-game-12-team-4-lodo</code></pre>
                <p>If the same key and same JSON body are submitted again, the original response is returned.</p>
            </section>

            <section id="money-in" class="card api-doc-section">
                <h2>Money In requests</h2>
                <p>Use Money In for entry fees, clan contributions, donations, and other incoming GP requests.</p>

                <h3>Create Money In request</h3>
                <pre><code>POST <?= h($apiBase) ?>/money-in-requests</code></pre>
                <p>Required scope: <code>payments:create</code></p>
                <pre><code>{
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
}</code></pre>

                <h3>Create Money In request already received by an admin</h3>
                <pre><code>POST <?= h($apiBase) ?>/money-in-requests</code></pre>
                <p>Required scopes: <code>payments:create</code> and <code>payments:receive</code></p>
                <pre><code>{
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
}</code></pre>
                <p>This creates the request and immediately posts it as <code>received_by_admin</code>. The GP is recorded as owed to treasury by the named admin until it is handed over/reconciled inside Treasury.</p>

                <h3>Mark existing Money In request as received</h3>
                <pre><code>POST <?= h($apiBase) ?>/money-in-requests/{request_uuid}/receive</code></pre>
                <p>Required scope: <code>payments:receive</code></p>
                <pre><code>{
  "received_by_admin_rsn": "Lodo",
  "received_at": "2026-06-27T10:58:00+10:00",
  "notes": "Received in-game by Lodo"
}</code></pre>

                <h3>Read Money In request</h3>
                <pre><code>GET <?= h($apiBase) ?>/money-in-requests/{request_uuid}
GET <?= h($apiBase) ?>/money-in-requests/by-source/{source_type}/{source_id}</code></pre>
                <p>Required scope: <code>payments:read</code></p>

                <h3>Important fields</h3>
                <table>
                    <thead><tr><th>Field</th><th>Required</th><th>Notes</th></tr></thead>
                    <tbody>
                        <tr><td><code>source_type</code></td><td>Yes</td><td>Integration-specific category, such as <code>bingo_entry</code>.</td></tr>
                        <tr><td><code>source_id</code></td><td>Yes</td><td>Stable unique ID from the source app.</td></tr>
                        <tr><td><code>payer_rsn</code></td><td>Yes</td><td>Alias for internal <code>player_rsn</code>.</td></tr>
                        <tr><td><code>amount</code></td><td>Yes</td><td>Integer GP.</td></tr>
                        <tr><td><code>description</code></td><td>Recommended</td><td>Shown to treasury admins.</td></tr>
                        <tr><td><code>revenue_account_code</code></td><td>Yes</td><td>Must be an active posting Revenue GL account.</td></tr>
                        <tr><td><code>metadata</code></td><td>No</td><td>JSON object for source app context.</td></tr>
                        <tr><td><code>received_by_admin_rsn</code></td><td>No</td><td>When supplied with <code>payments:receive</code>, creates the request as already received by that active treasury user.</td></tr>
                        <tr><td><code>received_at</code></td><td>No</td><td>ISO-8601 date/time of receipt. Defaults to current server time.</td></tr>
                    </tbody>
                </table>
            </section>

            <section id="money-out" class="card api-doc-section">
                <h2>Money Out requests</h2>
                <p>Use Money Out for prize payouts, event expenses, reimbursements, and other outgoing GP requests.</p>

                <h3>Create Money Out request</h3>
                <pre><code>POST <?= h($apiBase) ?>/money-out-requests</code></pre>
                <p>Required scope: <code>payouts:create</code></p>
                <pre><code>{
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
}</code></pre>

                <h3>Create Money Out request already paid by an admin</h3>
                <pre><code>POST <?= h($apiBase) ?>/money-out-requests</code></pre>
                <p>Required scopes: <code>payouts:create</code> and <code>payouts:pay</code></p>
                <pre><code>{
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
}</code></pre>
                <p>This creates the request and immediately posts it as <code>paid_by_admin</code>. The GP is recorded as an admin reimbursement payable until the admin is reimbursed from the official treasury inside Treasury.</p>

                <h3>Mark existing Money Out request as paid by admin</h3>
                <pre><code>POST <?= h($apiBase) ?>/money-out-requests/{request_uuid}/pay-by-admin</code></pre>
                <p>Required scope: <code>payouts:pay</code></p>
                <pre><code>{
  "paid_by_admin_rsn": "Lodo",
  "paid_at": "2026-06-27T10:58:00+10:00",
  "notes": "Paid in-game by Lodo"
}</code></pre>

                <h3>Read Money Out request</h3>
                <pre><code>GET <?= h($apiBase) ?>/money-out-requests/{request_uuid}
GET <?= h($apiBase) ?>/money-out-requests/by-source/{source_type}/{source_id}</code></pre>
                <p>Required scope: <code>payouts:read</code></p>

                <h3>Important fields</h3>
                <table>
                    <thead><tr><th>Field</th><th>Required</th><th>Notes</th></tr></thead>
                    <tbody>
                        <tr><td><code>source_type</code></td><td>Yes</td><td>Integration-specific category, such as <code>bingo_prize</code>.</td></tr>
                        <tr><td><code>source_id</code></td><td>Yes</td><td>Stable unique ID from the source app.</td></tr>
                        <tr><td><code>payee_rsn</code></td><td>Yes</td><td>Winner, vendor, or admin being paid.</td></tr>
                        <tr><td><code>amount</code></td><td>Yes</td><td>Integer GP.</td></tr>
                        <tr><td><code>description</code></td><td>Recommended</td><td>Shown to treasury admins.</td></tr>
                        <tr><td><code>expense_account_code</code></td><td>Yes</td><td>Must be an active posting Expense GL account.</td></tr>
                        <tr><td><code>metadata</code></td><td>No</td><td>JSON object for source app context.</td></tr>
                        <tr><td><code>paid_by_admin_rsn</code></td><td>No</td><td>When supplied with <code>payouts:pay</code>, creates the request as already paid by that active treasury user.</td></tr>
                        <tr><td><code>paid_at</code></td><td>No</td><td>ISO-8601 date/time of payment. Defaults to current server time.</td></tr>
                    </tbody>
                </table>
            </section>

            <section id="admin-rsns" class="card api-doc-section">
                <h2>Admin RSNs</h2>
                <p>Use this endpoint when an integration needs to populate or validate the admin RSNs accepted by <code>received_by_admin_rsn</code> and <code>paid_by_admin_rsn</code>.</p>

                <h3>List valid admin RSNs</h3>
                <pre><code>GET <?= h($apiBase) ?>/admin-rsns
GET <?= h($apiBase) ?>/admins/rsns</code></pre>
                <p>Required scope: one of <code>admins:read</code>, <code>payments:receive</code>, or <code>payouts:pay</code></p>

                <h3>Response example</h3>
                <pre><code>{
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
    { "rsn": "Main RSN", "admin_id": 1, "display_name": "Display Name", "is_primary": true },
    { "rsn": "Alt RSN", "admin_id": 1, "display_name": "Display Name", "is_primary": false }
  ],
  "accepted_fields": ["received_by_admin_rsn", "paid_by_admin_rsn"]
}</code></pre>

                <h3>Important fields</h3>
                <table>
                    <thead><tr><th>Field</th><th>Notes</th></tr></thead>
                    <tbody>
                        <tr><td><code>admins</code></td><td>Grouped active treasury users/admins, including their primary RSN and all active RSNs.</td></tr>
                        <tr><td><code>admins[].rsns</code></td><td>All active RSNs that map to that treasury user. Any listed RSN can be used by integrations.</td></tr>
                        <tr><td><code>rsns</code></td><td>Flat list for dropdowns, autocomplete, or simple validation.</td></tr>
                        <tr><td><code>accepted_fields</code></td><td>Payload fields that accept these RSN values.</td></tr>
                    </tbody>
                </table>
            </section>

            <section id="balances" class="card api-doc-section">
                <h2>Balances</h2>
                <pre><code>GET <?= h($apiBase) ?>/balances</code></pre>
                <p>Required scope: <code>balances:read</code></p>
                <p>Returns current official treasury, admin-held pending funds, reimbursements payable, and total clan GP summary.</p>
            </section>

            <section id="statuses" class="card api-doc-section">
                <h2>Request statuses</h2>
                <div class="grid two">
                    <div>
                        <h3>Money In</h3>
                        <table>
                            <tbody>
                                <tr><td><code>pending</code></td><td>Created, not yet received.</td></tr>
                                <tr><td><code>received_by_admin</code></td><td>An admin has received the GP.</td></tr>
                                <tr><td><code>reconciled_to_treasury</code></td><td>Received GP has been moved into the official treasury.</td></tr>
                                <tr><td><code>cancelled</code></td><td>Request was cancelled before GP moved.</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div>
                        <h3>Money Out</h3>
                        <table>
                            <tbody>
                                <tr><td><code>pending</code></td><td>Created, not yet paid.</td></tr>
                                <tr><td><code>paid_from_treasury</code></td><td>Paid directly from official treasury.</td></tr>
                                <tr><td><code>paid_by_admin</code></td><td>Admin paid personally and may need reimbursement.</td></tr>
                                <tr><td><code>reimbursed</code></td><td>Admin-paid amount has been reimbursed.</td></tr>
                                <tr><td><code>cancelled</code></td><td>Request was cancelled before GP moved.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <section id="errors" class="card api-doc-section">
                <h2>Errors</h2>
                <p>Errors are returned as JSON with a message and status code.</p>
                <pre><code>{
  "error": "revenue_account_code is required"
}</code></pre>
                <table>
                    <thead><tr><th>Status</th><th>Meaning</th></tr></thead>
                    <tbody>
                        <tr><td><code>401</code></td><td>Missing or invalid API key.</td></tr>
                        <tr><td><code>403</code></td><td>API key does not have the required scope.</td></tr>
                        <tr><td><code>404</code></td><td>Record or endpoint not found.</td></tr>
                        <tr><td><code>409</code></td><td>State conflict.</td></tr>
                        <tr><td><code>422</code></td><td>Validation error.</td></tr>
                        <tr><td><code>500</code></td><td>Unexpected server error.</td></tr>
                    </tbody>
                </table>
            </section>

            <section id="integration-checklist" class="card api-doc-section">
                <h2>Integration checklist</h2>
                <ol>
                    <li>Create a source app in Treasury.</li>
                    <li>Create the required Revenue and Expense GL accounts in Chart of Accounts.</li>
                    <li>Generate an API key with the minimum scopes needed.</li>
                    <li>Call <code>GET /api/v1/me</code> from the source app to confirm authentication.</li>
                    <li>Create Money In/Out requests using stable <code>source_type</code> and <code>source_id</code> values.</li>
                    <li>Use <code>GET /api/v1/admin-rsns</code> to validate or populate admin RSNs before sending admin receipt/payment fields.</li>
                    <li>If the source app already knows an admin physically received the GP, include <code>received_by_admin_rsn</code> and grant <code>payments:receive</code>.</li>
                    <li>If the source app already knows an admin paid outgoing GP from their own funds, include <code>paid_by_admin_rsn</code> and grant <code>payouts:pay</code>.</li>
                    <li>Poll by UUID or by source reference to display Treasury status inside the source app.</li>
                    <li>Let Treasury admins handle treasury handovers/reconciliation, reimbursements, and corrections from the Treasury UI.</li>
                </ol>
            </section>
        </div>
    </section>


    </main>
</body>
</html>
