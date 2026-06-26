<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use Treasury\Auth\DiscordOAuth;
use Treasury\Services\AdminService;
use Treasury\Services\AccountService;
use Treasury\Services\AppService;
use Treasury\Services\BalanceService;
use Treasury\Services\ManualLedgerService;
use Treasury\Services\PaymentRequestService;
use Treasury\Services\PayoutRequestService;
use Treasury\Services\ReconciliationService;
use Treasury\Services\ReversalService;
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


function nav_items(): array
{
    return [
        'dashboard' => 'Overview',
        'payments' => 'Money in',
        'payouts' => 'Money out',
        'reconciliation' => 'Bank reconciliation',
        'transactions' => 'Ledger',
        'chart_accounts' => 'Chart of Accounts',
        'users' => 'Users',
        'settings' => 'Settings',
    ];
}

function page_title(string $page): string
{
    return [
        'new_payment' => 'New money-in request',
        'new_payout' => 'New money-out request',
        'new_opening_balance' => 'New treasury adjustment',
        'new_treasury_expense' => 'New treasury expense',
        'new_admin_paid_expense' => 'New admin-paid expense',
        'new_admin_reimbursement' => 'New admin reimbursement',
        'chart_accounts' => 'Chart of Accounts',
        'users' => 'Users',
    ][$page] ?? (nav_items()[$page] ?? ucwords(str_replace('_', ' ', $page)));
}

function page_description(string $page): string
{
    return [
        'dashboard' => 'Cash position, work queue, and common treasury actions.',
        'payments' => 'Track entry fees, clan contributions, and GP received by admins.',
        'payouts' => 'Manage prizes, expenses, admin-paid payouts, and reimbursements.',
        'reconciliation' => 'Move admin-held GP into the official treasury with a clear audit trail.',
        'transactions' => 'Review posted ledger entries and reverse mistakes safely.',
        'settings' => 'Manage source apps and Discord login status.',
        'chart_accounts' => 'Manage revenue and expense ledger accounts used to categorise GP transactions.',
        'users' => 'Manage treasury users, Discord links, active status, and RSN changes.',
        'new_payment' => 'Create an expected incoming GP payment for entry fees, contributions, or other money-in workflows.',
        'new_payout' => 'Create an outgoing GP request for prizes, expenses, or reimbursement workflows.',
        'new_opening_balance' => 'Post an opening balance or one-off official treasury adjustment.',
        'new_treasury_expense' => 'Record GP paid directly from the official treasury.',
        'new_admin_paid_expense' => 'Record an expense paid personally by an admin so it can be reimbursed later.',
        'new_admin_reimbursement' => 'Record a manual reimbursement from the official treasury to an admin.',
    ][$page] ?? 'Standalone treasury control for clan GP.';
}

function count_rows_by_status(array $rows, string $status): int
{
    return count(array_filter($rows, fn(array $row): bool => ($row['status'] ?? '') === $status));
}

function amount_rows_by_status(array $rows, ?string $status = null): int
{
    $total = 0;
    foreach ($rows as $row) {
        if ($status === null || ($row['status'] ?? '') === $status) {
            $total += (int)($row['amount'] ?? 0);
        }
    }
    return $total;
}

function status_tabs(string $page, array $statuses): string
{
    $active = (string)($_GET['status'] ?? '');
    $appId = (string)($_GET['app_id'] ?? '');
    $q = (string)($_GET['q'] ?? '');
    $base = ['page' => $page];
    if ($appId !== '') {
        $base['app_id'] = $appId;
    }
    if ($q !== '') {
        $base['q'] = $q;
    }

    ob_start();
    ?>
    <nav class="status-tabs" aria-label="Status filters">
        <a class="<?= $active === '' ? 'active' : '' ?>" href="<?= h('?' . http_build_query($base)) ?>">All</a>
        <?php foreach ($statuses as $status): ?>
            <?php $params = array_merge($base, ['status' => $status]); ?>
            <a class="<?= $active === $status ? 'active' : '' ?>" href="<?= h('?' . http_build_query($params)) ?>"><?= h(ucwords(str_replace('_', ' ', $status))) ?></a>
        <?php endforeach; ?>
    </nav>
    <?php
    return ob_get_clean();
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
                $actorId = AdminSession::actingAdminId();
                $admin = (new AdminService())->create($_POST, $actorId ?: null);
                if (!AdminSession::actingAdminId() && AdminSession::canActAsAdmin((int)$admin['id'])) {
                    AdminSession::setActingAdminId((int)$admin['id']);
                }
                Flash::add('success', 'Treasury user created.');
                if (AdminSession::actingAdminLockEnabled() && !AdminSession::actingAdminId()) {
                    Flash::add('warning', 'Acting admin is locked to your Discord login. Link your Discord user ID to your treasury user record to post treasury actions.');
                }
                redirect_to('users');

            case 'update_admin':
                (new AdminService())->update((int)($_POST['admin_id'] ?? 0), $_POST, require_acting_admin());
                Flash::add('success', 'Treasury user updated.');
                redirect_to('users');

            case 'archive_admin':
                (new AdminService())->setActive((int)($_POST['admin_id'] ?? 0), false, require_acting_admin());
                Flash::add('success', 'Treasury user archived.');
                redirect_to('users');

            case 'restore_admin':
                (new AdminService())->setActive((int)($_POST['admin_id'] ?? 0), true, require_acting_admin());
                Flash::add('success', 'Treasury user restored.');
                redirect_to('users');

            case 'delete_admin':
                (new AdminService())->deleteIfUnused((int)($_POST['admin_id'] ?? 0), require_acting_admin());
                Flash::add('success', 'Unused treasury user deleted.');
                redirect_to('users');

            case 'create_app':
                (new AppService())->create($_POST);
                Flash::add('success', 'Source app saved.');
                redirect_to('settings');

            case 'create_account':
                (new AccountService())->createPostingAccount($_POST, require_acting_admin());
                Flash::add('success', 'Ledger account created.');
                redirect_to('chart_accounts');

            case 'update_account':
                (new AccountService())->updatePostingAccount((int)($_POST['account_id'] ?? 0), $_POST, require_acting_admin());
                Flash::add('success', 'Ledger account updated.');
                redirect_to('chart_accounts');

            case 'delete_account':
                (new AccountService())->deleteUnusedPostingAccount((int)($_POST['account_id'] ?? 0), require_acting_admin());
                Flash::add('success', 'Unused ledger account deleted.');
                redirect_to('chart_accounts');

            case 'archive_account':
                (new AccountService())->setActive((int)($_POST['account_id'] ?? 0), false, require_acting_admin());
                Flash::add('success', 'Ledger account archived.');
                redirect_to('chart_accounts');

            case 'restore_account':
                (new AccountService())->setActive((int)($_POST['account_id'] ?? 0), true, require_acting_admin());
                Flash::add('success', 'Ledger account restored.');
                redirect_to('chart_accounts');

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
                    'expense_account_id' => (int)($_POST['expense_account_id'] ?? 0),
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
                    'expense_account_id' => (int)($_POST['expense_account_id'] ?? 0),
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
                $sourceId = 'manual-money-in-' . time() . '-' . bin2hex(random_bytes(3));
                (new PaymentRequestService())->create((new AppService())->manualContext(), [
                    'source_type' => 'manual_money_in',
                    'source_id' => $sourceId,
                    'player_rsn' => $_POST['player_rsn'] ?? '',
                    'amount' => GP::parse($_POST['amount'] ?? ''),
                    'purpose' => 'other',
                    'description' => $_POST['description'] ?? null,
                    'revenue_account_id' => (int)($_POST['revenue_account_id'] ?? 0),
                    'metadata' => ['created_from' => 'admin_ui'],
                ]);
                Flash::add('success', 'Money-in request created.');
                redirect_to('payments');

            case 'receive_payment_request':
                (new PaymentRequestService())->receive((string)($_POST['request_uuid'] ?? ''), [
                    'admin_id' => require_acting_admin(),
                    'received_at' => (($_POST['received_at'] ?? '') ?: 'now'),
                    'notes' => $_POST['notes'] ?? null,
                ]);
                Flash::add('success', 'Payment marked as received by admin.');
                redirect_to('payments');

            case 'cancel_payment_request':
                (new PaymentRequestService())->cancel(
                    (string)($_POST['request_uuid'] ?? ''),
                    require_acting_admin(),
                    $_POST['notes'] ?? null
                );
                Flash::add('success', 'Pending payment request cancelled.');
                redirect_to('payments');

            case 'create_payout_request':
                $sourceId = 'manual-money-out-' . time() . '-' . bin2hex(random_bytes(3));
                (new PayoutRequestService())->create((new AppService())->manualContext(), [
                    'source_type' => 'manual_money_out',
                    'source_id' => $sourceId,
                    'payee_rsn' => $_POST['payee_rsn'] ?? '',
                    'amount' => GP::parse($_POST['amount'] ?? ''),
                    'payout_type' => 'expense',
                    'description' => $_POST['description'] ?? null,
                    'expense_account_id' => (int)($_POST['expense_account_id'] ?? 0),
                    'metadata' => ['created_from' => 'admin_ui'],
                ]);
                Flash::add('success', 'Money-out request created.');
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

            case 'cancel_payout_request':
                (new PayoutRequestService())->cancel(
                    (string)($_POST['request_uuid'] ?? ''),
                    require_acting_admin(),
                    $_POST['notes'] ?? null
                );
                Flash::add('success', 'Pending payout request cancelled.');
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

            case 'reverse_transaction':
                $result = (new ReversalService())->reverse(
                    (string)($_POST['transaction_uuid'] ?? ''),
                    require_acting_admin(),
                    (string)($_POST['reason'] ?? ''),
                    (($_POST['occurred_at'] ?? '') ?: 'now')
                );
                Flash::add('success', 'Transaction reversed. Reversal posted as ' . $result['reversal_transaction_uuid'] . '.');
                redirect_to('transactions');
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
$accountService = new AccountService();
$query = new TreasuryQueryService();
$admins = $loggedIn ? $adminService->all(true) : [];
$allAdmins = $loggedIn ? $adminService->all(false) : [];
$apps = $loggedIn ? $appService->all(true) : [];
$revenueAccounts = $loggedIn ? $accountService->postingAccounts('income') : [];
$expenseAccounts = $loggedIn ? $accountService->postingAccounts('expense') : [];

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
            <div class="nav-new <?= str_starts_with($page, 'new_') ? 'active' : '' ?>">
                <button class="nav-new-trigger" type="button">New</button>
                <div class="nav-new-menu" role="menu" aria-label="New treasury item">
                    <a href="<?= h(url_for('new_payment')) ?>">Money-in request</a>
                    <a href="<?= h(url_for('new_payout')) ?>">Money-out request</a>
                    <a href="<?= h(url_for('new_opening_balance')) ?>">Treasury adjustment</a>
                    <a href="<?= h(url_for('new_treasury_expense')) ?>">Treasury expense</a>
                    <a href="<?= h(url_for('new_admin_paid_expense')) ?>">Admin-paid expense</a>
                    <a href="<?= h(url_for('new_admin_reimbursement')) ?>">Admin reimbursement</a>
                </div>
            </div>
            <?php foreach (nav_items() as $key => $label): ?>
                <a class="<?= $page === $key ? 'active' : '' ?>" href="<?= h(url_for($key)) ?>"><?= h($label) ?></a>
            <?php endforeach; ?>
        </nav>
    </aside>
<?php endif; ?>

<main class="<?= $loggedIn ? 'app-shell' : 'login-shell' ?>">
    <?php if ($loggedIn): ?>
        <header class="topbar">
            <div>
                <h1><?= h(page_title($page)) ?></h1>
                <p><?= h(page_description($page)) ?></p>
            </div>
            <div class="topbar-actions">
                <div class="user-pill">
                    <span><?= h(AdminSession::authMethod() === 'discord' ? 'Discord' : 'Signed in') ?></span>
                    <strong><?= h(AdminSession::displayName()) ?></strong>
                    <?php if (AdminSession::discordUserId()): ?><small>ID: <?= h(AdminSession::discordUserId()) ?></small><?php endif; ?>
                </div>
                <?php
                    $actingAdmin = null;
                    foreach ($admins as $admin) {
                        if (AdminSession::actingAdminId() === (int)$admin['id']) {
                            $actingAdmin = $admin;
                            break;
                        }
                    }
                ?>
                <?php if (AdminSession::actingAdminLockEnabled()): ?>
                    <div class="acting-admin-pill locked">
                        <span>Acting admin</span>
                        <?php if ($actingAdmin): ?>
                            <strong><?= h($actingAdmin['display_name'] ?: $actingAdmin['rsn']) ?></strong>
                            <small>Locked to Discord login</small>
                        <?php else: ?>
                            <strong>Not linked</strong>
                            <small>Link your Discord user ID in Users</small>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
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
                <?php endif; ?>
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
        <?php render_payments($query); ?>
    <?php elseif ($page === 'payouts'): ?>
        <?php render_payouts($query, $admins); ?>
    <?php elseif ($page === 'new_payment'): ?>
        <?php render_new_payment($revenueAccounts); ?>
    <?php elseif ($page === 'new_payout'): ?>
        <?php render_new_payout($expenseAccounts); ?>
    <?php elseif ($page === 'new_opening_balance'): ?>
        <?php render_new_opening_balance(); ?>
    <?php elseif ($page === 'new_treasury_expense'): ?>
        <?php render_new_treasury_expense($expenseAccounts); ?>
    <?php elseif ($page === 'new_admin_paid_expense'): ?>
        <?php render_new_admin_paid_expense($admins, $expenseAccounts); ?>
    <?php elseif ($page === 'new_admin_reimbursement'): ?>
        <?php render_new_admin_reimbursement(); ?>
    <?php elseif ($page === 'reconciliation'): ?>
        <?php render_reconciliation($query, $admins); ?>
    <?php elseif ($page === 'transactions'): ?>
        <?php render_transactions($query, $apps); ?>
    <?php elseif ($page === 'chart_accounts'): ?>
        <?php render_chart_accounts((new AccountService())->all(true), $apps); ?>
    <?php elseif ($page === 'users'): ?>
        <?php render_users($allAdmins); ?>
    <?php elseif ($page === 'settings'): ?>
        <?php render_settings($apps); ?>
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
    $transactions = $query->transactions([], 6);
    ?>
    <?php if (!$admins): ?>
        <section class="notice-card">
            <h2>Create your first treasury user</h2>
            <p>No treasury users exist yet. Open Users and create yourself as the first user before posting treasury actions.</p>
            <a class="button primary" href="<?= h(url_for('users')) ?>">Open users</a>
        </section>
    <?php endif; ?>

    <section class="metric-grid">
        <div class="metric"><span>Official Treasury</span><strong><?= h(GP::format($balances['official_treasury'])) ?></strong><small>GP physically in the official treasury</small></div>
        <div class="metric"><span>Held by Admins</span><strong><?= h(GP::format($balances['admin_held_pending'])) ?></strong><small>Received but not yet reconciled</small></div>
        <div class="metric"><span>Owed to Admins</span><strong><?= h(GP::format($balances['admin_reimbursements_payable'])) ?></strong><small>Admin-paid costs awaiting reimbursement</small></div>
        <div class="metric"><span>Net Clan GP</span><strong><?= h(GP::format($balances['total_clan_gp'])) ?></strong><small>Official + held - reimbursements</small></div>
    </section>

    <section class="workflow-grid">
        <a class="workflow-card" href="<?= h(url_for('payments')) ?>">
            <span>Money in</span>
            <strong>Entry fees & contributions</strong>
            <small>Create requests, mark GP as received, then reconcile it later.</small>
        </a>
        <a class="workflow-card" href="<?= h(url_for('payouts')) ?>">
            <span>Money out</span>
            <strong>Prizes & expenses</strong>
            <small>Pay from treasury, record admin-paid costs, and reimburse admins.</small>
        </a>
        <a class="workflow-card" href="<?= h(url_for('reconciliation')) ?>">
            <span>Banking</span>
            <strong>Reconcile admin-held GP</strong>
            <small>Select received payments that have moved into the official treasury.</small>
        </a>
    </section>

    <section class="grid two">
        <div class="card">
            <div class="section-header">
                <h2>Things to do</h2>
                <span class="pill">Live queue</span>
            </div>
            <div class="queue-grid">
                <a href="<?= h(url_for('payments', ['status' => 'pending'])) ?>"><strong><?= (int)$stats['pending_payments'] ?></strong><span>payments awaiting receipt</span></a>
                <a href="<?= h(url_for('payments', ['status' => 'received_by_admin'])) ?>"><strong><?= (int)$stats['received_unreconciled_payments'] ?></strong><span>received payments to reconcile</span></a>
                <a href="<?= h(url_for('payouts', ['status' => 'pending'])) ?>"><strong><?= (int)$stats['pending_payouts'] ?></strong><span>payouts awaiting action</span></a>
                <a href="<?= h(url_for('payouts', ['status' => 'paid_by_admin'])) ?>"><strong><?= (int)$stats['admin_paid_unreimbursed'] ?></strong><span>admin-paid items to reimburse</span></a>
            </div>
        </div>
        <div class="card">
            <div class="section-header">
                <h2>Admin-held GP</h2>
                <a class="button small" href="<?= h(url_for('reconciliation')) ?>">Open reconciliation</a>
            </div>
            <?php if (!$balances['admin_held_breakdown']): ?>
                <p class="muted">No admin-held GP recorded yet.</p>
            <?php else: ?>
                <div class="table-wrap">
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
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="card">
        <div class="section-header">
            <div>
                <h2>Quick manual actions</h2>
                <p class="muted">Create manual treasury movements from the New menu. These are kept separate from the overview so the dashboard stays focused on work to do.</p>
            </div>
            <span class="pill">Use New</span>
        </div>
        <div class="workflow-grid compact-workflow">
            <a class="workflow-card" href="<?= h(url_for('new_opening_balance')) ?>"><span>Adjustment</span><strong>Treasury adjustment</strong><small>Opening balance or one-off correction.</small></a>
            <a class="workflow-card" href="<?= h(url_for('new_treasury_expense')) ?>"><span>Expense</span><strong>Treasury expense</strong><small>GP paid directly from official treasury.</small></a>
            <a class="workflow-card" href="<?= h(url_for('new_admin_paid_expense')) ?>"><span>Reimbursement</span><strong>Admin-paid expense</strong><small>Admin paid personally and is owed GP.</small></a>
        </div>
    </section>

    <section class="card">
        <div class="section-header">
            <h2>Recent ledger activity</h2>
            <a class="button small" href="<?= h(url_for('transactions')) ?>">View full ledger</a>
        </div>
        <?php render_transaction_table($transactions); ?>
    </section>
    <?php
}

function render_payments(TreasuryQueryService $query): void
{
    $statuses = ['pending','received_by_admin','reconciled_to_treasury','cancelled'];
    $filters = [
        'status' => $_GET['status'] ?? '',
        'q' => $_GET['q'] ?? '',
    ];
    $rows = $query->paymentRequests($filters, 150);
    ?>
    <section class="status-summary">
        <div class="summary-tile"><span>Visible total</span><strong><?= h(GP::format(amount_rows_by_status($rows))) ?></strong></div>
        <div class="summary-tile"><span>Pending</span><strong><?= count_rows_by_status($rows, 'pending') ?></strong></div>
        <div class="summary-tile"><span>Received</span><strong><?= count_rows_by_status($rows, 'received_by_admin') ?></strong></div>
        <div class="summary-tile"><span>Reconciled</span><strong><?= count_rows_by_status($rows, 'reconciled_to_treasury') ?></strong></div>
    </section>

    <section class="card">
        <div class="section-header">
            <div>
                <h2>Payment requests</h2>
                <p class="muted">Follow GP from expected payment, to admin receipt, to treasury reconciliation.</p>
            </div>
            <a class="button primary" href="<?= h(url_for('new_payment')) ?>">New money-in request</a>
        </div>
        <?= status_tabs('payments', $statuses) ?>
        <div class="filter-panel"><?php render_request_filters('payments', $statuses); ?></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Created</th><th>Payer</th><th>Description</th><th>Revenue account</th><th class="right">Amount</th><th>Status</th><th>Received by</th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= h(local_datetime($row['created_at'])) ?></td>
                        <td><?= h($row['player_rsn']) ?></td>
                        <td><?= h($row['description']) ?></td>
                        <td><code><?= h($row['revenue_account_code'] ?? '—') ?></code><small><?= h($row['revenue_account_name'] ?? '') ?></small></td>
                        <td class="right amount"><?= h(GP::format($row['amount'])) ?></td>
                        <td><?= badge($row['status']) ?></td>
                        <td><?= h($row['received_by_display_name'] ?: $row['received_by_rsn'] ?: '—') ?></td>
                        <td class="actions-cell">
                            <?php if ($row['status'] === 'pending'): ?>
                                <form method="post" class="row-action">
                                    <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
                                    <input type="hidden" name="action" value="receive_payment_request">
                                    <input type="hidden" name="request_uuid" value="<?= h($row['request_uuid']) ?>">
                                    <button class="button small primary" type="submit">Receive</button>
                                </form>
                                <form method="post" class="row-action" onsubmit="return confirm('Cancel this pending payment request?');">
                                    <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
                                    <input type="hidden" name="action" value="cancel_payment_request">
                                    <input type="hidden" name="request_uuid" value="<?= h($row['request_uuid']) ?>">
                                    <button class="button small danger" type="submit">Cancel</button>
                                </form>
                            <?php elseif ($row['status'] === 'received_by_admin'): ?>
                                <a class="button small" href="<?= h(url_for('reconciliation', ['admin_id' => (int)($row['received_by_admin_id'] ?? 0)])) ?>">Reconcile</a>
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

function render_payouts(TreasuryQueryService $query, array $admins): void
{
    $statuses = ['pending','paid_from_treasury','paid_by_admin','reimbursed','cancelled'];
    $filters = [
        'status' => $_GET['status'] ?? '',
        'q' => $_GET['q'] ?? '',
    ];
    $rows = $query->payoutRequests($filters, 150);
    ?>
    <section class="status-summary">
        <div class="summary-tile"><span>Visible total</span><strong><?= h(GP::format(amount_rows_by_status($rows))) ?></strong></div>
        <div class="summary-tile"><span>Pending</span><strong><?= count_rows_by_status($rows, 'pending') ?></strong></div>
        <div class="summary-tile"><span>Paid by admin</span><strong><?= count_rows_by_status($rows, 'paid_by_admin') ?></strong></div>
        <div class="summary-tile"><span>Reimbursed</span><strong><?= count_rows_by_status($rows, 'reimbursed') ?></strong></div>
    </section>

    <section class="card">
        <div class="section-header">
            <div>
                <h2>Payout requests</h2>
                <p class="muted">Track prizes and expenses through treasury payment, admin payment, or reimbursement.</p>
            </div>
            <a class="button primary" href="<?= h(url_for('new_payout')) ?>">New money-out request</a>
        </div>
        <?= status_tabs('payouts', $statuses) ?>
        <div class="filter-panel"><?php render_request_filters('payouts', $statuses); ?></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Created</th><th>Payee</th><th>Description</th><th>Expense account</th><th class="right">Amount</th><th>Status</th><th>Admin</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= h(local_datetime($row['created_at'])) ?></td>
                        <td><?= h($row['payee_rsn']) ?></td>
                        <td><?= h($row['description']) ?></td>
                        <td><code><?= h($row['expense_account_code'] ?? '—') ?></code><small><?= h($row['expense_account_name'] ?? '') ?></small></td>
                        <td class="right amount"><?= h(GP::format($row['amount'])) ?></td>
                        <td><?= badge($row['status']) ?></td>
                        <td><?= h($row['paid_by_display_name'] ?: $row['paid_by_rsn'] ?: '—') ?></td>
                        <td class="actions-cell">
                            <?php if ($row['status'] === 'pending'): ?>
                                <form method="post" class="row-action"><input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>"><input type="hidden" name="action" value="payout_from_treasury"><input type="hidden" name="request_uuid" value="<?= h($row['request_uuid']) ?>"><button class="button small primary" type="submit">Pay from treasury</button></form>
                                <form method="post" class="row-action"><input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>"><input type="hidden" name="action" value="payout_by_admin"><input type="hidden" name="request_uuid" value="<?= h($row['request_uuid']) ?>"><button class="button small" type="submit">Admin paid</button></form>
                                <form method="post" class="row-action" onsubmit="return confirm('Cancel this pending payout request?');"><input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>"><input type="hidden" name="action" value="cancel_payout_request"><input type="hidden" name="request_uuid" value="<?= h($row['request_uuid']) ?>"><button class="button small danger" type="submit">Cancel</button></form>
                            <?php elseif ($row['status'] === 'paid_by_admin'): ?>
                                <form method="post" class="row-action"><input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>"><input type="hidden" name="action" value="reimburse_payout"><input type="hidden" name="request_uuid" value="<?= h($row['request_uuid']) ?>"><button class="button small primary" type="submit">Reimburse</button></form>
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

function render_new_payment(array $revenueAccounts): void
{
    ?>
    <section class="card form-page-card">
        <div class="section-header">
            <div>
                <h2>New money-in request</h2>
                <p class="muted">Record GP expected from a payer. Choose the revenue account that explains what the GP is for.</p>
            </div>
            <a class="button" href="<?= h(url_for('payments')) ?>">Back to Money in</a>
        </div>
        <?php if (!$revenueAccounts): ?>
            <div class="notice-inline">Create at least one active revenue account in Chart of Accounts before creating money-in requests.</div>
            <a class="button primary" href="<?= h(url_for('chart_accounts')) ?>">Open Chart of Accounts</a>
        <?php else: ?>
            <form method="post" class="grid-form">
                <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
                <input type="hidden" name="action" value="create_payment_request">
                <label>Deposit account <input value="Admin-held funds — selected when the payment is received" disabled></label>
                <label>Payer RSN <input name="player_rsn" required></label>
                <label>Amount <input name="amount" placeholder="10m" required></label>
                <label>Revenue account <?= account_select($revenueAccounts, 'revenue_account_id') ?></label>
                <label class="wide">Description <input name="description" placeholder="What was the GP received for?" required></label>
                <div class="form-actions"><button class="button primary" type="submit">Create money-in request</button></div>
            </form>
        <?php endif; ?>
    </section>
    <?php
}

function render_new_payout(array $expenseAccounts): void
{
    ?>
    <section class="card form-page-card">
        <div class="section-header">
            <div>
                <h2>New money-out request</h2>
                <p class="muted">Record GP to be paid out. Choose the expense account that explains what the spend is for.</p>
            </div>
            <a class="button" href="<?= h(url_for('payouts')) ?>">Back to Money out</a>
        </div>
        <?php if (!$expenseAccounts): ?>
            <div class="notice-inline">Create at least one active expense account in Chart of Accounts before creating money-out requests.</div>
            <a class="button primary" href="<?= h(url_for('chart_accounts')) ?>">Open Chart of Accounts</a>
        <?php else: ?>
            <form method="post" class="grid-form">
                <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
                <input type="hidden" name="action" value="create_payout_request">
                <label>Payment account <input value="Choose pay from treasury or admin paid after creating the request" disabled></label>
                <label>Payee RSN <input name="payee_rsn" required></label>
                <label>Amount <input name="amount" placeholder="50m" required></label>
                <label>Expense account <?= account_select($expenseAccounts, 'expense_account_id') ?></label>
                <label class="wide">Description <input name="description" placeholder="What was the GP paid for?" required></label>
                <div class="form-actions"><button class="button primary" type="submit">Create money-out request</button></div>
            </form>
        <?php endif; ?>
    </section>
    <?php
}

function render_new_opening_balance(): void
{
    ?>
    <section class="card form-page-card">
        <div class="section-header"><div><h2>New treasury adjustment</h2><p class="muted">Use this for the opening balance or a one-off adjustment that should directly affect the official treasury.</p></div><a class="button" href="<?= h(url_for('dashboard')) ?>">Back to Overview</a></div>
        <form method="post" class="grid-form">
            <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
            <input type="hidden" name="action" value="opening_balance">
            <label>Amount <input name="amount" placeholder="1.25b" required></label>
            <label>Occurred at <input name="occurred_at" placeholder="now"></label>
            <label class="wide">Description <input name="description" value="Opening official treasury balance"></label>
            <label class="wide">Notes <textarea name="notes" placeholder="Optional notes"></textarea></label>
            <div class="form-actions"><button class="button primary" type="submit">Post treasury adjustment</button></div>
        </form>
    </section>
    <?php
}

function render_new_treasury_expense(array $expenseAccounts): void
{
    ?>
    <section class="card form-page-card">
        <div class="section-header"><div><h2>New treasury expense</h2><p class="muted">Record GP paid directly from the official treasury.</p></div><a class="button" href="<?= h(url_for('dashboard')) ?>">Back to Overview</a></div>
        <form method="post" class="grid-form">
            <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
            <input type="hidden" name="action" value="expense_from_treasury">
            <label>Amount <input name="amount" placeholder="25m" required></label>
            <label>Payee RSN <input name="player_rsn" placeholder="Optional"></label>
            <label>Expense account <?= account_select($expenseAccounts, 'expense_account_id') ?></label>
            <label class="wide">Description <input name="description" placeholder="Event supplies / prize top-up" required></label>
            <label class="wide">Notes <textarea name="notes" placeholder="Optional notes"></textarea></label>
            <div class="form-actions"><button class="button primary" type="submit">Post treasury expense</button></div>
        </form>
    </section>
    <?php
}

function render_new_admin_paid_expense(array $admins, array $expenseAccounts): void
{
    ?>
    <section class="card form-page-card">
        <div class="section-header"><div><h2>New admin-paid expense</h2><p class="muted">Record an expense paid personally by an admin so the treasury knows they are owed reimbursement.</p></div><a class="button" href="<?= h(url_for('dashboard')) ?>">Back to Overview</a></div>
        <form method="post" class="grid-form">
            <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
            <input type="hidden" name="action" value="admin_paid_expense">
            <label>Paid by admin <?= admin_select('paid_by_admin_id') ?></label>
            <label>Amount <input name="amount" placeholder="10m" required></label>
            <label>Expense account <?= account_select($expenseAccounts, 'expense_account_id') ?></label>
            <label>Payee RSN <input name="player_rsn" placeholder="Optional"></label>
            <label>Occurred at <input name="occurred_at" placeholder="now"></label>
            <label class="wide">Description <input name="description" placeholder="What was paid?" required></label>
            <label class="wide">Notes <textarea name="notes" placeholder="Optional notes"></textarea></label>
            <div class="form-actions"><button class="button primary" type="submit">Record amount owed</button></div>
        </form>
    </section>
    <?php
}

function render_new_admin_reimbursement(): void
{
    ?>
    <section class="card form-page-card">
        <div class="section-header"><div><h2>New admin reimbursement</h2><p class="muted">Record a manual reimbursement from the official treasury to an admin.</p></div><a class="button" href="<?= h(url_for('dashboard')) ?>">Back to Overview</a></div>
        <form method="post" class="grid-form">
            <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
            <input type="hidden" name="action" value="manual_reimburse_admin">
            <label>Reimbursed admin <?= admin_select('reimbursed_admin_id') ?></label>
            <label>Amount <input name="amount" placeholder="10m" required></label>
            <label>Occurred at <input name="occurred_at" placeholder="now"></label>
            <label class="wide">Description <input name="description" value="Manual admin reimbursement"></label>
            <label class="wide">Notes <textarea name="notes" placeholder="Optional notes"></textarea></label>
            <div class="form-actions"><button class="button primary" type="submit">Post reimbursement</button></div>
        </form>
    </section>
    <?php
}

function render_reconciliation(TreasuryQueryService $query, array $admins): void
{
    $selectedAdminId = (int)($_GET['admin_id'] ?? AdminSession::actingAdminId() ?? ($admins[0]['id'] ?? 0));
    $adminFilter = $selectedAdminId > 0 ? $selectedAdminId : null;
    $rows = $query->unreconciledPaymentsByAdmin($adminFilter);
    $history = $query->reconciliations($adminFilter ? ['admin_id' => $adminFilter] : [], 50);
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
                        <thead><tr><th></th><th>Received</th><th>Admin</th><th>Payer</th><th>Description</th><th class="right">Amount</th></tr></thead>
                        <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><input type="checkbox" name="payment_request_uuids[]" value="<?= h($row['request_uuid']) ?>" checked></td>
                                <td><?= h(local_datetime($row['received_at'])) ?></td>
                                <td><?= h($row['received_by_display_name'] ?: $row['received_by_rsn']) ?></td>
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

    <section class="card">
        <div class="section-header">
            <h2>Reconciliation history</h2>
            <span class="pill"><?= count($history) ?> recent</span>
        </div>
        <?php if (!$history): ?>
            <p class="empty">No completed reconciliations found for this filter.</p>
        <?php else: ?>
            <div class="transaction-list">
                <?php foreach ($history as $recon): ?>
                    <details class="transaction-card">
                        <summary>
                            <span>
                                <strong><?= h($recon['from_admin_display_name'] ?: $recon['from_admin_rsn']) ?> reconciled <?= h(GP::format($recon['amount'])) ?></strong>
                                <small><?= h(local_datetime($recon['completed_at'] ?: $recon['created_at'])) ?> · <?= h($recon['linked_payment_count']) ?> linked payment<?= (int)$recon['linked_payment_count'] === 1 ? '' : 's' ?></small>
                            </span>
                            <span><?= badge($recon['status']) ?></span>
                        </summary>
                        <div class="ledger-lines">
                            <?php if (!empty($recon['notes'])): ?><p class="muted"><?= h($recon['notes']) ?></p><?php endif; ?>
                            <?php if (!empty($recon['transaction_uuid'])): ?><p class="muted">Ledger transaction: <code><?= h($recon['transaction_uuid']) ?></code></p><?php endif; ?>
                            <?php if (empty($recon['linked_payments'])): ?>
                                <p class="muted">No linked payment requests were recorded for this reconciliation.</p>
                            <?php else: ?>
                                <table>
                                    <thead><tr><th>Received</th><th>Payer</th><th>Description</th><th class="right">Amount</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($recon['linked_payments'] as $payment): ?>
                                        <tr>
                                            <td><?= h(local_datetime($payment['received_at'])) ?></td>
                                            <td><?= h($payment['player_rsn']) ?></td>
                                            <td><?= h($payment['description']) ?></td>
                                            <td class="right amount"><?= h(GP::format($payment['amount'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
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

function render_chart_accounts(array $accounts, array $apps): void
{
    $groups = [
        'asset' => [],
        'liability' => [],
        'equity' => [],
        'income' => [],
        'expense' => [],
        'clearing' => [],
    ];
    foreach ($accounts as $account) {
        $groups[$account['account_type']][] = $account;
    }
    ?>
    <section class="grid two">
        <div class="card">
            <div class="section-header">
                <div>
                    <h2>Create posting account</h2>
                    <p class="muted">Create revenue and expense accounts for categorising Money In, Money Out, and manual expenses.</p>
                </div>
            </div>
            <form method="post" class="grid-form">
                <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
                <input type="hidden" name="action" value="create_account">
                <label>Type
                    <select name="account_type" required>
                        <option value="income">Revenue / income</option>
                        <option value="expense">Expense</option>
                    </select>
                </label>
                <label>Account code <input name="code" placeholder="4310" required></label>
                <label class="wide">Account name <input name="name" placeholder="Giveaway Contributions" required></label>
                <div class="form-actions"><button class="button primary" type="submit">Create account</button></div>
            </form>
        </div>

        <div class="notice-card">
            <h2>How these accounts are used</h2>
            <p>Money-in requests credit a revenue account when GP is received by an admin. Money-out requests and manual expenses debit an expense account when GP is paid or owed.</p>
            <p class="muted">System accounts remain locked. User-created accounts can be renamed, deleted while unused, or archived once they have ledger/request history.</p>
        </div>
    </section>

    <?php foreach ($groups as $type => $rows): ?>
        <?php if (!$rows) { continue; } ?>
        <section class="card">
            <div class="section-header">
                <h2><?= h(ucwords(str_replace('_', ' ', $type))) ?> accounts</h2>
                <span class="pill"><?= count($rows) ?> accounts</span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Code</th><th>Name</th><th>Parent</th><th>Normal balance</th><th>Used</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $account): ?>
                        <?php
                            $ledgerCount = (int)($account['ledger_entry_count'] ?? 0);
                            $paymentCount = (int)($account['payment_request_count'] ?? 0);
                            $payoutCount = (int)($account['payout_request_count'] ?? 0);
                            $childCount = (int)($account['child_account_count'] ?? 0);
                            $usageTotal = $ledgerCount + $paymentCount + $payoutCount + $childCount;
                            $isSystem = (int)$account['is_system'] === 1;
                            $isActive = (int)$account['is_active'] === 1;
                            $canManage = !$isSystem && in_array($account['account_type'], ['income', 'expense'], true);
                        ?>
                        <tr>
                            <td><code><?= h($account['code']) ?></code></td>
                            <td><strong><?= h($account['name']) ?></strong><?= $isSystem ? '<small>System account</small>' : '' ?></td>
                            <td><?= h($account['parent_code'] ?? '—') ?><small><?= h($account['parent_name'] ?? '') ?></small></td>
                            <td><?= h($account['normal_balance']) ?></td>
                            <td>
                                <?= $ledgerCount ?> ledger lines
                                <?php if ($paymentCount || $payoutCount || $childCount): ?>
                                    <small><?= $paymentCount ?> money-in refs · <?= $payoutCount ?> money-out refs<?= $childCount ? ' · ' . $childCount . ' child accounts' : '' ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?= $isActive ? badge('active') : badge('archived') ?></td>
                            <td class="actions-cell">
                                <?php if (!$canManage): ?>
                                    <span class="muted">Locked</span>
                                <?php else: ?>
                                    <details class="inline-edit">
                                        <summary class="button small">Edit</summary>
                                        <form method="post" class="stacked-form compact account-edit-form">
                                            <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
                                            <input type="hidden" name="action" value="update_account">
                                            <input type="hidden" name="account_id" value="<?= (int)$account['id'] ?>">
                                            <label>Account code <input name="code" value="<?= h($account['code']) ?>" required></label>
                                            <label>Account name <input name="name" value="<?= h($account['name']) ?>" required></label>
                                            <button class="button small primary" type="submit">Save</button>
                                        </form>
                                    </details>

                                    <?php if (!$isActive): ?>
                                        <form method="post" class="row-action">
                                            <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
                                            <input type="hidden" name="action" value="restore_account">
                                            <input type="hidden" name="account_id" value="<?= (int)$account['id'] ?>">
                                            <button class="button small primary" type="submit">Restore</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($usageTotal === 0): ?>
                                        <form method="post" class="row-action" onsubmit="return confirm('Delete this unused account? This cannot be undone.');">
                                            <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
                                            <input type="hidden" name="action" value="delete_account">
                                            <input type="hidden" name="account_id" value="<?= (int)$account['id'] ?>">
                                            <button class="button small danger" type="submit">Delete</button>
                                        </form>
                                    <?php elseif ($isActive): ?>
                                        <form method="post" class="row-action" onsubmit="return confirm('Archive this account? Existing ledger history will remain.');">
                                            <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
                                            <input type="hidden" name="action" value="archive_account">
                                            <input type="hidden" name="account_id" value="<?= (int)$account['id'] ?>">
                                            <button class="button small" type="submit">Archive</button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endforeach; ?>
    <?php
}

function render_users(array $admins): void
{
    $adminService = new AdminService();
    $history = $adminService->rsnHistoryForAdmins(array_map(fn(array $admin): int => (int)$admin['id'], $admins));
    ?>
    <section class="grid two">
        <div class="card">
            <div class="section-header">
                <div>
                    <h2>Create treasury user</h2>
                    <p class="muted">Treasury users are admins who can hold GP, receive payments, pay expenses, or post ledger actions.</p>
                </div>
            </div>
            <form method="post" class="grid-form">
                <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
                <input type="hidden" name="action" value="create_admin">
                <label>Display name <input name="display_name" placeholder="Lewis"></label>
                <label>Current RSN <input name="rsn" required placeholder="RuneScape name"></label>
                <label class="wide">Discord user ID <input name="discord_user_id" placeholder="Required for Discord auto-link"></label>
                <div class="form-actions"><button class="button primary" type="submit">Create user</button></div>
            </form>
        </div>

        <div class="notice-card">
            <h2>RSN changes</h2>
            <p>When a user changes RSN, edit their current RSN here. The app keeps an RSN history so old records remain understandable while future transactions use the current RSN.</p>
            <p class="muted">Users with no treasury history can be deleted. Users with activity should be archived instead, preserving the audit trail.</p>
        </div>
    </section>

    <section class="card">
        <div class="section-header">
            <div>
                <h2>Treasury users</h2>
                <p class="muted">Manage active users, Discord links, and RSN changes.</p>
            </div>
            <span class="pill"><?= count($admins) ?> users</span>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>User</th><th>Current RSN</th><th>Discord user ID</th><th>Usage</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($admins as $admin): ?>
                    <?php
                        $adminId = (int)$admin['id'];
                        $usageTotal = (int)($admin['ledger_entry_count'] ?? 0)
                            + (int)($admin['posted_transaction_count'] ?? 0)
                            + (int)($admin['reconciliation_count'] ?? 0)
                            + (int)($admin['received_payment_count'] ?? 0)
                            + (int)($admin['paid_payout_count'] ?? 0);
                        $isActive = (int)$admin['is_active'] === 1;
                    ?>
                    <tr>
                        <td><strong><?= h($admin['display_name'] ?: $admin['rsn']) ?></strong><small>ID <?= $adminId ?></small></td>
                        <td><strong><?= h($admin['rsn']) ?></strong></td>
                        <td><?= $admin['discord_user_id'] ? '<code>' . h($admin['discord_user_id']) . '</code>' : '<span class="muted">Not linked</span>' ?></td>
                        <td>
                            <?= $usageTotal ?> references
                            <small><?= (int)($admin['ledger_entry_count'] ?? 0) ?> ledger · <?= (int)($admin['received_payment_count'] ?? 0) ?> received · <?= (int)($admin['paid_payout_count'] ?? 0) ?> paid · <?= (int)($admin['reconciliation_count'] ?? 0) ?> reconciliations</small>
                        </td>
                        <td><?= $isActive ? badge('active') : badge('archived') ?></td>
                        <td class="actions-cell">
                            <?php if ($isActive): ?>
                                <details class="inline-edit user-edit">
                                    <summary class="button small">Edit</summary>
                                    <form method="post" class="stacked-form compact account-edit-form">
                                        <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
                                        <input type="hidden" name="action" value="update_admin">
                                        <input type="hidden" name="admin_id" value="<?= $adminId ?>">
                                        <label>Display name <input name="display_name" value="<?= h($admin['display_name'] ?? '') ?>"></label>
                                        <label>Current RSN <input name="rsn" value="<?= h($admin['rsn']) ?>" required></label>
                                        <label>Discord user ID <input name="discord_user_id" value="<?= h($admin['discord_user_id'] ?? '') ?>"></label>
                                        <button class="button small primary" type="submit">Save user</button>
                                    </form>
                                </details>
                            <?php endif; ?>

                            <details class="inline-edit user-history">
                                <summary class="button small">RSN history</summary>
                                <div class="history-list">
                                    <?php foreach (($history[$adminId] ?? []) as $row): ?>
                                        <div class="history-row">
                                            <strong><?= h($row['rsn']) ?></strong>
                                            <?= ((int)$row['is_current'] === 1) ? badge('current') : '' ?>
                                            <small><?= h(local_datetime($row['effective_from'])) ?><?= $row['effective_to'] ? ' → ' . h(local_datetime($row['effective_to'])) : '' ?></small>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (empty($history[$adminId])): ?><p class="muted">No RSN history recorded yet.</p><?php endif; ?>
                                </div>
                            </details>

                            <?php if (!$isActive): ?>
                                <form method="post" class="row-action">
                                    <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
                                    <input type="hidden" name="action" value="restore_admin">
                                    <input type="hidden" name="admin_id" value="<?= $adminId ?>">
                                    <button class="button small primary" type="submit">Restore</button>
                                </form>
                            <?php endif; ?>

                            <?php if ($usageTotal === 0): ?>
                                <form method="post" class="row-action" onsubmit="return confirm('Delete this unused treasury user? This cannot be undone.');">
                                    <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
                                    <input type="hidden" name="action" value="delete_admin">
                                    <input type="hidden" name="admin_id" value="<?= $adminId ?>">
                                    <button class="button small danger" type="submit">Delete</button>
                                </form>
                            <?php elseif ($isActive): ?>
                                <form method="post" class="row-action" onsubmit="return confirm('Archive this treasury user? Their history will remain available.');">
                                    <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
                                    <input type="hidden" name="action" value="archive_admin">
                                    <input type="hidden" name="admin_id" value="<?= $adminId ?>">
                                    <button class="button small" type="submit">Archive</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$admins): ?><tr><td colspan="6" class="empty">No treasury users have been created yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php
}

function render_settings(array $apps): void
{
    ?>
    <section class="card">
        <div class="section-header">
            <h2>Discord integration</h2>
            <span class="pill"><?= DiscordOAuth::enabled() ? 'Enabled' : 'Disabled' ?></span>
        </div>
        <p class="muted">Discord OAuth controls sign-in. Link Discord user IDs to treasury users from the Users page.</p>
        <div class="config-grid">
            <div><span>Client ID</span><strong><?= h(Env::get('DISCORD_CLIENT_ID', '') ?: 'Not set') ?></strong></div>
            <div><span>Guild ID</span><strong><?= h(Env::get('DISCORD_GUILD_ID', '') ?: 'Optional') ?></strong></div>
            <div><span>Redirect URI</span><strong><?= h(Env::get('DISCORD_REDIRECT_URI', '') ?: 'Not set') ?></strong></div>
            <div><span>Role IDs</span><strong><?= h(Env::get('DISCORD_ADMIN_ROLE_IDS', '') ?: 'Linked users / owner IDs only') ?></strong></div>
        </div>
        <p class="muted"><a href="<?= h(url_for('users')) ?>">Open Users</a> to manage treasury users, RSNs, Discord links, and active status.</p>
    </section>

    <section class="card">
        <div class="section-header">
            <div>
                <h2>Source apps</h2>
                <p class="muted">Source apps are used by API integrations. Manual web entries automatically use the internal Manual Entry app.</p>
            </div>
        </div>
        <form method="post" class="grid-form">
            <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
            <input type="hidden" name="action" value="create_app">
            <label>Name <input name="name" placeholder="Bingo" required></label>
            <label>Slug <input name="slug" placeholder="bingo"></label>
            <label class="wide">Description <input name="description" placeholder="Optional"></label>
            <div class="form-actions"><button class="button primary" type="submit">Save source app</button></div>
        </form>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Name</th><th>Slug</th><th>Description</th></tr></thead>
                <tbody><?php foreach ($apps as $app): ?><tr><td><?= h($app['name']) ?></td><td><code><?= h($app['slug']) ?></code></td><td><?= h($app['description'] ?: '—') ?></td></tr><?php endforeach; ?></tbody>
            </table>
        </div>
        <p class="muted">API keys remain CLI-managed for now.</p>
    </section>
    <?php
}

function render_request_filters(string $page, array $statuses): void
{
    $status = (string)($_GET['status'] ?? '');
    $q = (string)($_GET['q'] ?? '');
    ?>
    <form method="get" class="filter-form">
        <input type="hidden" name="page" value="<?= h($page) ?>">
        <label>Status <select name="status"><option value="">All</option><?php foreach ($statuses as $st): ?><option value="<?= h($st) ?>" <?= $status === $st ? 'selected' : '' ?>><?= h($st) ?></option><?php endforeach; ?></select></label>
        <label>Search <input name="q" value="<?= h($q) ?>" placeholder="RSN or description"></label>
        <button class="button" type="submit">Filter</button>
    </form>
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

function account_select(array $accounts, string $name, int $selected = 0, bool $includeBlank = false): string
{
    ob_start();
    ?>
    <select name="<?= h($name) ?>" <?= $includeBlank ? '' : 'required' ?>>
        <?php if ($includeBlank): ?><option value="">Use default account</option><?php endif; ?>
        <?php foreach ($accounts as $account): ?>
            <option value="<?= (int)$account['id'] ?>" <?= $selected === (int)$account['id'] ? 'selected' : '' ?>>
                <?= h($account['code'] . ' · ' . $account['name'] . (!empty($account['app_name']) ? ' (' . $account['app_name'] . ')' : '')) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <?php
    return ob_get_clean();
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
                    <span>
                        <strong><?= h($transaction['description']) ?></strong>
                        <small><?= h(local_datetime($transaction['occurred_at'])) ?> · <?= h($transaction['transaction_type']) ?> · <?= h($transaction['app_name'] ?: 'Manual') ?> · <?= badge($transaction['status']) ?></small>
                    </span>
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
                    <?php if (!empty($transaction['related_transaction_uuid'])): ?>
                        <p class="muted">Related transaction: <code><?= h($transaction['related_transaction_uuid']) ?></code></p>
                    <?php endif; ?>
                    <?php if (!empty($transaction['reversal_uuid'])): ?>
                        <div class="notice-inline warning-text">This transaction has been reversed by <code><?= h($transaction['reversal_uuid']) ?></code>.</div>
                    <?php elseif ($transaction['status'] === 'posted' && $transaction['transaction_type'] !== 'reversal'): ?>
                        <details class="danger-zone">
                            <summary>Reverse this transaction</summary>
                            <p class="muted">This posts a linked reversal transaction with opposite ledger entries. The original transaction remains visible and is marked reversed.</p>
                            <form method="post" class="stacked-form compact" onsubmit="return confirm('Reverse this posted transaction? This cannot be deleted afterwards.');">
                                <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
                                <input type="hidden" name="action" value="reverse_transaction">
                                <input type="hidden" name="transaction_uuid" value="<?= h($transaction['transaction_uuid']) ?>">
                                <label>Reason <textarea name="reason" required placeholder="Explain what was wrong and what this reversal is correcting."></textarea></label>
                                <div class="form-actions"><button class="button danger" type="submit">Post reversal</button></div>
                            </form>
                        </details>
                    <?php elseif ($transaction['status'] === 'reversed'): ?>
                        <div class="notice-inline warning-text">This transaction is marked reversed.</div>
                    <?php endif; ?>
                </div>
            </details>
        <?php endforeach; ?>
    </div>
    <?php
}
