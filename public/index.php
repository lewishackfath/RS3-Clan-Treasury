<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use Treasury\Auth\DiscordOAuth;
use Treasury\Services\AdminService;
use Treasury\Services\AppService;
use Treasury\Services\BalanceService;
use Treasury\Services\ManualLedgerService;
use Treasury\Services\PaymentRequestService;
use Treasury\Services\PayoutRequestService;
use Treasury\Services\ReconciliationService;
use Treasury\Services\TreasuryQueryService;
use Treasury\Support\Env;
use Treasury\Support\GP;
use Treasury\Web\AdminSession;
use Treasury\Web\Csrf;
use Treasury\Web\Flash;

AdminSession::start();

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function url_for(string $page, array $params = []): string
{
    return '?' . http_build_query(array_merge(['page' => $page], $params));
}

function redirect_to(string $page, array $params = []): never
{
    header('Location: ' . url_for($page, $params));
    exit;
}

function current_page(): string
{
    return preg_replace('/[^a-z0-9_\-]/i', '', (string)($_GET['page'] ?? 'dashboard')) ?: 'dashboard';
}

function local_datetime(?string $value): string
{
    if (!$value) {
        return '—';
    }
    try {
        $tz = new DateTimeZone(Env::get('APP_TIMEZONE', 'Australia/Sydney'));
        $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        return $dt->setTimezone($tz)->format('d M Y, g:ia');
    } catch (Throwable) {
        return $value;
    }
}

function badge(string $status): string
{
    $safe = h($status);
    $class = 'badge badge-' . strtolower(str_replace('_', '-', $status));
    return '<span class="' . h($class) . '">' . $safe . '</span>';
}

function require_acting_admin(): int
{
    $adminId = AdminSession::actingAdminId();
    if (!$adminId) {
        throw new RuntimeException('Select an acting treasury admin before posting treasury actions.');
    }
    return $adminId;
}

function selected_total_for_reconciliation(int $adminId, array $uuids): int
{
    $uuids = array_values(array_unique(array_filter(array_map('strval', $uuids))));
    if (!$uuids) {
        throw new InvalidArgumentException('Select at least one payment to reconcile.');
    }

    $rows = (new TreasuryQueryService())->unreconciledPaymentsByAdmin($adminId);
    $total = 0;
    $seen = [];
    foreach ($rows as $row) {
        if (in_array($row['request_uuid'], $uuids, true)) {
            $total += (int)$row['amount'];
            $seen[] = $row['request_uuid'];
        }
    }

    sort($seen);
    $check = $uuids;
    sort($check);
    if ($seen !== $check) {
        throw new RuntimeException('One or more selected payments cannot be reconciled for that admin.');
    }

    return $total;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $preflightPage = current_page();

    if ($preflightPage === 'discord_login') {
        try {
            header('Location: ' . (new DiscordOAuth())->authorizationUrl());
            exit;
        } catch (Throwable $e) {
            Flash::add('error', $e->getMessage());
            redirect_to('login');
        }
    }

    if ($preflightPage === 'discord_callback') {
        try {
            $result = (new DiscordOAuth())->handleCallback($_GET);
            AdminSession::loginWithDiscord($result['user'], $result['authorisation'] ?? null);

            $matchedAdmin = AdminSession::discordUserId()
                ? (new AdminService())->findByDiscordUserId(AdminSession::discordUserId())
                : null;
            $actorAdminId = $matchedAdmin ? (int)$matchedAdmin['id'] : null;
            Treasury\Services\AuditService::log(
                'auth.discord_login',
                'discord_user',
                (string)($result['user']['id'] ?? 'unknown'),
                null,
                [
                    'username' => $result['user']['username'] ?? null,
                    'global_name' => $result['user']['global_name'] ?? null,
                    'authorisation_method' => $result['authorisation']['method'] ?? null,
                    'matched_admin_id' => $actorAdminId,
                ],
                null,
                $actorAdminId
            );

            Flash::add('success', 'Signed in with Discord.');
            redirect_to('dashboard');
        } catch (Throwable $e) {
            Flash::add('error', $e->getMessage());
            redirect_to('login');
        }
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'login') {
            Csrf::validate();
            if (!AdminSession::login((string)($_POST['password'] ?? ''))) {
                Flash::add('error', 'Invalid admin password.');
                redirect_to('login');
            }
            Flash::add('success', 'Logged in.');
            redirect_to('dashboard');
        }

        if ($action === 'logout') {
            Csrf::validate();
            AdminSession::logout();
            header('Location: ?page=login');
            exit;
        }

        AdminSession::requireLogin();
        Csrf::validate();

        switch ($action) {
            case 'set_acting_admin':
                $adminId = (int)($_POST['admin_id'] ?? 0);
                if ($adminId <= 0) {
                    throw new InvalidArgumentException('Choose a treasury admin.');
                }
                (new AdminService())->get($adminId);
                AdminSession::setActingAdminId($adminId);
                Flash::add('success', 'Acting admin updated.');
                redirect_to(current_page());

            case 'create_admin':
                $admin = (new AdminService())->create($_POST);
                if (!AdminSession::actingAdminId()) {
                    AdminSession::setActingAdminId((int)$admin['id']);
                }
                Flash::add('success', 'Treasury admin created.');
                redirect_to('settings');

            case 'create_app':
                (new AppService())->create($_POST);
                Flash::add('success', 'Source app saved.');
                redirect_to('settings');

            case 'opening_balance':
                (new ManualLedgerService())->openingBalance([
                    'admin_id' => require_acting_admin(),
                    'amount' => $_POST['amount'] ?? '',
                    'description' => $_POST['description'] ?? 'Opening official treasury balance',
                    'notes' => $_POST['notes'] ?? null,
                    'occurred_at' => (($_POST['occurred_at'] ?? '') ?: 'now'),
                ]);
                Flash::add('success', 'Opening balance posted.');
                redirect_to('dashboard');

            case 'expense_from_treasury':
                (new ManualLedgerService())->expenseFromTreasury([
                    'admin_id' => require_acting_admin(),
                    'amount' => $_POST['amount'] ?? '',
                    'description' => $_POST['description'] ?? 'Manual treasury expense',
                    'player_rsn' => $_POST['player_rsn'] ?? null,
                    'notes' => $_POST['notes'] ?? null,
                    'occurred_at' => (($_POST['occurred_at'] ?? '') ?: 'now'),
                ]);
                Flash::add('success', 'Treasury expense posted.');
                redirect_to('dashboard');

            case 'admin_paid_expense':
                (new ManualLedgerService())->adminPaidExpense([
                    'posted_by_admin_id' => require_acting_admin(),
                    'paid_by_admin_id' => (int)($_POST['paid_by_admin_id'] ?? 0),
                    'amount' => $_POST['amount'] ?? '',
                    'description' => $_POST['description'] ?? 'Admin-paid expense',
                    'player_rsn' => $_POST['player_rsn'] ?? null,
                    'notes' => $_POST['notes'] ?? null,
                    'occurred_at' => (($_POST['occurred_at'] ?? '') ?: 'now'),
                ]);
                Flash::add('success', 'Admin-paid expense posted.');
                redirect_to('dashboard');

            case 'manual_reimburse_admin':
                (new ManualLedgerService())->reimburseAdmin([
                    'posted_by_admin_id' => require_acting_admin(),
                    'reimbursed_admin_id' => (int)($_POST['reimbursed_admin_id'] ?? 0),
                    'amount' => $_POST['amount'] ?? '',
                    'description' => $_POST['description'] ?? 'Manual admin reimbursement',
                    'notes' => $_POST['notes'] ?? null,
                    'occurred_at' => (($_POST['occurred_at'] ?? '') ?: 'now'),
                ]);
                Flash::add('success', 'Admin reimbursement posted.');
                redirect_to('dashboard');

            case 'create_payment_request':
                $appId = (int)($_POST['app_id'] ?? 0);
                $purpose = (string)($_POST['purpose'] ?? 'entry_fee');
                $sourceType = trim((string)($_POST['source_type'] ?? ''));
                if ($sourceType === '') {
                    $sourceType = $purpose === 'entry_fee' ? 'manual_entry_fee' : 'manual_contribution';
                }
                $sourceId = trim((string)($_POST['source_id'] ?? '')) ?: ('manual-payment-' . time() . '-' . bin2hex(random_bytes(3)));
                (new PaymentRequestService())->create((new AppService())->manualContext($appId), [
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'player_rsn' => $_POST['player_rsn'] ?? '',
                    'amount' => GP::parse($_POST['amount'] ?? ''),
                    'purpose' => $purpose,
                    'description' => $_POST['description'] ?? null,
                    'metadata' => ['created_from' => 'admin_ui'],
                ]);
                Flash::add('success', 'Payment request created.');
                redirect_to('payments');

            case 'receive_payment_request':
                (new PaymentRequestService())->receive((string)($_POST['request_uuid'] ?? ''), [
                    'admin_id' => require_acting_admin(),
                    'received_at' => (($_POST['received_at'] ?? '') ?: 'now'),
                    'notes' => $_POST['notes'] ?? null,
                ]);
                Flash::add('success', 'Payment marked as received by admin.');
                redirect_to('payments');

            case 'create_payout_request':
                $appId = (int)($_POST['app_id'] ?? 0);
                $payoutType = (string)($_POST['payout_type'] ?? 'prize');
                $sourceType = trim((string)($_POST['source_type'] ?? ''));
                if ($sourceType === '') {
                    $sourceType = $payoutType === 'prize' ? 'manual_prize' : 'manual_expense';
                }
                $sourceId = trim((string)($_POST['source_id'] ?? '')) ?: ('manual-payout-' . time() . '-' . bin2hex(random_bytes(3)));
                (new PayoutRequestService())->create((new AppService())->manualContext($appId), [
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'payee_rsn' => $_POST['payee_rsn'] ?? '',
                    'amount' => GP::parse($_POST['amount'] ?? ''),
                    'payout_type' => $payoutType,
                    'description' => $_POST['description'] ?? null,
                    'metadata' => ['created_from' => 'admin_ui'],
                ]);
                Flash::add('success', 'Payout request created.');
                redirect_to('payouts');

            case 'payout_from_treasury':
                (new PayoutRequestService())->payFromTreasury((string)($_POST['request_uuid'] ?? ''), [
                    'admin_id' => require_acting_admin(),
                    'paid_at' => (($_POST['paid_at'] ?? '') ?: 'now'),
                    'notes' => $_POST['notes'] ?? null,
                ]);
                Flash::add('success', 'Payout marked as paid from official treasury.');
                redirect_to('payouts');

            case 'payout_by_admin':
                (new PayoutRequestService())->payByAdmin((string)($_POST['request_uuid'] ?? ''), [
                    'admin_id' => require_acting_admin(),
                    'paid_at' => (($_POST['paid_at'] ?? '') ?: 'now'),
                    'notes' => $_POST['notes'] ?? null,
                ]);
                Flash::add('success', 'Payout marked as paid by admin.');
                redirect_to('payouts');

            case 'reimburse_payout':
                (new PayoutRequestService())->reimburseAdmin((string)($_POST['request_uuid'] ?? ''), [
                    'admin_id' => require_acting_admin(),
                    'reimbursed_at' => (($_POST['reimbursed_at'] ?? '') ?: 'now'),
                    'notes' => $_POST['notes'] ?? null,
                ]);
                Flash::add('success', 'Payout reimbursement posted.');
                redirect_to('payouts');

            case 'reconcile_payments':
                $fromAdminId = (int)($_POST['from_admin_id'] ?? 0);
                $uuids = $_POST['payment_request_uuids'] ?? [];
                if (!is_array($uuids)) {
                    $uuids = [];
                }
                $amount = selected_total_for_reconciliation($fromAdminId, $uuids);
                (new ReconciliationService())->complete([
                    'from_admin_id' => $fromAdminId,
                    'completed_by_admin_id' => require_acting_admin(),
                    'amount' => $amount,
                    'notes' => $_POST['notes'] ?? null,
                    'completed_at' => (($_POST['completed_at'] ?? '') ?: 'now'),
                    'payment_request_uuids' => $uuids,
                ]);
                Flash::add('success', 'Selected admin-held payments reconciled into the official treasury.');
                redirect_to('reconciliation', ['admin_id' => $fromAdminId]);
        }

        throw new RuntimeException('Unknown action.');
    } catch (Throwable $e) {
        Flash::add('error', $e->getMessage());
        redirect_to(current_page());
    }
}

$appName = Env::get('APP_NAME', 'RS3 GP Treasury');
$page = current_page();
$loggedIn = AdminSession::isLoggedIn();
if (!$loggedIn && !in_array($page, ['login', 'discord_login', 'discord_callback'], true)) {
    $page = 'login';
}

$adminService = new AdminService();
$appService = new AppService();
$query = new TreasuryQueryService();
$admins = $loggedIn ? $adminService->all(true) : [];
$apps = $loggedIn ? $appService->all(true) : [];

?><!doctype html>
<html lang="en-AU">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($appName) ?></title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<?php if ($loggedIn): ?>
    <aside class="sidebar">
        <div class="brand">
            <span class="brand-mark">◇</span>
            <div>
                <strong><?= h($appName) ?></strong>
                <small>RS3 GP Ledger</small>
            </div>
        </div>
        <nav>
            <?php foreach ([
                'dashboard' => 'Dashboard',
                'payments' => 'Payments',
                'payouts' => 'Payouts',
                'reconciliation' => 'Reconciliation',
                'transactions' => 'Ledger',
                'settings' => 'Settings',
            ] as $key => $label): ?>
                <a class="<?= $page === $key ? 'active' : '' ?>" href="<?= h(url_for($key)) ?>"><?= h($label) ?></a>
            <?php endforeach; ?>
        </nav>
    </aside>
<?php endif; ?>

<main class="<?= $loggedIn ? 'app-shell' : 'login-shell' ?>">
    <?php if ($loggedIn): ?>
        <header class="topbar">
            <div>
                <h1><?= h(ucwords(str_replace('_', ' ', $page))) ?></h1>
                <p>Standalone treasury control for clan GP.</p>
            </div>
            <div class="topbar-actions">
                <div class="user-pill">
                    <span><?= h(AdminSession::authMethod() === 'discord' ? 'Discord' : 'Signed in') ?></span>
                    <strong><?= h(AdminSession::displayName()) ?></strong>
                    <?php if (AdminSession::discordUserId()): ?><small>ID: <?= h(AdminSession::discordUserId()) ?></small><?php endif; ?>
                </div>
                <form method="post" class="inline-form">
                    <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
                    <input type="hidden" name="action" value="set_acting_admin">
                    <label>
                        Acting admin
                        <select name="admin_id" onchange="this.form.submit()">
                            <option value="">Select…</option>
                            <?php foreach ($admins as $admin): ?>
                                <option value="<?= (int)$admin['id'] ?>" <?= AdminSession::actingAdminId() === (int)$admin['id'] ? 'selected' : '' ?>>
                                    <?= h($admin['display_name'] ?: $admin['rsn']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </form>
                <form method="post">
                    <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
                    <input type="hidden" name="action" value="logout">
                    <button class="button ghost" type="submit">Log out</button>
                </form>
            </div>
        </header>
    <?php endif; ?>

    <?php foreach (Flash::all() as $flash): ?>
        <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
    <?php endforeach; ?>

    <?php if ($page === 'login'): ?>
        <?php render_login($appName); ?>
    <?php elseif ($page === 'dashboard'): ?>
        <?php render_dashboard($query, $admins); ?>
    <?php elseif ($page === 'payments'): ?>
        <?php render_payments($query, $apps); ?>
    <?php elseif ($page === 'payouts'): ?>
        <?php render_payouts($query, $apps, $admins); ?>
    <?php elseif ($page === 'reconciliation'): ?>
        <?php render_reconciliation($query, $admins); ?>
    <?php elseif ($page === 'transactions'): ?>
        <?php render_transactions($query, $apps); ?>
    <?php elseif ($page === 'settings'): ?>
        <?php render_settings($admins, $apps); ?>
    <?php else: ?>
        <section class="card"><h2>Page not found</h2></section>
    <?php endif; ?>
</main>
</body>
</html>
<?php

function render_login(string $appName): void
{
    $discordEnabled = DiscordOAuth::enabled();
    $passwordLoginEnabled = AdminSession::passwordLoginEnabled();
    ?>
    <section class="login-card">
        <div class="brand large"><span class="brand-mark">◇</span><div><strong><?= h($appName) ?></strong><small>RS3 GP Accounting</small></div></div>
        <?php if ($discordEnabled): ?>
            <p class="muted">Sign in with Discord to manage the treasury. Your Discord user must be linked to a treasury admin, listed as an owner, or hold an allowed Discord role.</p>
            <a class="button primary full-button" href="<?= h(url_for('discord_login')) ?>">Sign in with Discord</a>
            <?php if ($passwordLoginEnabled): ?>
                <details class="fallback-login">
                    <summary>Use fallback password login</summary>
                    <form method="post" class="stacked-form">
                        <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
                        <input type="hidden" name="action" value="login">
                        <label>Admin password <input type="password" name="password"></label>
                        <button class="button" type="submit">Open treasury with password</button>
                    </form>
                </details>
            <?php endif; ?>
        <?php else: ?>
            <?php if ($passwordLoginEnabled): ?>
                <p class="muted">Sign in with the temporary admin UI password from your <code>.env</code> file.</p>
                <form method="post" class="stacked-form">
                    <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
                    <input type="hidden" name="action" value="login">
                    <label>Admin password <input type="password" name="password" autofocus required></label>
                    <button class="button primary" type="submit">Open treasury</button>
                </form>
            <?php else: ?>
                <p class="muted warning-text">Password login is disabled and Discord OAuth is not enabled. Enable Discord OAuth or re-enable password login in <code>.env</code>.</p>
            <?php endif; ?>
        <?php endif; ?>
    </section>
    <?php
}

function render_dashboard(TreasuryQueryService $query, array $admins): void
{
    $balances = (new BalanceService())->summary();
    $stats = $query->dashboardStats();
    $transactions = $query->transactions([], 8);
    ?>
    <?php if (!$admins): ?>
        <section class="notice-card">
            <h2>Create your first treasury admin</h2>
            <p>No admins exist yet. Open Settings and create yourself as the first admin before posting treasury actions.</p>
            <a class="button primary" href="<?= h(url_for('settings')) ?>">Open settings</a>
        </section>
    <?php endif; ?>

    <section class="metric-grid">
        <div class="metric"><span>Official Treasury</span><strong><?= h(GP::format($balances['official_treasury'])) ?></strong></div>
        <div class="metric"><span>Admin-held Pending</span><strong><?= h(GP::format($balances['admin_held_pending'])) ?></strong></div>
        <div class="metric"><span>Reimbursements Owed</span><strong><?= h(GP::format($balances['admin_reimbursements_payable'])) ?></strong></div>
        <div class="metric"><span>Total Clan GP</span><strong><?= h(GP::format($balances['total_clan_gp'])) ?></strong></div>
    </section>

    <section class="grid two">
        <div class="card">
            <h2>Work queue</h2>
            <div class="queue-grid">
                <a href="<?= h(url_for('payments', ['status' => 'pending'])) ?>"><strong><?= (int)$stats['pending_payments'] ?></strong><span>pending payments</span></a>
                <a href="<?= h(url_for('payments', ['status' => 'received_by_admin'])) ?>"><strong><?= (int)$stats['received_unreconciled_payments'] ?></strong><span>received, unreconciled</span></a>
                <a href="<?= h(url_for('payouts', ['status' => 'pending'])) ?>"><strong><?= (int)$stats['pending_payouts'] ?></strong><span>pending payouts</span></a>
                <a href="<?= h(url_for('payouts', ['status' => 'paid_by_admin'])) ?>"><strong><?= (int)$stats['admin_paid_unreimbursed'] ?></strong><span>admin-paid, unreimbursed</span></a>
            </div>
        </div>
        <div class="card">
            <h2>Admin-held funds</h2>
            <?php if (!$balances['admin_held_breakdown']): ?>
                <p class="muted">No admin-held GP recorded yet.</p>
            <?php else: ?>
                <table>
                    <thead><tr><th>Admin</th><th class="right">Held GP</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($balances['admin_held_breakdown'] as $row): ?>
                        <tr>
                            <td><?= h($row['display_name'] ?: $row['rsn']) ?></td>
                            <td class="right amount"><?= h(GP::format($row['balance'])) ?></td>
                            <td class="right"><a href="<?= h(url_for('reconciliation', ['admin_id' => (int)$row['admin_id']])) ?>">Reconcile</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </section>

    <section class="grid two">
        <div class="card">
            <h2>Manual treasury actions</h2>
            <details open>
                <summary>Set or increase official treasury balance</summary>
                <form method="post" class="stacked-form compact">
                    <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
                    <input type="hidden" name="action" value="opening_balance">
                    <label>Amount <input name="amount" placeholder="1.25b" required></label>
                    <label>Description <input name="description" value="Opening official treasury balance"></label>
                    <button class="button primary" type="submit">Post balance adjustment</button>
                </form>
            </details>
            <details>
                <summary>Record expense from official treasury</summary>
                <form method="post" class="stacked-form compact">
                    <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
                    <input type="hidden" name="action" value="expense_from_treasury">
                    <label>Amount <input name="amount" placeholder="25m" required></label>
                    <label>Description <input name="description" placeholder="Event supplies / prize top-up" required></label>
                    <label>Related RSN <input name="player_rsn" placeholder="Optional"></label>
                    <button class="button" type="submit">Post expense</button>
                </form>
            </details>
        </div>
        <div class="card">
            <h2>Admin reimbursements</h2>
            <details open>
                <summary>Admin paid an expense personally</summary>
                <form method="post" class="stacked-form compact">
                    <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
                    <input type="hidden" name="action" value="admin_paid_expense">
                    <label>Paid by admin <?= admin_select('paid_by_admin_id') ?></label>
                    <label>Amount <input name="amount" placeholder="10m" required></label>
                    <label>Description <input name="description" placeholder="What was paid?" required></label>
                    <button class="button" type="submit">Record amount owed</button>
                </form>
            </details>
            <details>
                <summary>Reimburse an admin manually</summary>
                <form method="post" class="stacked-form compact">
                    <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
                    <input type="hidden" name="action" value="manual_reimburse_admin">
                    <label>Reimbursed admin <?= admin_select('reimbursed_admin_id') ?></label>
                    <label>Amount <input name="amount" placeholder="10m" required></label>
                    <label>Description <input name="description" value="Manual admin reimbursement"></label>
                    <button class="button" type="submit">Post reimbursement</button>
                </form>
            </details>
        </div>
    </section>

    <section class="card">
        <h2>Recent ledger activity</h2>
        <?php render_transaction_table($transactions); ?>
    </section>
    <?php
}

function render_payments(TreasuryQueryService $query, array $apps): void
{
    $filters = [
        'status' => $_GET['status'] ?? '',
        'app_id' => $_GET['app_id'] ?? '',
        'q' => $_GET['q'] ?? '',
    ];
    $rows = $query->paymentRequests($filters, 150);
    ?>
    <section class="card">
        <h2>Create payment request</h2>
        <p class="muted">Use this for entry fees, clan contributions, or any GP expected from a player before it is received.</p>
        <form method="post" class="grid-form">
            <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
            <input type="hidden" name="action" value="create_payment_request">
            <label>Source app <?= app_select($apps, 'app_id') ?></label>
            <label>Purpose
                <select name="purpose"><option value="entry_fee">Entry fee</option><option value="clan_contribution">Clan contribution</option><option value="other">Other</option></select>
            </label>
            <label>Player RSN <input name="player_rsn" required></label>
            <label>Amount <input name="amount" placeholder="10m" required></label>
            <label>Description <input name="description" placeholder="Bingo entry fee - Game 1"></label>
            <label>Source type <input name="source_type" placeholder="Optional"></label>
            <label>Source ID <input name="source_id" placeholder="Optional; auto-generated if blank"></label>
            <div class="form-actions"><button class="button primary" type="submit">Create payment request</button></div>
        </form>
    </section>

    <section class="card">
        <div class="section-header"><h2>Payment requests</h2><?php render_filters('payments', $apps, ['pending','received_by_admin','reconciled_to_treasury','cancelled']); ?></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Created</th><th>Source</th><th>Player</th><th>Description</th><th class="right">Amount</th><th>Status</th><th>Received by</th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= h(local_datetime($row['created_at'])) ?></td>
                        <td><strong><?= h($row['app_name']) ?></strong><small><?= h($row['source_type']) ?> / <?= h($row['source_id']) ?></small></td>
                        <td><?= h($row['player_rsn']) ?></td>
                        <td><?= h($row['description']) ?></td>
                        <td class="right amount"><?= h(GP::format($row['amount'])) ?></td>
                        <td><?= badge($row['status']) ?></td>
                        <td><?= h($row['received_by_display_name'] ?: $row['received_by_rsn'] ?: '—') ?></td>
                        <td>
                            <?php if ($row['status'] === 'pending'): ?>
                                <form method="post" class="row-action">
                                    <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
                                    <input type="hidden" name="action" value="receive_payment_request">
                                    <input type="hidden" name="request_uuid" value="<?= h($row['request_uuid']) ?>">
                                    <button class="button small" type="submit">Mark received</button>
                                </form>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><tr><td colspan="8" class="empty">No payment requests found.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php
}

function render_payouts(TreasuryQueryService $query, array $apps, array $admins): void
{
    $filters = [
        'status' => $_GET['status'] ?? '',
        'app_id' => $_GET['app_id'] ?? '',
        'q' => $_GET['q'] ?? '',
    ];
    $rows = $query->payoutRequests($filters, 150);
    ?>
    <section class="card">
        <h2>Create payout request</h2>
        <p class="muted">Use this for prizes, GP expenses owed to a player, or reimbursements that need approval/payment.</p>
        <form method="post" class="grid-form">
            <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
            <input type="hidden" name="action" value="create_payout_request">
            <label>Source app <?= app_select($apps, 'app_id') ?></label>
            <label>Type
                <select name="payout_type"><option value="prize">Prize</option><option value="expense">Expense</option><option value="admin_reimbursement">Admin reimbursement</option></select>
            </label>
            <label>Payee RSN <input name="payee_rsn" required></label>
            <label>Amount <input name="amount" placeholder="50m" required></label>
            <label>Description <input name="description" placeholder="Bingo row prize - Game 1"></label>
            <label>Source type <input name="source_type" placeholder="Optional"></label>
            <label>Source ID <input name="source_id" placeholder="Optional; auto-generated if blank"></label>
            <div class="form-actions"><button class="button primary" type="submit">Create payout request</button></div>
        </form>
    </section>

    <section class="card">
        <div class="section-header"><h2>Payout requests</h2><?php render_filters('payouts', $apps, ['pending','paid_from_treasury','paid_by_admin','reimbursed','cancelled']); ?></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Created</th><th>Source</th><th>Payee</th><th>Description</th><th class="right">Amount</th><th>Status</th><th>Admin</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= h(local_datetime($row['created_at'])) ?></td>
                        <td><strong><?= h($row['app_name']) ?></strong><small><?= h($row['source_type']) ?> / <?= h($row['source_id']) ?></small></td>
                        <td><?= h($row['payee_rsn']) ?></td>
                        <td><?= h($row['description']) ?></td>
                        <td class="right amount"><?= h(GP::format($row['amount'])) ?></td>
                        <td><?= badge($row['status']) ?></td>
                        <td><?= h($row['paid_by_display_name'] ?: $row['paid_by_rsn'] ?: '—') ?></td>
                        <td class="actions-cell">
                            <?php if ($row['status'] === 'pending'): ?>
                                <form method="post" class="row-action"><input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>"><input type="hidden" name="action" value="payout_from_treasury"><input type="hidden" name="request_uuid" value="<?= h($row['request_uuid']) ?>"><button class="button small" type="submit">Paid from treasury</button></form>
                                <form method="post" class="row-action"><input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>"><input type="hidden" name="action" value="payout_by_admin"><input type="hidden" name="request_uuid" value="<?= h($row['request_uuid']) ?>"><button class="button small ghost" type="submit">Paid by admin</button></form>
                            <?php elseif ($row['status'] === 'paid_by_admin'): ?>
                                <form method="post" class="row-action"><input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>"><input type="hidden" name="action" value="reimburse_payout"><input type="hidden" name="request_uuid" value="<?= h($row['request_uuid']) ?>"><button class="button small" type="submit">Reimburse</button></form>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><tr><td colspan="8" class="empty">No payout requests found.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php
}

function render_reconciliation(TreasuryQueryService $query, array $admins): void
{
    $selectedAdminId = (int)($_GET['admin_id'] ?? AdminSession::actingAdminId() ?? ($admins[0]['id'] ?? 0));
    $rows = $query->unreconciledPaymentsByAdmin($selectedAdminId > 0 ? $selectedAdminId : null);
    $total = array_sum(array_map(fn($row) => (int)$row['amount'], $rows));
    ?>
    <section class="card">
        <h2>Reconcile admin-held funds into official treasury</h2>
        <p class="muted">Select received payments that have physically been moved from an admin to the official treasury.</p>
        <form method="get" class="filter-form">
            <input type="hidden" name="page" value="reconciliation">
            <label>Admin <?= admin_select('admin_id', $selectedAdminId, true) ?></label>
            <button class="button" type="submit">Filter</button>
        </form>
    </section>

    <section class="card">
        <div class="section-header"><h2>Unreconciled received payments</h2><span class="pill">Visible total: <?= h(GP::format($total)) ?></span></div>
        <?php if (!$rows): ?>
            <p class="empty">No received payments are waiting for reconciliation.</p>
        <?php else: ?>
            <form method="post">
                <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
                <input type="hidden" name="action" value="reconcile_payments">
                <input type="hidden" name="from_admin_id" value="<?= (int)$selectedAdminId ?>">
                <div class="table-wrap">
                    <table>
                        <thead><tr><th></th><th>Received</th><th>Admin</th><th>Source</th><th>Player</th><th>Description</th><th class="right">Amount</th></tr></thead>
                        <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><input type="checkbox" name="payment_request_uuids[]" value="<?= h($row['request_uuid']) ?>" checked></td>
                                <td><?= h(local_datetime($row['received_at'])) ?></td>
                                <td><?= h($row['received_by_display_name'] ?: $row['received_by_rsn']) ?></td>
                                <td><?= h($row['app_name']) ?></td>
                                <td><?= h($row['player_rsn']) ?></td>
                                <td><?= h($row['description']) ?></td>
                                <td class="right amount"><?= h(GP::format($row['amount'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <label class="full-width">Notes <textarea name="notes" placeholder="Optional reconciliation note"></textarea></label>
                <div class="form-actions"><button class="button primary" type="submit">Reconcile selected payments</button></div>
            </form>
        <?php endif; ?>
    </section>
    <?php
}

function render_transactions(TreasuryQueryService $query, array $apps): void
{
    $filters = [
        'transaction_type' => $_GET['transaction_type'] ?? '',
        'app_id' => $_GET['app_id'] ?? '',
        'q' => $_GET['q'] ?? '',
    ];
    $transactions = $query->transactions($filters, 200);
    ?>
    <section class="card">
        <div class="section-header"><h2>Ledger transactions</h2></div>
        <form method="get" class="filter-form">
            <input type="hidden" name="page" value="transactions">
            <label>Type
                <select name="transaction_type"><option value="">All</option>
                    <?php foreach (['entry_fee','contribution','prize_payout','expense','admin_reimbursement','reconciliation','adjustment','reversal'] as $type): ?>
                        <option value="<?= h($type) ?>" <?= ($filters['transaction_type'] === $type) ? 'selected' : '' ?>><?= h($type) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Source app <?= app_select($apps, 'app_id', (int)($filters['app_id'] ?: 0), true) ?></label>
            <label>Search <input name="q" value="<?= h($filters['q']) ?>" placeholder="Player, source, description"></label>
            <button class="button" type="submit">Filter</button>
        </form>
        <?php render_transaction_table($transactions); ?>
    </section>
    <?php
}

function render_settings(array $admins, array $apps): void
{
    ?>
    <section class="card">
        <div class="section-header">
            <h2>Discord integration</h2>
            <span class="pill"><?= DiscordOAuth::enabled() ? 'Enabled' : 'Disabled' ?></span>
        </div>
        <p class="muted">Link each treasury admin to their Discord user ID. When Discord OAuth is enabled, a linked admin is automatically selected as the acting admin after login.</p>
        <div class="config-grid">
            <div><span>Client ID</span><strong><?= h(Env::get('DISCORD_CLIENT_ID', '') ?: 'Not set') ?></strong></div>
            <div><span>Guild ID</span><strong><?= h(Env::get('DISCORD_GUILD_ID', '') ?: 'Optional') ?></strong></div>
            <div><span>Redirect URI</span><strong><?= h(Env::get('DISCORD_REDIRECT_URI', '') ?: 'Not set') ?></strong></div>
            <div><span>Role IDs</span><strong><?= h(Env::get('DISCORD_ADMIN_ROLE_IDS', '') ?: 'Linked admins / owner IDs only') ?></strong></div>
        </div>
    </section>

    <section class="grid two">
        <div class="card">
            <h2>Treasury admins</h2>
            <form method="post" class="stacked-form compact">
                <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
                <input type="hidden" name="action" value="create_admin">
                <label>Display name <input name="display_name" placeholder="Lewis"></label>
                <label>RSN <input name="rsn" required></label>
                <label>Discord user ID <input name="discord_user_id" placeholder="Required for Discord auto-link"></label>
                <button class="button primary" type="submit">Create admin</button>
            </form>
            <table>
                <thead><tr><th>Name</th><th>RSN</th><th>Discord user ID</th></tr></thead>
                <tbody><?php foreach ($admins as $admin): ?><tr><td><?= h($admin['display_name']) ?></td><td><?= h($admin['rsn']) ?></td><td><?= h($admin['discord_user_id'] ?: '—') ?></td></tr><?php endforeach; ?></tbody>
            </table>
        </div>
        <div class="card">
            <h2>Source apps</h2>
            <form method="post" class="stacked-form compact">
                <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
                <input type="hidden" name="action" value="create_app">
                <label>Name <input name="name" placeholder="Bingo" required></label>
                <label>Slug <input name="slug" placeholder="bingo"></label>
                <label>Description <input name="description" placeholder="Optional"></label>
                <button class="button primary" type="submit">Save source app</button>
            </form>
            <table>
                <thead><tr><th>Name</th><th>Slug</th><th>Description</th></tr></thead>
                <tbody><?php foreach ($apps as $app): ?><tr><td><?= h($app['name']) ?></td><td><code><?= h($app['slug']) ?></code></td><td><?= h($app['description'] ?: '—') ?></td></tr><?php endforeach; ?></tbody>
            </table>
            <p class="muted">API keys remain CLI-managed for now. The admin application can still tag manual records to Bingo, Runes of Power, or any future app.</p>
        </div>
    </section>
    <?php
}

function render_filters(string $page, array $apps, array $statuses): void
{
    $status = (string)($_GET['status'] ?? '');
    $appId = (int)($_GET['app_id'] ?? 0);
    $q = (string)($_GET['q'] ?? '');
    ?>
    <form method="get" class="filter-form">
        <input type="hidden" name="page" value="<?= h($page) ?>">
        <label>Status <select name="status"><option value="">All</option><?php foreach ($statuses as $s): ?><option value="<?= h($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= h($s) ?></option><?php endforeach; ?></select></label>
        <label>App <?= app_select($apps, 'app_id', $appId, true) ?></label>
        <label>Search <input name="q" value="<?= h($q) ?>" placeholder="RSN, source, description"></label>
        <button class="button" type="submit">Filter</button>
    </form>
    <?php
}

function app_select(array $apps, string $name, int $selected = 0, bool $includeAll = false): string
{
    ob_start();
    ?>
    <select name="<?= h($name) ?>" <?= $includeAll ? '' : 'required' ?>>
        <?php if ($includeAll): ?><option value="">All</option><?php endif; ?>
        <?php foreach ($apps as $app): ?>
            <option value="<?= (int)$app['id'] ?>" <?= $selected === (int)$app['id'] ? 'selected' : '' ?>><?= h($app['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php
    return ob_get_clean();
}

function admin_select(string $name, int $selected = 0, bool $includeAll = false): string
{
    $admins = (new AdminService())->all(true);
    ob_start();
    ?>
    <select name="<?= h($name) ?>" <?= $includeAll ? '' : 'required' ?>>
        <?php if ($includeAll): ?><option value="">All admins</option><?php endif; ?>
        <?php foreach ($admins as $admin): ?>
            <option value="<?= (int)$admin['id'] ?>" <?= $selected === (int)$admin['id'] ? 'selected' : '' ?>><?= h($admin['display_name'] ?: $admin['rsn']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php
    return ob_get_clean();
}

function render_transaction_table(array $transactions): void
{
    if (!$transactions) {
        echo '<p class="empty">No ledger transactions found.</p>';
        return;
    }
    ?>
    <div class="transaction-list">
        <?php foreach ($transactions as $transaction): ?>
            <details class="transaction-card">
                <summary>
                    <span><strong><?= h($transaction['description']) ?></strong><small><?= h(local_datetime($transaction['occurred_at'])) ?> · <?= h($transaction['transaction_type']) ?> · <?= h($transaction['app_name'] ?: 'Manual') ?></small></span>
                    <span class="amount"><?= h(GP::format($transaction['amount'])) ?></span>
                </summary>
                <div class="ledger-lines">
                    <table>
                        <thead><tr><th>Account</th><th>Memo</th><th>RSN/Admin</th><th class="right">Debit</th><th class="right">Credit</th></tr></thead>
                        <tbody>
                        <?php foreach (($transaction['lines'] ?? []) as $line): ?>
                            <tr>
                                <td><code><?= h($line['account_code']) ?></code> <?= h($line['account_name']) ?></td>
                                <td><?= h($line['memo'] ?: '—') ?></td>
                                <td><?= h($line['player_rsn'] ?: ($line['admin_display_name'] ?: $line['admin_rsn'] ?: '—')) ?></td>
                                <td class="right amount"><?= $line['direction'] === 'debit' ? h(GP::format($line['amount'])) : '—' ?></td>
                                <td class="right amount"><?= $line['direction'] === 'credit' ? h(GP::format($line['amount'])) : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if (!empty($transaction['source_type']) || !empty($transaction['source_id'])): ?>
                        <p class="muted">Source: <?= h($transaction['source_type']) ?> / <?= h($transaction['source_id']) ?></p>
                    <?php endif; ?>
                </div>
            </details>
        <?php endforeach; ?>
    </div>
    <?php
}
