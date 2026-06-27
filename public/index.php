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
use Treasury\Services\ReportService;
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


function report_date_bounds(): array
{
    $from = report_normalise_date((string)($_GET['from'] ?? ''));
    $to = report_normalise_date((string)($_GET['to'] ?? ''));

    if ($from !== '' && $to !== '' && $from > $to) {
        [$from, $to] = [$to, $from];
    }

    $tz = new DateTimeZone(Env::get('APP_TIMEZONE', 'Australia/Sydney'));

    $fromUtc = null;
    if ($from !== '') {
        $fromLocal = new DateTimeImmutable($from . ' 00:00:00', $tz);
        $fromUtc = $fromLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    $toUtc = null;
    if ($to !== '') {
        $toLocalExclusive = (new DateTimeImmutable($to . ' 00:00:00', $tz))->modify('+1 day');
        $toUtc = $toLocalExclusive->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    return [
        'from' => $from,
        'to' => $to,
        'from_utc' => $fromUtc,
        'to_utc' => $toUtc,
    ];
}

function report_normalise_date(string $value): string
{
    $value = trim($value);
    if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return '';
    }

    [$year, $month, $day] = array_map('intval', explode('-', $value));
    return checkdate($month, $day, $year) ? $value : '';
}

function report_date_label(array $bounds): string
{
    $from = (string)($bounds['from'] ?? '');
    $to = (string)($bounds['to'] ?? '');

    if ($from === '' && $to === '') {
        return 'all time';
    }
    if ($from !== '' && $to !== '') {
        return report_pretty_date($from) . ' to ' . report_pretty_date($to);
    }
    if ($from !== '') {
        return 'from ' . report_pretty_date($from);
    }
    return 'up to ' . report_pretty_date($to);
}

function report_pretty_date(string $date): string
{
    try {
        return (new DateTimeImmutable($date))->format('d M Y');
    } catch (Throwable) {
        return $date;
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
        'reports' => 'Reports',
        'chart_accounts' => 'Chart of Accounts',
        'users' => 'Users',
        'integrations' => 'Integrations',
        'integration_new' => 'New integration',
        'integration_edit' => 'Edit integration',
        'api_keys' => 'API Keys',
        'profile' => 'Profile',
        'settings' => 'Settings',
    ];
}

function nav_categories(): array
{
    return [
        'Treasury' => [
            'dashboard' => 'Overview',
            'payments' => 'Money in',
            'payouts' => 'Money out',
            'reconciliation' => 'Bank reconciliation',
            'transactions' => 'Ledger',
        ],
        'Reporting' => [
            'reports' => 'Reports',
        ],
        'Management' => [
            'chart_accounts' => 'Chart of Accounts',
            'users' => 'Users',
        ],
        'Integrations' => [
            'integrations' => 'Integrations',
            'api_keys' => 'API Keys',
        ],
        'System' => [
            'profile' => 'Profile',
            'settings' => 'Settings',
        ],
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
        'user_new' => 'New user',
        'user_edit' => 'Edit user',
        'profile' => 'Profile',
        'setup_rsn' => 'Set your RSN',
        'payment_detail' => 'Money-in detail',
        'payout_detail' => 'Money-out detail',
        'reconciliation_detail' => 'Handover detail',
        'transaction_detail' => 'Transaction detail',
        'reports' => 'Reports',
        'integrations' => 'Integrations',
        'integration_new' => 'New integration',
        'integration_edit' => 'Edit integration',
        'api_keys' => 'API Keys',
        'api_key_new' => 'New API key',
        'api_key_edit' => 'Edit API key',
        'source_apps' => 'Integrations',
    ][$page] ?? (nav_items()[$page] ?? ucwords(str_replace('_', ' ', $page)));
}

function page_description(string $page): string
{
    return [
        'dashboard' => 'Cash position, work queue, and common treasury actions.',
        'payments' => 'Track entry fees, clan contributions, and GP received by admins.',
        'payouts' => 'Manage prizes, expenses, admin-paid payouts, and reimbursements.',
        'reconciliation' => 'Record GP handed over by admins into the official treasury with a clear audit trail.',
        'transactions' => 'Review posted ledger entries and reverse mistakes safely.',
        'settings' => 'Review Discord login status and app configuration.',
        'integrations' => 'Manage source applications/integrations that can connect to Treasury.',
        'integration_new' => 'Create a new source application/integration.',
        'integration_edit' => 'Edit integration details and source slug.',
        'api_keys' => 'Manage API keys, scopes, expiry dates, and key regeneration for integrations.',
        'api_key_new' => 'Generate a new API key for an integration.',
        'api_key_edit' => 'Edit API key permissions, expiry, and regeneration.',
        'source_apps' => 'Manage source applications/integrations that can connect to Treasury.',
        'reports' => 'Review revenue, expenses, official treasury movement, money owed by admins, and account activity.',
        'chart_accounts' => 'Manage revenue and expense ledger accounts used to categorise GP transactions.',
        'users' => 'Manage treasury users, Discord links, active status, and RSNs.',
        'user_new' => 'Create a treasury user and assign their primary RSN.',
        'user_edit' => 'Update a treasury user and manage all of their RSNs.',
        'profile' => 'Manage your own treasury profile, display name, and RSNs.',
        'setup_rsn' => 'Link your Discord login to a treasury user before continuing.',
        'new_payment' => 'Create an expected incoming GP payment for entry fees, contributions, or other money-in workflows.',
        'new_payout' => 'Create an outgoing GP request for prizes, expenses, or reimbursement workflows.',
        'new_opening_balance' => 'Post an opening balance or one-off official treasury adjustment.',
        'new_treasury_expense' => 'Record GP paid directly from the official treasury.',
        'new_admin_paid_expense' => 'Record an expense paid personally by an admin so it can be reimbursed later.',
        'new_admin_reimbursement' => 'Record a manual reimbursement from the official treasury to an admin.',
        'payment_detail' => 'Inspect the money-in request, linked ledger transactions, and audit trail.',
        'payout_detail' => 'Inspect the money-out request, linked ledger transactions, and audit trail.',
        'reconciliation_detail' => 'Inspect the handover, included payments, ledger movement, and audit trail.',
        'transaction_detail' => 'Inspect the posted ledger transaction, lines, related records, and reversal options.',
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
            <a class="<?= $active === $status ? 'active' : '' ?>" href="<?= h('?' . http_build_query($params)) ?>"><?= h(status_label($status)) ?></a>
        <?php endforeach; ?>
    </nav>
    <?php
    return ob_get_clean();
}

function status_label(string $status): string
{
    return match ($status) {
        'received_by_admin' => 'Owed to Treasury',
        'reconciled_to_treasury' => 'Paid to Treasury',
        'paid_from_treasury' => 'Paid from Treasury',
        'paid_by_admin' => 'Paid by Admin',
        'reimbursed' => 'Reimbursed',
        'pending' => 'Pending',
        'cancelled' => 'Cancelled',
        'posted' => 'Posted',
        'completed' => 'Completed',
        'reversed' => 'Reversed',
        'voided' => 'Voided',
        'archived' => 'Archived',
        default => ucwords(str_replace('_', ' ', $status)),
    };
}

function badge(string $status): string
{
    $class = 'badge badge-' . strtolower(str_replace('_', '-', $status));
    return '<span class="' . h($class) . '">' . h(status_label($status)) . '</span>';
}

function require_acting_admin(): int
{
    $adminId = AdminSession::actingAdminId();
    if (!$adminId) {
        throw new RuntimeException('Select an acting treasury admin before posting treasury actions.');
    }
    return $adminId;
}

function current_treasury_user(): ?array
{
    $service = new AdminService();
    $adminId = AdminSession::actingAdminId();
    if ($adminId) {
        try {
            return $service->get($adminId);
        } catch (Throwable) {
            // Fall through to Discord match.
        }
    }

    $discordUserId = AdminSession::discordUserId();
    return $discordUserId ? $service->findByDiscordUserId($discordUserId) : null;
}

function discord_profile_needs_rsn_setup(): bool
{
    if (!AdminSession::isLoggedIn() || AdminSession::authMethod() !== 'discord') {
        return false;
    }

    $discordUserId = AdminSession::discordUserId();
    if (!$discordUserId) {
        return false;
    }

    return (new AdminService())->findByDiscordUserId($discordUserId) === null;
}

function selected_total_for_reconciliation(int $adminId, array $uuids): int
{
    $uuids = array_values(array_unique(array_filter(array_map('strval', $uuids))));
    if (!$uuids) {
        throw new InvalidArgumentException('Select at least one payment that has been paid into treasury.');
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
        throw new RuntimeException('One or more selected payments cannot be paid into treasury for that admin.');
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

        if ($action === 'complete_discord_rsn_setup') {
            if (AdminSession::authMethod() !== 'discord' || !AdminSession::discordUserId()) {
                throw new RuntimeException('This setup page is only available after Discord sign-in.');
            }

            $adminService = new AdminService();
            $existing = $adminService->findByDiscordUserId(AdminSession::discordUserId());
            if ($existing) {
                AdminSession::setActingAdminId((int)$existing['id']);
                Flash::add('success', 'Your Discord login is already linked.');
                redirect_to('dashboard');
            }

            $displayName = trim((string)($_POST['display_name'] ?? ''));
            if ($displayName === '') {
                $displayName = AdminSession::displayName();
            }

            $admin = $adminService->create([
                'display_name' => $displayName,
                'rsn' => $_POST['rsn'] ?? '',
                'discord_user_id' => AdminSession::discordUserId(),
            ], null);

            AdminSession::setActingAdminId((int)$admin['id']);
            Flash::add('success', 'Your RSN has been linked to your Discord login.');
            redirect_to('dashboard');
        }

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
                redirect_to('user_edit', ['id' => (int)$admin['id']]);

            case 'update_admin':
                $adminId = (int)($_POST['admin_id'] ?? 0);
                (new AdminService())->update($adminId, $_POST, require_acting_admin());
                Flash::add('success', 'Treasury user updated.');
                redirect_to('user_edit', ['id' => $adminId]);

            case 'update_profile':
                $admin = current_treasury_user();
                if (!$admin) {
                    throw new RuntimeException('Your Discord login is not linked to a treasury user.');
                }
                (new AdminService())->update((int)$admin['id'], $_POST, (int)$admin['id']);
                Flash::add('success', 'Profile updated.');
                redirect_to('profile');

            case 'add_admin_rsn':
                $adminId = (int)($_POST['admin_id'] ?? 0);
                (new AdminService())->addRsn($adminId, (string)($_POST['rsn'] ?? ''), !empty($_POST['is_primary']), require_acting_admin());
                Flash::add('success', 'RSN added.');
                redirect_to('user_edit', ['id' => $adminId]);

            case 'update_admin_rsn':
                $row = (new AdminService())->updateRsn((int)($_POST['rsn_id'] ?? 0), (string)($_POST['rsn'] ?? ''), !empty($_POST['is_primary']), require_acting_admin());
                Flash::add('success', 'RSN updated.');
                redirect_to('user_edit', ['id' => (int)$row['admin_id']]);

            case 'set_primary_admin_rsn':
                $service = new AdminService();
                $row = $service->getAdminRsn((int)($_POST['rsn_id'] ?? 0));
                $service->setPrimaryRsn((int)$row['id'], require_acting_admin());
                Flash::add('success', 'Primary RSN updated.');
                redirect_to('user_edit', ['id' => (int)$row['admin_id']]);

            case 'archive_admin_rsn':
                $service = new AdminService();
                $row = $service->getAdminRsn((int)($_POST['rsn_id'] ?? 0));
                $service->setRsnActive((int)$row['id'], false, require_acting_admin());
                Flash::add('success', 'RSN archived.');
                redirect_to('user_edit', ['id' => (int)$row['admin_id']]);

            case 'restore_admin_rsn':
                $service = new AdminService();
                $row = $service->getAdminRsn((int)($_POST['rsn_id'] ?? 0));
                $service->setRsnActive((int)$row['id'], true, require_acting_admin());
                Flash::add('success', 'RSN restored.');
                redirect_to('user_edit', ['id' => (int)$row['admin_id']]);

            case 'delete_admin_rsn':
                $service = new AdminService();
                $row = $service->getAdminRsn((int)($_POST['rsn_id'] ?? 0));
                $adminId = (int)$row['admin_id'];
                $service->deleteRsn((int)$row['id'], require_acting_admin());
                Flash::add('success', 'RSN deleted.');
                redirect_to('user_edit', ['id' => $adminId]);

            case 'profile_add_rsn':
                $admin = current_treasury_user();
                if (!$admin) {
                    throw new RuntimeException('Your Discord login is not linked to a treasury user.');
                }
                (new AdminService())->addRsn((int)$admin['id'], (string)($_POST['rsn'] ?? ''), !empty($_POST['is_primary']), (int)$admin['id']);
                Flash::add('success', 'RSN added to your profile.');
                redirect_to('profile');

            case 'profile_update_rsn':
                $admin = current_treasury_user();
                if (!$admin) {
                    throw new RuntimeException('Your Discord login is not linked to a treasury user.');
                }
                $row = (new AdminService())->getAdminRsn((int)($_POST['rsn_id'] ?? 0));
                if ((int)$row['admin_id'] !== (int)$admin['id']) {
                    throw new RuntimeException('You can only edit your own RSNs from Profile.');
                }
                (new AdminService())->updateRsn((int)$row['id'], (string)($_POST['rsn'] ?? ''), !empty($_POST['is_primary']), (int)$admin['id']);
                Flash::add('success', 'RSN updated.');
                redirect_to('profile');

            case 'profile_set_primary_rsn':
                $admin = current_treasury_user();
                if (!$admin) {
                    throw new RuntimeException('Your Discord login is not linked to a treasury user.');
                }
                $row = (new AdminService())->getAdminRsn((int)($_POST['rsn_id'] ?? 0));
                if ((int)$row['admin_id'] !== (int)$admin['id']) {
                    throw new RuntimeException('You can only edit your own RSNs from Profile.');
                }
                (new AdminService())->setPrimaryRsn((int)$row['id'], (int)$admin['id']);
                Flash::add('success', 'Primary RSN updated.');
                redirect_to('profile');

            case 'profile_archive_rsn':
                $admin = current_treasury_user();
                if (!$admin) {
                    throw new RuntimeException('Your Discord login is not linked to a treasury user.');
                }
                $row = (new AdminService())->getAdminRsn((int)($_POST['rsn_id'] ?? 0));
                if ((int)$row['admin_id'] !== (int)$admin['id']) {
                    throw new RuntimeException('You can only edit your own RSNs from Profile.');
                }
                (new AdminService())->setRsnActive((int)$row['id'], false, (int)$admin['id']);
                Flash::add('success', 'RSN archived.');
                redirect_to('profile');


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
                $app = (new AppService())->create($_POST);
                Flash::add('success', 'Integration created.');
                redirect_to('integration_edit', ['id' => (int)$app['id']]);

            case 'update_app':
                $appId = (int)($_POST['app_id'] ?? 0);
                (new AppService())->update($appId, $_POST, require_acting_admin());
                Flash::add('success', 'Integration updated.');
                redirect_to('integration_edit', ['id' => $appId]);

            case 'archive_app':
                (new AppService())->setActive((int)($_POST['app_id'] ?? 0), false, require_acting_admin());
                Flash::add('success', 'Integration archived.');
                redirect_to('integrations');

            case 'restore_app':
                (new AppService())->setActive((int)($_POST['app_id'] ?? 0), true, require_acting_admin());
                Flash::add('success', 'Integration restored.');
                redirect_to('integrations');

            case 'delete_app':
                (new AppService())->deleteIfUnused((int)($_POST['app_id'] ?? 0), require_acting_admin());
                Flash::add('success', 'Unused integration deleted.');
                redirect_to('integrations');

            case 'create_api_key':
                $createdKey = (new AppService())->createApiKey($_POST, require_acting_admin());
                Flash::add('success', 'API key created. Copy it now; it will not be shown again: ' . $createdKey['raw_key']);
                redirect_to('api_keys');

            case 'update_api_key':
                $keyId = (int)($_POST['api_key_id'] ?? 0);
                (new AppService())->updateApiKey($keyId, $_POST, require_acting_admin());
                Flash::add('success', 'API key permissions updated.');
                redirect_to('api_key_edit', ['id' => $keyId]);

            case 'regenerate_api_key':
                $keyId = (int)($_POST['api_key_id'] ?? 0);
                $regeneratedKey = (new AppService())->regenerateApiKey($keyId, require_acting_admin());
                Flash::add('success', 'API key regenerated. Copy it now; it will not be shown again: ' . $regeneratedKey['raw_key']);
                redirect_to('api_key_edit', ['id' => $keyId]);

            case 'revoke_api_key':
                (new AppService())->setApiKeyActive((int)($_POST['api_key_id'] ?? 0), false, require_acting_admin());
                Flash::add('success', 'API key revoked.');
                redirect_to('api_keys');

            case 'restore_api_key':
                (new AppService())->setApiKeyActive((int)($_POST['api_key_id'] ?? 0), true, require_acting_admin());
                Flash::add('success', 'API key restored.');
                redirect_to('api_keys');

            case 'delete_api_key':
                (new AppService())->deleteApiKey((int)($_POST['api_key_id'] ?? 0), require_acting_admin());
                Flash::add('success', 'Unused API key deleted.');
                redirect_to('api_keys');

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
                $actingAdminId = require_acting_admin();
                $holdingAdminId = (int)($_POST['admin_id'] ?? $actingAdminId);
                (new PaymentRequestService())->receive((string)($_POST['request_uuid'] ?? ''), [
                    'admin_id' => $holdingAdminId,
                    'posted_by_admin_id' => $actingAdminId,
                    'received_at' => (($_POST['received_at'] ?? '') ?: 'now'),
                    'notes' => $_POST['notes'] ?? null,
                ]);
                Flash::add('success', 'Payment recorded as held by the selected admin and owed to treasury.');
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
                Flash::add('success', 'Selected admin-held payments recorded as paid into the official treasury.');
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
if ($page === 'source_apps') {
    $page = 'integrations';
}
$loggedIn = AdminSession::isLoggedIn();
if (!$loggedIn && !in_array($page, ['login', 'discord_login', 'discord_callback'], true)) {
    $page = 'login';
}

$needsRsnSetup = $loggedIn && discord_profile_needs_rsn_setup();
if ($needsRsnSetup && !in_array($page, ['setup_rsn'], true)) {
    $page = 'setup_rsn';
}

$adminService = new AdminService();
$appService = new AppService();
$accountService = new AccountService();
$query = new TreasuryQueryService();
$admins = $loggedIn ? $adminService->all(true) : [];
$allAdmins = $loggedIn ? $adminService->all(false) : [];
$apps = $loggedIn ? $appService->all(true) : [];
$allSourceApps = $loggedIn ? $appService->allWithUsage(true) : [];
$apiKeys = $loggedIn ? $appService->apiKeys() : [];
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
<?php if ($loggedIn && !$needsRsnSetup): ?>
    <aside class="sidebar">
        <div class="brand">
            <span class="brand-mark">◇</span>
            <div>
                <strong><?= h($appName) ?></strong>
                <small>RS3 GP Ledger</small>
            </div>
        </div>
        <nav>
            <div class="nav-section nav-section-new">
                <div class="nav-section-label">Create</div>
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
            </div>
            <?php foreach (nav_categories() as $category => $items): ?>
                <div class="nav-section">
                    <div class="nav-section-label"><?= h($category) ?></div>
                    <?php foreach ($items as $key => $label): ?>
                        <a class="<?= $page === $key ? 'active' : '' ?>" href="<?= h(url_for($key)) ?>"><?= h($label) ?></a>
                    <?php endforeach; ?>
                    <?php if ($category === 'Integrations'): ?>
                        <a class="nav-external" href="api-docs.php" target="_blank" rel="noopener">API Docs</a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </nav>
    </aside>
<?php endif; ?>

<main class="<?= ($loggedIn && !$needsRsnSetup) ? 'app-shell' : 'login-shell' ?>">
    <?php if ($loggedIn && !$needsRsnSetup): ?>
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
    <?php elseif ($page === 'setup_rsn'): ?>
        <?php render_setup_rsn($appName); ?>
    <?php elseif ($page === 'dashboard'): ?>
        <?php render_dashboard($query, $admins); ?>
    <?php elseif ($page === 'payments'): ?>
        <?php render_payments($query); ?>
    <?php elseif ($page === 'payment_detail'): ?>
        <?php render_payment_detail($query); ?>
    <?php elseif ($page === 'payouts'): ?>
        <?php render_payouts($query, $admins); ?>
    <?php elseif ($page === 'payout_detail'): ?>
        <?php render_payout_detail($query); ?>
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
    <?php elseif ($page === 'reconciliation_detail'): ?>
        <?php render_reconciliation_detail($query); ?>
    <?php elseif ($page === 'transactions'): ?>
        <?php render_transactions($query, $apps); ?>
    <?php elseif ($page === 'reports'): ?>
        <?php render_reports(new ReportService()); ?>
    <?php elseif ($page === 'transaction_detail'): ?>
        <?php render_transaction_detail($query); ?>
    <?php elseif ($page === 'chart_accounts'): ?>
        <?php render_chart_accounts((new AccountService())->all(true), $apps); ?>
    <?php elseif ($page === 'users'): ?>
        <?php render_users($allAdmins); ?>
    <?php elseif ($page === 'user_new'): ?>
        <?php render_user_form(null); ?>
    <?php elseif ($page === 'user_edit'): ?>
        <?php render_user_form((int)($_GET['id'] ?? 0)); ?>
    <?php elseif ($page === 'profile'): ?>
        <?php render_profile(); ?>
    <?php elseif ($page === 'integrations'): ?>
        <?php render_integrations($allSourceApps); ?>
    <?php elseif ($page === 'integration_new'): ?>
        <?php render_integration_form(null); ?>
    <?php elseif ($page === 'integration_edit'): ?>
        <?php render_integration_form((int)($_GET['id'] ?? 0)); ?>
    <?php elseif ($page === 'api_keys'): ?>
        <?php render_api_keys($allSourceApps, $apiKeys); ?>
    <?php elseif ($page === 'api_key_new'): ?>
        <?php render_api_key_form(null, $allSourceApps); ?>
    <?php elseif ($page === 'api_key_edit'): ?>
        <?php render_api_key_form((int)($_GET['id'] ?? 0), $allSourceApps); ?>
    <?php elseif ($page === 'settings'): ?>
        <?php render_settings($apps); ?>
    <?php else: ?>
        <section class="card"><h2>Page not found</h2></section>
    <?php endif; ?>
</main>
</body>
</html>
<?php

function render_setup_rsn(string $appName): void
{
    $discordName = AdminSession::displayName();
    $discordId = AdminSession::discordUserId() ?: '';
    ?>
    <section class="login-card setup-card">
        <div class="brand large"><span class="brand-mark">◇</span><div><strong><?= h($appName) ?></strong><small>RS3 GP Accounting</small></div></div>
        <h2>Set your RuneScape name</h2>
        <p class="muted">Your Discord sign-in is authorised, but it is not linked to a treasury user yet. Set your current RSN before using the treasury.</p>
        <div class="notice-inline">
            <strong><?= h($discordName) ?></strong>
            <?php if ($discordId !== ''): ?><small>Discord ID: <?= h($discordId) ?></small><?php endif; ?>
        </div>
        <form method="post" class="stacked-form">
            <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
            <input type="hidden" name="action" value="complete_discord_rsn_setup">
            <label>Display name <input name="display_name" placeholder="First Name" autocomplete="name"></label>
            <label>Current RSN <input name="rsn" required placeholder="RuneScape name" autofocus></label>
            <button class="button primary" type="submit">Save RSN and continue</button>
        </form>
        <form method="post" class="fallback-login">
            <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
            <input type="hidden" name="action" value="logout">
            <button class="button ghost" type="submit">Log out</button>
        </form>
    </section>
    <?php
}

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
        <div class="metric"><span>Admins Owe Treasury</span><strong><?= h(GP::format($balances['admin_held_pending'])) ?></strong><small>Received by admins, not yet handed over</small></div>
        <div class="metric"><span>Owed to Admins</span><strong><?= h(GP::format($balances['admin_reimbursements_payable'])) ?></strong><small>Admin-paid costs awaiting reimbursement</small></div>
        <div class="metric"><span>Net Clan GP</span><strong><?= h(GP::format($balances['total_clan_gp'])) ?></strong><small>Official + owed by admins - reimbursements</small></div>
    </section>

    <section class="workflow-grid">
        <a class="workflow-card" href="<?= h(url_for('payments')) ?>">
            <span>Money in</span>
            <strong>Entry fees & contributions</strong>
            <small>Create requests, record who received the GP, then record when it is handed to treasury.</small>
        </a>
        <a class="workflow-card" href="<?= h(url_for('payouts')) ?>">
            <span>Money out</span>
            <strong>Prizes & expenses</strong>
            <small>Pay from treasury, record admin-paid costs, and reimburse admins.</small>
        </a>
        <a class="workflow-card" href="<?= h(url_for('reconciliation')) ?>">
            <span>Banking</span>
            <strong>Record treasury handover</strong>
            <small>Select admin-held GP only after it has actually been handed into the official treasury.</small>
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
                <a href="<?= h(url_for('payments', ['status' => 'received_by_admin'])) ?>"><strong><?= (int)$stats['received_unreconciled_payments'] ?></strong><span>admin-held GP owed to treasury</span></a>
                <a href="<?= h(url_for('payouts', ['status' => 'pending'])) ?>"><strong><?= (int)$stats['pending_payouts'] ?></strong><span>payouts awaiting action</span></a>
                <a href="<?= h(url_for('payouts', ['status' => 'paid_by_admin'])) ?>"><strong><?= (int)$stats['admin_paid_unreimbursed'] ?></strong><span>admin-paid items to reimburse</span></a>
            </div>
        </div>
        <div class="card">
            <div class="section-header">
                <h2>Money owed by admins</h2>
                <a class="button small" href="<?= h(url_for('reconciliation')) ?>">Record handover</a>
            </div>
            <?php if (!$balances['admin_held_breakdown']): ?>
                <p class="muted">No admin-held money owed to treasury yet.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Admin</th><th class="right">Owes Treasury</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($balances['admin_held_breakdown'] as $row): ?>
                            <tr>
                                <td><?= h($row['display_name'] ?: $row['rsn']) ?></td>
                                <td class="right amount"><?= h(GP::format($row['balance'])) ?></td>
                                <td class="right"><a href="<?= h(url_for('reconciliation', ['admin_id' => (int)$row['admin_id']])) ?>">Record handover</a></td>
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
        <div class="summary-tile"><span>Owed to treasury</span><strong><?= count_rows_by_status($rows, 'received_by_admin') ?></strong></div>
        <div class="summary-tile"><span>Paid to treasury</span><strong><?= count_rows_by_status($rows, 'reconciled_to_treasury') ?></strong></div>
    </section>

    <section class="card">
        <div class="section-header">
            <div>
                <h2>Payment requests</h2>
                <p class="muted">Follow GP from expected payment, to the admin currently holding it, then to the official treasury.</p>
            </div>
            <a class="button primary" href="<?= h(url_for('new_payment')) ?>">New money-in request</a>
        </div>
        <?= status_tabs('payments', $statuses) ?>
        <div class="filter-panel"><?php render_request_filters('payments', $statuses); ?></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Created</th><th>Payer</th><th>Description</th><th>Revenue account</th><th class="right">Amount</th><th>Status</th><th>Admin holding GP</th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= h(local_datetime($row['created_at'])) ?></td>
                        <td><?= h($row['player_rsn']) ?></td>
                        <td><a href="<?= h(url_for('payment_detail', ['uuid' => $row['request_uuid']])) ?>"><strong><?= h($row['description']) ?></strong></a><small><?= h($row['request_uuid']) ?></small></td>
                        <td><code><?= h($row['revenue_account_code'] ?? '—') ?></code><small><?= h($row['revenue_account_name'] ?? '') ?></small></td>
                        <td class="right amount"><?= h(GP::format($row['amount'])) ?></td>
                        <td><?= badge($row['status']) ?></td>
                        <td><?= h($row['received_by_display_name'] ?: $row['received_by_rsn'] ?: '—') ?></td>
                        <td class="actions-cell">
                            <a class="button small" href="<?= h(url_for('payment_detail', ['uuid' => $row['request_uuid']])) ?>">View</a>
                            <?php if ($row['status'] === 'pending'): ?>
                                <a class="button small primary" href="<?= h(url_for('payment_detail', ['uuid' => $row['request_uuid']])) ?>">Record receipt</a>
                                <form method="post" class="row-action" onsubmit="return confirm('Cancel this pending payment request?');">
                                    <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
                                    <input type="hidden" name="action" value="cancel_payment_request">
                                    <input type="hidden" name="request_uuid" value="<?= h($row['request_uuid']) ?>">
                                    <button class="button small danger" type="submit">Cancel</button>
                                </form>
                            <?php elseif ($row['status'] === 'received_by_admin'): ?>
                                <a class="button small" href="<?= h(url_for('reconciliation', ['admin_id' => (int)($row['received_by_admin_id'] ?? 0)])) ?>">Record handover</a>
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
                        <td><a href="<?= h(url_for('payout_detail', ['uuid' => $row['request_uuid']])) ?>"><strong><?= h($row['description']) ?></strong></a><small><?= h($row['request_uuid']) ?></small></td>
                        <td><code><?= h($row['expense_account_code'] ?? '—') ?></code><small><?= h($row['expense_account_name'] ?? '') ?></small></td>
                        <td class="right amount"><?= h(GP::format($row['amount'])) ?></td>
                        <td><?= badge($row['status']) ?></td>
                        <td><?= h($row['paid_by_display_name'] ?: $row['paid_by_rsn'] ?: '—') ?></td>
                        <td class="actions-cell">
                            <a class="button small" href="<?= h(url_for('payout_detail', ['uuid' => $row['request_uuid']])) ?>">View</a>
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
                <label>Deposit account <input value="Admin funds owed to treasury — selected when payment is received" disabled></label>
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
    $history = $query->handovers($adminFilter ? ['admin_id' => $adminFilter] : [], 50);
    $total = array_sum(array_map(fn($row) => (int)$row['amount'], $rows));
    ?>
    <section class="card">
        <h2>Record admin handover into official treasury</h2>
        <p class="muted">Select only the payments that have physically been handed over by an admin and deposited into the official treasury.</p>
        <form method="get" class="filter-form">
            <input type="hidden" name="page" value="reconciliation">
            <label>Admin <?= admin_select('admin_id', $selectedAdminId, true) ?></label>
            <button class="button" type="submit">Filter</button>
        </form>
    </section>

    <section class="card">
        <div class="section-header"><h2>Money owed to treasury</h2><span class="pill">Visible total: <?= h(GP::format($total)) ?></span></div>
        <?php if (!$rows): ?>
            <p class="empty">No admin-held money is currently owed to treasury for this filter.</p>
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
                                <td><a href="<?= h(url_for('payment_detail', ['uuid' => $row['request_uuid']])) ?>"><?= h($row['description']) ?></a></td>
                                <td class="right amount"><?= h(GP::format($row['amount'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <label class="full-width">Notes <textarea name="notes" placeholder="Optional handover note, e.g. paid into official treasury by the listed admin"></textarea></label>
                <div class="form-actions"><button class="button primary" type="submit">Record selected payments as handed over</button></div>
            </form>
        <?php endif; ?>
    </section>

    <section class="card">
        <div class="section-header">
            <h2>Reconciliation history</h2>
            <span class="pill"><?= count($history) ?> recent</span>
        </div>
        <?php if (!$history): ?>
            <p class="empty">No completed handovers found for this filter.</p>
        <?php else: ?>
            <div class="transaction-list">
                <?php foreach ($history as $recon): ?>
                    <details class="transaction-card">
                        <summary>
                            <span>
                                <strong><a href="<?= h(url_for('reconciliation_detail', ['uuid' => $recon['reconciliation_uuid']])) ?>"><?= h($recon['from_admin_display_name'] ?: $recon['from_admin_rsn']) ?> handed over <?= h(GP::format($recon['amount'])) ?></a></strong>
                                <small><?= h(local_datetime($recon['completed_at'] ?: $recon['created_at'])) ?> · <?= h($recon['linked_payment_count']) ?> linked payment<?= (int)$recon['linked_payment_count'] === 1 ? '' : 's' ?></small>
                            </span>
                            <span><?= badge($recon['status']) ?></span>
                        </summary>
                        <div class="ledger-lines">
                            <?php if (!empty($recon['notes'])): ?><p class="muted"><?= h($recon['notes']) ?></p><?php endif; ?>
                            <?php if (!empty($recon['transaction_uuid'])): ?><p class="muted">Ledger transaction: <a href="<?= h(url_for('transaction_detail', ['uuid' => $recon['transaction_uuid']])) ?>"><code><?= h($recon['transaction_uuid']) ?></code></a></p><?php endif; ?>
                            <?php if (empty($recon['linked_payments'])): ?>
                                <p class="muted">No linked payment requests were recorded for this handover.</p>
                            <?php else: ?>
                                <table>
                                    <thead><tr><th>Received</th><th>Payer</th><th>Description</th><th class="right">Amount</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($recon['linked_payments'] as $payment): ?>
                                        <tr>
                                            <td><?= h(local_datetime($payment['received_at'])) ?></td>
                                            <td><?= h($payment['player_rsn']) ?></td>
                                            <td><a href="<?= h(url_for('payment_detail', ['uuid' => $payment['request_uuid']])) ?>"><?= h($payment['description']) ?></a></td>
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


function render_payment_detail(TreasuryQueryService $query): void
{
    $uuid = (string)($_GET['uuid'] ?? '');
    $payment = $uuid !== '' ? $query->paymentRequestByUuid($uuid) : null;
    if (!$payment) {
        render_not_found('Money-in request not found', 'payments');
        return;
    }

    $linkedTransactions = $query->transactionsByIds([
        (int)($payment['received_transaction_id'] ?? 0),
        (int)($payment['reconciliation_transaction_id'] ?? 0),
    ]);
    $audit = $query->auditLogForEntity('treasury_payment_request', $payment['request_uuid']);
    ?>
    <section class="detail-header card">
        <div>
            <a class="muted" href="<?= h(url_for('payments')) ?>">← Back to Money in</a>
            <h2><?= h($payment['description']) ?></h2>
            <p class="muted">Money requested from <strong><?= h($payment['player_rsn']) ?></strong>.</p>
        </div>
        <div class="detail-header-actions">
            <?= badge($payment['status']) ?>
            <span class="amount detail-amount"><?= h(GP::format($payment['amount'])) ?></span>
        </div>
    </section>

    <section class="grid two">
        <div class="card">
            <h2>Request details</h2>
            <div class="detail-grid">
                <div><span>Payer</span><strong><?= h($payment['player_rsn']) ?></strong></div>
                <div><span>Amount</span><strong><?= h(GP::format($payment['amount'])) ?></strong></div>
                <div><span>Revenue account</span><strong><code><?= h($payment['revenue_account_code'] ?? '—') ?></code> <?= h($payment['revenue_account_name'] ?? '') ?></strong></div>
                <div><span>Status</span><strong><?= badge($payment['status']) ?></strong></div>
                <div><span>Created</span><strong><?= h(local_datetime($payment['created_at'])) ?></strong></div>
                <div><span>Held since</span><strong><?= h(local_datetime($payment['received_at'] ?? null)) ?></strong></div>
                <div><span>Admin holding GP</span><strong><?= h($payment['received_by_display_name'] ?: $payment['received_by_rsn'] ?: '—') ?></strong></div>
                <div><span>Paid to treasury</span><strong><?= h(local_datetime($payment['reconciled_at'] ?? null)) ?></strong></div>
            </div>
            <?php if (!empty($payment['metadata'])): ?>
                <details class="detail-json"><summary>Metadata</summary><pre><?= h(pretty_json($payment['metadata'])) ?></pre></details>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Available actions</h2>
            <div class="detail-actions">
                <?php if ($payment['status'] === 'pending'): ?>
                    <form method="post" class="stacked-form compact">
                        <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
                        <input type="hidden" name="action" value="receive_payment_request">
                        <input type="hidden" name="request_uuid" value="<?= h($payment['request_uuid']) ?>">
                        <label>Admin holding GP <?= admin_select('admin_id', AdminSession::actingAdminId() ?? 0) ?></label>
                        <label>Received at <input name="received_at" placeholder="now"></label>
                        <label>Notes <textarea name="notes" placeholder="Optional note, e.g. received at GE from payer"></textarea></label>
                        <button class="button primary" type="submit">Record as owed to treasury</button>
                    </form>
                    <form method="post" class="stacked-form compact" onsubmit="return confirm('Cancel this pending payment request?');">
                        <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
                        <input type="hidden" name="action" value="cancel_payment_request">
                        <input type="hidden" name="request_uuid" value="<?= h($payment['request_uuid']) ?>">
                        <button class="button danger" type="submit">Cancel request</button>
                    </form>
                <?php elseif ($payment['status'] === 'received_by_admin'): ?>
                    <a class="button primary" href="<?= h(url_for('reconciliation', ['admin_id' => (int)($payment['received_by_admin_id'] ?? 0)])) ?>">Record this admin’s handover</a>
                <?php else: ?>
                    <p class="muted">No direct actions are available for this request state.</p>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <?php render_linked_transactions($linkedTransactions, 'No ledger transactions have been posted for this money-in request yet.'); ?>
    <?php render_audit_log($audit, 'No audit events found for this money-in request.'); ?>
    <?php
}

function render_payout_detail(TreasuryQueryService $query): void
{
    $uuid = (string)($_GET['uuid'] ?? '');
    $payout = $uuid !== '' ? $query->payoutRequestByUuid($uuid) : null;
    if (!$payout) {
        render_not_found('Money-out request not found', 'payouts');
        return;
    }

    $linkedTransactions = $query->transactionsByIds([
        (int)($payout['paid_transaction_id'] ?? 0),
        (int)($payout['reimbursement_transaction_id'] ?? 0),
    ]);
    $audit = $query->auditLogForEntity('treasury_payout_request', $payout['request_uuid']);
    ?>
    <section class="detail-header card">
        <div>
            <a class="muted" href="<?= h(url_for('payouts')) ?>">← Back to Money out</a>
            <h2><?= h($payout['description']) ?></h2>
            <p class="muted">Money paid to <strong><?= h($payout['payee_rsn']) ?></strong>.</p>
        </div>
        <div class="detail-header-actions">
            <?= badge($payout['status']) ?>
            <span class="amount detail-amount"><?= h(GP::format($payout['amount'])) ?></span>
        </div>
    </section>

    <section class="grid two">
        <div class="card">
            <h2>Request details</h2>
            <div class="detail-grid">
                <div><span>Payee</span><strong><?= h($payout['payee_rsn']) ?></strong></div>
                <div><span>Amount</span><strong><?= h(GP::format($payout['amount'])) ?></strong></div>
                <div><span>Expense account</span><strong><code><?= h($payout['expense_account_code'] ?? '—') ?></code> <?= h($payout['expense_account_name'] ?? '') ?></strong></div>
                <div><span>Status</span><strong><?= badge($payout['status']) ?></strong></div>
                <div><span>Created</span><strong><?= h(local_datetime($payout['created_at'])) ?></strong></div>
                <div><span>Paid</span><strong><?= h(local_datetime($payout['paid_at'] ?? null)) ?></strong></div>
                <div><span>Admin</span><strong><?= h($payout['paid_by_display_name'] ?: $payout['paid_by_rsn'] ?: '—') ?></strong></div>
                <div><span>Reimbursed</span><strong><?= h(local_datetime($payout['reimbursed_at'] ?? null)) ?></strong></div>
            </div>
            <?php if (!empty($payout['metadata'])): ?>
                <details class="detail-json"><summary>Metadata</summary><pre><?= h(pretty_json($payout['metadata'])) ?></pre></details>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Available actions</h2>
            <div class="detail-actions">
                <?php if ($payout['status'] === 'pending'): ?>
                    <form method="post" class="row-action"><input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>"><input type="hidden" name="action" value="payout_from_treasury"><input type="hidden" name="request_uuid" value="<?= h($payout['request_uuid']) ?>"><button class="button primary" type="submit">Pay from treasury</button></form>
                    <form method="post" class="row-action"><input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>"><input type="hidden" name="action" value="payout_by_admin"><input type="hidden" name="request_uuid" value="<?= h($payout['request_uuid']) ?>"><button class="button" type="submit">Admin paid</button></form>
                    <form method="post" class="row-action" onsubmit="return confirm('Cancel this pending payout request?');"><input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>"><input type="hidden" name="action" value="cancel_payout_request"><input type="hidden" name="request_uuid" value="<?= h($payout['request_uuid']) ?>"><button class="button danger" type="submit">Cancel request</button></form>
                <?php elseif ($payout['status'] === 'paid_by_admin'): ?>
                    <form method="post" class="row-action"><input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>"><input type="hidden" name="action" value="reimburse_payout"><input type="hidden" name="request_uuid" value="<?= h($payout['request_uuid']) ?>"><button class="button primary" type="submit">Reimburse admin</button></form>
                <?php else: ?>
                    <p class="muted">No direct actions are available for this request state.</p>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <?php render_linked_transactions($linkedTransactions, 'No ledger transactions have been posted for this money-out request yet.'); ?>
    <?php render_audit_log($audit, 'No audit events found for this money-out request.'); ?>
    <?php
}

function render_reconciliation_detail(TreasuryQueryService $query): void
{
    $uuid = (string)($_GET['uuid'] ?? '');
    $recon = $uuid !== '' ? $query->reconciliationByUuid($uuid) : null;
    if (!$recon) {
        render_not_found('Reconciliation not found', 'reconciliation');
        return;
    }

    $linkedTransactions = $query->transactionsByIds([(int)($recon['transaction_id'] ?? 0)]);
    $audit = $query->auditLogForEntity('treasury_reconciliation', $recon['reconciliation_uuid']);
    ?>
    <section class="detail-header card">
        <div>
            <a class="muted" href="<?= h(url_for('reconciliation', ['admin_id' => (int)$recon['from_admin_id']])) ?>">← Back to handovers</a>
            <h2><?= h($recon['from_admin_display_name'] ?: $recon['from_admin_rsn']) ?> handed over <?= h(GP::format($recon['amount'])) ?></h2>
            <p class="muted">Admin-held GP was handed over and recorded in the official treasury.</p>
        </div>
        <div class="detail-header-actions">
            <?= badge($recon['status']) ?>
            <span class="amount detail-amount"><?= h(GP::format($recon['amount'])) ?></span>
        </div>
    </section>

    <section class="card">
        <h2>Reconciliation details</h2>
        <div class="detail-grid">
            <div><span>From admin</span><strong><?= h($recon['from_admin_display_name'] ?: $recon['from_admin_rsn']) ?></strong></div>
            <div><span>Amount</span><strong><?= h(GP::format($recon['amount'])) ?></strong></div>
            <div><span>Completed by</span><strong><?= h($recon['completed_by_display_name'] ?: $recon['completed_by_rsn'] ?: '—') ?></strong></div>
            <div><span>Completed</span><strong><?= h(local_datetime($recon['completed_at'] ?? null)) ?></strong></div>
            <div><span>Linked payments</span><strong><?= h($recon['linked_payment_count']) ?></strong></div>
            <div><span>Ledger transaction</span><strong><?php if (!empty($recon['transaction_uuid'])): ?><a href="<?= h(url_for('transaction_detail', ['uuid' => $recon['transaction_uuid']])) ?>"><code><?= h($recon['transaction_uuid']) ?></code></a><?php else: ?>—<?php endif; ?></strong></div>
        </div>
        <?php if (!empty($recon['notes'])): ?><p class="notice-inline"><?= h($recon['notes']) ?></p><?php endif; ?>
    </section>

    <section class="card">
        <div class="section-header"><h2>Included payments</h2><span class="pill"><?= count($recon['linked_payments'] ?? []) ?> payments</span></div>
        <?php if (empty($recon['linked_payments'])): ?>
            <p class="empty">No linked payment requests were recorded for this handover.</p>
        <?php else: ?>
            <div class="table-wrap"><table>
                <thead><tr><th>Received</th><th>Payer</th><th>Description</th><th class="right">Amount</th></tr></thead>
                <tbody>
                <?php foreach ($recon['linked_payments'] as $payment): ?>
                    <tr>
                        <td><?= h(local_datetime($payment['received_at'])) ?></td>
                        <td><?= h($payment['player_rsn']) ?></td>
                        <td><a href="<?= h(url_for('payment_detail', ['uuid' => $payment['request_uuid']])) ?>"><?= h($payment['description']) ?></a></td>
                        <td class="right amount"><?= h(GP::format($payment['amount'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>

    <?php render_linked_transactions($linkedTransactions, 'No ledger transaction is linked to this handover.'); ?>
    <?php render_audit_log($audit, 'No audit events found for this handover.'); ?>
    <?php
}

function render_transaction_detail(TreasuryQueryService $query): void
{
    $uuid = (string)($_GET['uuid'] ?? '');
    $transaction = $uuid !== '' ? $query->transactionByUuid($uuid) : null;
    if (!$transaction) {
        render_not_found('Ledger transaction not found', 'transactions');
        return;
    }

    $audit = $query->auditLogForEntity('treasury_transaction', $transaction['transaction_uuid']);
    ?>
    <section class="detail-header card">
        <div>
            <a class="muted" href="<?= h(url_for('transactions')) ?>">← Back to Ledger</a>
            <h2><?= h($transaction['description']) ?></h2>
            <p class="muted"><?= h($transaction['transaction_type']) ?> · <?= h(local_datetime($transaction['occurred_at'])) ?></p>
        </div>
        <div class="detail-header-actions">
            <?= badge($transaction['status']) ?>
            <span class="amount detail-amount"><?= h(GP::format($transaction['amount'])) ?></span>
        </div>
    </section>

    <section class="grid two">
        <div class="card">
            <h2>Transaction details</h2>
            <div class="detail-grid">
                <div><span>Transaction UUID</span><strong><code><?= h($transaction['transaction_uuid']) ?></code></strong></div>
                <div><span>Amount</span><strong><?= h(GP::format($transaction['amount'])) ?></strong></div>
                <div><span>Type</span><strong><?= h($transaction['transaction_type']) ?></strong></div>
                <div><span>Status</span><strong><?= badge($transaction['status']) ?></strong></div>
                <div><span>Occurred</span><strong><?= h(local_datetime($transaction['occurred_at'])) ?></strong></div>
                <div><span>Posted</span><strong><?= h(local_datetime($transaction['posted_at'])) ?></strong></div>
                <div><span>Posted by</span><strong><?= h($transaction['posted_by_display_name'] ?: $transaction['posted_by_rsn'] ?: '—') ?></strong></div>
                <div><span>Source app</span><strong><?= h($transaction['app_name'] ?: 'Manual / system') ?></strong></div>
            </div>
            <?php if (!empty($transaction['notes'])): ?><p class="notice-inline"><?= h($transaction['notes']) ?></p><?php endif; ?>
            <?php render_transaction_links($transaction); ?>
            <?php if (!empty($transaction['metadata'])): ?>
                <details class="detail-json"><summary>Metadata</summary><pre><?= h(pretty_json($transaction['metadata'])) ?></pre></details>
            <?php endif; ?>
        </div>
        <div class="card">
            <h2>Correction controls</h2>
            <?php render_reversal_controls($transaction); ?>
        </div>
    </section>

    <?php render_single_transaction_lines($transaction); ?>
    <?php render_audit_log($audit, 'No audit events found for this ledger transaction.'); ?>
    <?php
}

function render_linked_transactions(array $transactions, string $emptyMessage): void
{
    ?>
    <section class="card">
        <div class="section-header"><h2>Linked ledger transactions</h2><span class="pill"><?= count($transactions) ?> transaction<?= count($transactions) === 1 ? '' : 's' ?></span></div>
        <?php if (!$transactions): ?>
            <p class="empty"><?= h($emptyMessage) ?></p>
        <?php else: ?>
            <div class="transaction-list">
                <?php foreach ($transactions as $transaction): ?>
                    <details class="transaction-card" open>
                        <summary>
                            <span>
                                <strong><a href="<?= h(url_for('transaction_detail', ['uuid' => $transaction['transaction_uuid']])) ?>"><?= h($transaction['description']) ?></a></strong>
                                <small><?= h(local_datetime($transaction['occurred_at'])) ?> · <?= h($transaction['transaction_type']) ?> · <?= badge($transaction['status']) ?></small>
                            </span>
                            <span class="amount"><?= h(GP::format($transaction['amount'])) ?></span>
                        </summary>
                        <div class="ledger-lines">
                            <?php render_ledger_lines_table($transaction['lines'] ?? []); ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php
}

function render_single_transaction_lines(array $transaction): void
{
    ?>
    <section class="card">
        <div class="section-header"><h2>Ledger lines</h2><span class="pill">Balanced entries</span></div>
        <?php render_ledger_lines_table($transaction['lines'] ?? []); ?>
    </section>
    <?php
}

function render_ledger_lines_table(array $lines): void
{
    if (!$lines) {
        echo '<p class="empty">No ledger lines found.</p>';
        return;
    }
    ?>
    <div class="table-wrap"><table>
        <thead><tr><th>Account</th><th>Memo</th><th>RSN/Admin</th><th class="right">Debit</th><th class="right">Credit</th></tr></thead>
        <tbody>
        <?php foreach ($lines as $line): ?>
            <tr>
                <td><code><?= h($line['account_code']) ?></code> <?= h($line['account_name']) ?></td>
                <td><?= h($line['memo'] ?: '—') ?></td>
                <td><?= h($line['player_rsn'] ?: ($line['admin_display_name'] ?: $line['admin_rsn'] ?: '—')) ?></td>
                <td class="right amount"><?= $line['direction'] === 'debit' ? h(GP::format($line['amount'])) : '—' ?></td>
                <td class="right amount"><?= $line['direction'] === 'credit' ? h(GP::format($line['amount'])) : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php
}

function render_transaction_links(array $transaction): void
{
    $links = [];
    if (!empty($transaction['source_type']) && !empty($transaction['source_id'])) {
        if ($transaction['source_type'] === 'reconciliation') {
            $links[] = '<a href="' . h(url_for('reconciliation_detail', ['uuid' => $transaction['source_id']])) . '">Open handover</a>';
        } elseif ($transaction['source_type'] === 'payment_request' && preg_match('/^[0-9a-fA-F-]{36}$/', (string)$transaction['source_id'])) {
            $links[] = '<a href="' . h(url_for('payment_detail', ['uuid' => $transaction['source_id']])) . '">Open money-in request</a>';
        }
    }
    if (!empty($transaction['related_transaction_uuid'])) {
        $links[] = '<a href="' . h(url_for('transaction_detail', ['uuid' => $transaction['related_transaction_uuid']])) . '">Open related transaction</a>';
    }
    if (!empty($transaction['reversal_uuid'])) {
        $links[] = '<a href="' . h(url_for('transaction_detail', ['uuid' => $transaction['reversal_uuid']])) . '">Open reversal transaction</a>';
    }
    if (!$links) {
        return;
    }
    echo '<div class="notice-inline"><strong>Related records:</strong> ' . implode(' · ', $links) . '</div>';
}

function render_reversal_controls(array $transaction): void
{
    if (!empty($transaction['reversal_uuid'])) {
        ?>
        <div class="notice-inline warning-text">This transaction has been reversed by <a href="<?= h(url_for('transaction_detail', ['uuid' => $transaction['reversal_uuid']])) ?>"><code><?= h($transaction['reversal_uuid']) ?></code></a>.</div>
        <?php
        return;
    }
    if ($transaction['status'] === 'posted' && $transaction['transaction_type'] !== 'reversal') {
        ?>
        <p class="muted">Reverse this transaction if it was posted in error. This creates an opposite transaction and keeps the audit trail intact.</p>
        <form method="post" class="stacked-form compact" onsubmit="return confirm('Reverse this posted transaction? This cannot be deleted afterwards.');">
            <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
            <input type="hidden" name="action" value="reverse_transaction">
            <input type="hidden" name="transaction_uuid" value="<?= h($transaction['transaction_uuid']) ?>">
            <label>Reason <textarea name="reason" required placeholder="Explain what was wrong and what this reversal is correcting."></textarea></label>
            <div class="form-actions"><button class="button danger" type="submit">Post reversal</button></div>
        </form>
        <?php
        return;
    }
    if ($transaction['status'] === 'reversed') {
        echo '<div class="notice-inline warning-text">This transaction is marked reversed.</div>';
        return;
    }
    echo '<p class="muted">No correction actions are available for this transaction.</p>';
}

function render_audit_log(array $events, string $emptyMessage): void
{
    ?>
    <section class="card">
        <div class="section-header"><h2>Audit trail</h2><span class="pill"><?= count($events) ?> event<?= count($events) === 1 ? '' : 's' ?></span></div>
        <?php if (!$events): ?>
            <p class="empty"><?= h($emptyMessage) ?></p>
        <?php else: ?>
            <div class="audit-list">
                <?php foreach ($events as $event): ?>
                    <details class="audit-card">
                        <summary>
                            <span><strong><?= h($event['action']) ?></strong><small><?= h(local_datetime($event['created_at'])) ?> · <?= h($event['actor_admin_name'] ?: $event['actor_admin_rsn'] ?: $event['actor_app_name'] ?: 'System') ?></small></span>
                        </summary>
                        <div class="audit-json-grid">
                            <div><span>Before</span><pre><?= h(pretty_json($event['before_json'] ?? null)) ?></pre></div>
                            <div><span>After</span><pre><?= h(pretty_json($event['after_json'] ?? null)) ?></pre></div>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php
}

function pretty_json(mixed $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: $value;
        }
        return $value;
    }
    return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '—';
}

function render_not_found(string $message, string $backPage): void
{
    ?>
    <section class="card">
        <h2><?= h($message) ?></h2>
        <p class="muted">The record may have been removed, or the link may be incorrect.</p>
        <a class="button" href="<?= h(url_for($backPage)) ?>">Go back</a>
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

function render_profile(): void
{
    $admin = current_treasury_user();
    if (!$admin) {
        render_not_found('Your Discord login is not linked to a treasury user yet.', 'dashboard');
        return;
    }

    render_user_profile_form($admin, true);
}

function render_users(array $admins): void
{
    $adminService = new AdminService();
    $rsns = $adminService->rsnsForAdmins(array_map(fn(array $admin): int => (int)$admin['id'], $admins));
    ?>
    <section class="card">
        <div class="section-header">
            <div>
                <h2>Treasury users</h2>
                <p class="muted">Manage treasury users, Discord links, active status, and multiple RSNs.</p>
            </div>
            <a class="button primary" href="<?= h(url_for('user_new')) ?>">New user</a>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>User</th><th>RSNs</th><th>Discord user ID</th><th>Usage</th><th>Status</th><th>Actions</th></tr></thead>
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
                        $userRsns = $rsns[$adminId] ?? [];
                    ?>
                    <tr>
                        <td><strong><?= h($admin['display_name'] ?: $admin['rsn']) ?></strong><small>ID <?= $adminId ?></small></td>
                        <td>
                            <?php foreach ($userRsns as $row): ?>
                                <?php if ((int)$row['is_active'] !== 1) { continue; } ?>
                                <span class="pill rsn-pill"><?= h($row['rsn']) ?><?= (int)$row['is_primary'] === 1 ? ' · Primary' : '' ?></span>
                            <?php endforeach; ?>
                            <?php if (!$userRsns): ?><span class="muted"><?= h($admin['rsn']) ?></span><?php endif; ?>
                        </td>
                        <td><?= $admin['discord_user_id'] ? '<code>' . h($admin['discord_user_id']) . '</code>' : '<span class="muted">Not linked</span>' ?></td>
                        <td>
                            <?= $usageTotal ?> references
                            <small><?= (int)($admin['ledger_entry_count'] ?? 0) ?> ledger · <?= (int)($admin['received_payment_count'] ?? 0) ?> received · <?= (int)($admin['paid_payout_count'] ?? 0) ?> paid · <?= (int)($admin['reconciliation_count'] ?? 0) ?> handovers</small>
                        </td>
                        <td><?= $isActive ? badge('active') : badge('archived') ?></td>
                        <td class="actions-cell">
                            <a class="button small" href="<?= h(url_for('user_edit', ['id' => $adminId])) ?>">Edit</a>
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

function render_user_form(?int $adminId): void
{
    $adminService = new AdminService();
    if ($adminId === null || $adminId <= 0) {
        ?>
        <section class="card">
            <div class="section-header">
                <div>
                    <h2>New treasury user</h2>
                    <p class="muted">Create a treasury user and assign their primary RuneScape name.</p>
                </div>
                <a class="button" href="<?= h(url_for('users')) ?>">Back to users</a>
            </div>
            <form method="post" class="grid-form">
                <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
                <input type="hidden" name="action" value="create_admin">
                <label>Display name <input name="display_name" placeholder="First Name"></label>
                <label>Primary RSN <input name="primary_rsn" required placeholder="RuneScape name"></label>
                <label class="wide">Discord user ID <input name="discord_user_id" placeholder="Required for Discord auto-link"></label>
                <div class="form-actions"><button class="button primary" type="submit">Create user</button></div>
            </form>
        </section>
        <?php
        return;
    }

    try {
        $admin = $adminService->get($adminId);
    } catch (Throwable $e) {
        render_not_found($e->getMessage(), 'users');
        return;
    }
    render_user_profile_form($admin, false);
}

function render_user_profile_form(array $admin, bool $selfProfile): void
{
    $adminService = new AdminService();
    $adminId = (int)$admin['id'];
    $rsns = $adminService->adminRsns($adminId, true);
    $history = $adminService->rsnHistory($adminId);
    $prefix = $selfProfile ? 'profile_' : '';
    ?>
    <section class="grid two">
        <div class="card">
            <div class="section-header">
                <div>
                    <h2><?= $selfProfile ? 'Your profile' : 'Edit treasury user' ?></h2>
                    <p class="muted">Update display details and Discord linkage. RSNs are managed separately below.</p>
                </div>
                <?php if (!$selfProfile): ?><a class="button" href="<?= h(url_for('users')) ?>">Back to users</a><?php endif; ?>
            </div>
            <form method="post" class="grid-form profile-form">
                <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
                <input type="hidden" name="action" value="<?= $selfProfile ? 'update_profile' : 'update_admin' ?>">
                <?php if (!$selfProfile): ?><input type="hidden" name="admin_id" value="<?= $adminId ?>"><?php endif; ?>
                <label>Display name <input name="display_name" value="<?= h($admin['display_name'] ?? '') ?>" placeholder="First Name"></label>
                <label>Primary RSN <input value="<?= h($admin['rsn']) ?>" disabled><small>Set the primary RSN from the RSN list below.</small></label>
                <label class="wide">Discord user ID <input name="discord_user_id" value="<?= h($admin['discord_user_id'] ?? '') ?>" <?= $selfProfile ? 'readonly' : '' ?>></label>
                <div class="form-actions"><button class="button primary" type="submit">Save</button></div>
            </form>
        </div>
        <div class="notice-card">
            <h2>Multiple RSNs</h2>
            <p>Assign every RuneScape name this admin may use. API calls can now identify an admin by any active RSN.</p>
            <p class="muted">The primary RSN is used for display and account naming. Old primary RSNs remain in the RSN history for audit clarity.</p>
        </div>
    </section>

    <section class="card">
        <div class="section-header"><div><h2>RSNs</h2><p class="muted">Add, edit, archive, or set the primary RSN.</p></div></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>RSN</th><th>Status</th><th>Primary</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($rsns as $row): ?>
                    <?php $rsnId = (int)$row['id']; $isPrimary = (int)$row['is_primary'] === 1; $isActive = (int)$row['is_active'] === 1; ?>
                    <tr>
                        <td>
                            <form method="post" class="inline-form compact-inline">
                                <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
                                <input type="hidden" name="action" value="<?= $selfProfile ? 'profile_update_rsn' : 'update_admin_rsn' ?>">
                                <input type="hidden" name="rsn_id" value="<?= $rsnId ?>">
                                <label class="sr-only">RSN</label>
                                <input name="rsn" value="<?= h($row['rsn']) ?>" required>
                                <label class="checkbox-inline"><input type="checkbox" name="is_primary" value="1" <?= $isPrimary ? 'checked' : '' ?>> Primary</label>
                                <button class="button small primary" type="submit">Save</button>
                            </form>
                        </td>
                        <td><?= $isActive ? badge('active') : badge('archived') ?></td>
                        <td><?= $isPrimary ? badge('current') : '<span class="muted">No</span>' ?></td>
                        <td class="actions-cell">
                            <?php if (!$isPrimary && $isActive): ?>
                                <form method="post" class="row-action">
                                    <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
                                    <input type="hidden" name="action" value="<?= $selfProfile ? 'profile_set_primary_rsn' : 'set_primary_admin_rsn' ?>">
                                    <input type="hidden" name="rsn_id" value="<?= $rsnId ?>">
                                    <button class="button small" type="submit">Set primary</button>
                                </form>
                            <?php endif; ?>
                            <?php if (!$isPrimary && $isActive): ?>
                                <form method="post" class="row-action" onsubmit="return confirm('Archive this RSN?');">
                                    <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
                                    <input type="hidden" name="action" value="<?= $selfProfile ? 'profile_archive_rsn' : 'archive_admin_rsn' ?>">
                                    <input type="hidden" name="rsn_id" value="<?= $rsnId ?>">
                                    <button class="button small" type="submit">Archive</button>
                                </form>
                            <?php elseif (!$isPrimary && !$selfProfile): ?>
                                <form method="post" class="row-action">
                                    <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
                                    <input type="hidden" name="action" value="restore_admin_rsn">
                                    <input type="hidden" name="rsn_id" value="<?= $rsnId ?>">
                                    <button class="button small primary" type="submit">Restore</button>
                                </form>
                            <?php endif; ?>
                            <?php if (!$isPrimary && !$selfProfile): ?>
                                <form method="post" class="row-action" onsubmit="return confirm('Delete this RSN record?');">
                                    <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
                                    <input type="hidden" name="action" value="delete_admin_rsn">
                                    <input type="hidden" name="rsn_id" value="<?= $rsnId ?>">
                                    <button class="button small danger" type="submit">Delete</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rsns): ?><tr><td colspan="4" class="empty">No RSNs recorded.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <form method="post" class="grid-form full-width">
            <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
            <input type="hidden" name="action" value="<?= $selfProfile ? 'profile_add_rsn' : 'add_admin_rsn' ?>">
            <?php if (!$selfProfile): ?><input type="hidden" name="admin_id" value="<?= $adminId ?>"><?php endif; ?>
            <label>New RSN <input name="rsn" required placeholder="RuneScape name"></label>
            <label class="checkbox-card"><input type="checkbox" name="is_primary" value="1"> Make primary</label>
            <div class="form-actions"><button class="button primary" type="submit">Add RSN</button></div>
        </form>
    </section>

    <section class="card">
        <div class="section-header"><div><h2>Primary RSN history</h2><p class="muted">This records previous primary RSNs for audit context.</p></div></div>
        <div class="history-list">
            <?php foreach ($history as $row): ?>
                <div class="history-row"><strong><?= h($row['rsn']) ?></strong> <?= ((int)$row['is_current'] === 1) ? badge('current') : '' ?><small><?= h(local_datetime($row['effective_from'])) ?><?= $row['effective_to'] ? ' → ' . h(local_datetime($row['effective_to'])) : '' ?></small></div>
            <?php endforeach; ?>
            <?php if (!$history): ?><p class="muted">No RSN history recorded yet.</p><?php endif; ?>
        </div>
    </section>
    <?php
}


function render_reports(ReportService $reports): void
{
    $activeReport = (string)($_GET['report'] ?? 'profit_loss');
    $allowed = ['profit_loss', 'treasury_movement', 'admin_held', 'account_activity'];
    if (!in_array($activeReport, $allowed, true)) {
        $activeReport = 'profit_loss';
    }

    $bounds = report_date_bounds();
    $accounts = $reports->reportAccounts();
    $selectedAccountId = (int)($_GET['account_id'] ?? 0);
    if ($selectedAccountId <= 0) {
        foreach ($accounts as $account) {
            if (in_array($account['account_type'], ['income', 'expense'], true) && (int)$account['is_system'] === 0) {
                $selectedAccountId = (int)$account['id'];
                break;
            }
        }
        if ($selectedAccountId <= 0 && $accounts) {
            $selectedAccountId = (int)$accounts[0]['id'];
        }
    }
    ?>
    <section class="card">
        <div class="section-header">
            <div>
                <h2>Reports</h2>
                <p class="muted">Showing <?= h(report_date_label($bounds)) ?>. Reversed transactions and reversal entries are excluded from report totals.</p>
            </div>
        </div>
        <nav class="status-tabs report-tabs" aria-label="Report tabs">
            <a class="<?= $activeReport === 'profit_loss' ? 'active' : '' ?>" href="<?= h(url_for('reports', array_filter(['report' => 'profit_loss', 'from' => $bounds['from'], 'to' => $bounds['to']], fn($v) => $v !== ''))) ?>">Revenue & expenses</a>
            <a class="<?= $activeReport === 'treasury_movement' ? 'active' : '' ?>" href="<?= h(url_for('reports', array_filter(['report' => 'treasury_movement', 'from' => $bounds['from'], 'to' => $bounds['to']], fn($v) => $v !== ''))) ?>">Treasury movement</a>
            <a class="<?= $activeReport === 'admin_held' ? 'active' : '' ?>" href="<?= h(url_for('reports', array_filter(['report' => 'admin_held', 'from' => $bounds['from'], 'to' => $bounds['to']], fn($v) => $v !== ''))) ?>">Admin funds owed</a>
            <a class="<?= $activeReport === 'account_activity' ? 'active' : '' ?>" href="<?= h(url_for('reports', array_filter(['report' => 'account_activity', 'account_id' => $selectedAccountId, 'from' => $bounds['from'], 'to' => $bounds['to']], fn($v) => $v !== '' && $v !== 0))) ?>">Account activity</a>
        </nav>
        <?php render_report_filters($activeReport, $bounds, $accounts, $selectedAccountId); ?>
    </section>

    <?php if ($activeReport === 'profit_loss'): ?>
        <?php render_profit_loss_report($reports->profitAndLoss($bounds['from_utc'], $bounds['to_utc'])); ?>
    <?php elseif ($activeReport === 'treasury_movement'): ?>
        <?php render_treasury_movement_report($reports->treasuryMovement($bounds['from_utc'], $bounds['to_utc'])); ?>
    <?php elseif ($activeReport === 'admin_held'): ?>
        <?php render_admin_held_report($reports->adminHeldFunds($bounds['from_utc'], $bounds['to_utc'])); ?>
    <?php elseif ($activeReport === 'account_activity'): ?>
        <?php if ($selectedAccountId > 0): ?>
            <?php try { render_account_activity_report($reports->accountActivity($selectedAccountId, $bounds['from_utc'], $bounds['to_utc'])); } catch (Throwable $e) { render_not_found('The selected account could not be found.', 'reports'); } ?>
        <?php else: ?>
            <section class="notice-card"><h2>No accounts yet</h2><p>Create accounts or post transactions before using account activity.</p></section>
        <?php endif; ?>
    <?php endif; ?>
    <?php
}

function render_report_filters(string $activeReport, array $bounds, array $accounts, int $selectedAccountId): void
{
    ?>
    <form method="get" class="filter-form report-filter-form">
        <input type="hidden" name="page" value="reports">
        <input type="hidden" name="report" value="<?= h($activeReport) ?>">
        <label>From <input type="date" name="from" value="<?= h($bounds['from']) ?>"></label>
        <label>To <input type="date" name="to" value="<?= h($bounds['to']) ?>"></label>
        <?php if ($activeReport === 'account_activity'): ?>
            <label>Account <?= report_account_select($accounts, $selectedAccountId) ?></label>
        <?php endif; ?>
        <button class="button" type="submit">Run report</button>
        <a class="button ghost" href="<?= h(url_for('reports', ['report' => $activeReport] + ($activeReport === 'account_activity' && $selectedAccountId > 0 ? ['account_id' => $selectedAccountId] : []))) ?>">Clear dates</a>
    </form>
    <?php
}

function report_account_select(array $accounts, int $selectedAccountId): string
{
    ob_start();
    ?>
    <select name="account_id" required>
        <?php foreach ($accounts as $account): ?>
            <option value="<?= (int)$account['id'] ?>" <?= $selectedAccountId === (int)$account['id'] ? 'selected' : '' ?>>
                <?= h($account['code'] . ' · ' . $account['name'] . ' (' . ucwords($account['account_type']) . ')' . ((int)$account['is_active'] === 1 ? '' : ' — archived')) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <?php
    return ob_get_clean();
}

function render_profit_loss_report(array $report): void
{
    ?>
    <section class="metric-grid">
        <div class="metric"><span>Total revenue</span><strong><?= h(GP::format($report['income_total'])) ?></strong><small>Credited to revenue accounts</small></div>
        <div class="metric"><span>Total expenses</span><strong><?= h(GP::format($report['expense_total'])) ?></strong><small>Debited to expense accounts</small></div>
        <div class="metric"><span>Net GP movement</span><strong><?= h(GP::format($report['net_movement'])) ?></strong><small>Revenue minus expenses</small></div>
        <div class="metric"><span>Posting accounts</span><strong><?= count($report['income']) + count($report['expenses']) ?></strong><small>Accounts with activity</small></div>
    </section>
    <section class="grid two">
        <div class="card">
            <h2>Revenue</h2>
            <?php render_pl_account_table($report['income'], 'No revenue recorded for this period.'); ?>
            <div class="report-total"><span>Total revenue</span><strong><?= h(GP::format($report['income_total'])) ?></strong></div>
        </div>
        <div class="card">
            <h2>Expenses</h2>
            <?php render_pl_account_table($report['expenses'], 'No expenses recorded for this period.'); ?>
            <div class="report-total"><span>Total expenses</span><strong><?= h(GP::format($report['expense_total'])) ?></strong></div>
        </div>
    </section>
    <section class="card report-net-card">
        <div class="section-header"><div><h2>Net GP movement</h2><p class="muted">This is not the official treasury balance. It is income minus expenses for the selected period.</p></div><strong class="amount report-grand-total"><?= h(GP::format($report['net_movement'])) ?></strong></div>
    </section>
    <?php
}

function render_pl_account_table(array $rows, string $empty): void
{
    if (!$rows) {
        echo '<p class="empty">' . h($empty) . '</p>';
        return;
    }
    ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Account</th><th class="right">Debits</th><th class="right">Credits</th><th class="right">Total</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><a href="<?= h(url_for('reports', ['report' => 'account_activity', 'account_id' => (int)$row['id']])) ?>"><code><?= h($row['code']) ?></code> <?= h($row['name']) ?></a></td>
                    <td class="right amount"><?= h(GP::format($row['debits'])) ?></td>
                    <td class="right amount"><?= h(GP::format($row['credits'])) ?></td>
                    <td class="right amount"><?= h(GP::format($row['movement'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function render_treasury_movement_report(array $report): void
{
    ?>
    <section class="metric-grid">
        <div class="metric"><span>Opening official treasury</span><strong><?= h(GP::format($report['opening_balance'])) ?></strong><small>Balance before selected period</small></div>
        <div class="metric"><span>Moved into treasury</span><strong><?= h(GP::format($report['money_in'])) ?></strong><small>Debits to official treasury</small></div>
        <div class="metric"><span>Paid out of treasury</span><strong><?= h(GP::format($report['money_out'])) ?></strong><small>Credits from official treasury</small></div>
        <div class="metric"><span>Closing official treasury</span><strong><?= h(GP::format($report['closing_balance'])) ?></strong><small>Opening plus net movement</small></div>
    </section>
    <section class="card">
        <h2>Official treasury activity</h2>
        <?php render_account_activity_rows($report['rows']); ?>
    </section>
    <?php
}

function render_admin_held_report(array $report): void
{
    ?>
    <section class="metric-grid">
        <div class="metric"><span>Admins owe treasury</span><strong><?= h(GP::format($report['totals']['current_held'])) ?></strong><small>GP received by admins, not yet handed over</small></div>
        <div class="metric"><span>Received in period</span><strong><?= h(GP::format($report['totals']['received_amount'])) ?></strong><small><?= (int)$report['totals']['received_count'] ?> payments</small></div>
        <div class="metric"><span>Handed over in period</span><strong><?= h(GP::format($report['totals']['reconciled_amount'])) ?></strong><small><?= (int)$report['totals']['reconciled_count'] ?> handovers</small></div>
        <div class="metric"><span>Admins</span><strong><?= count($report['rows']) ?></strong><small>Active and archived users</small></div>
    </section>
    <section class="card">
        <h2>Admin funds owed</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Admin</th><th class="right">Owed to treasury</th><th class="right">Received in period</th><th class="right">Handed over in period</th><th>Last handover</th></tr></thead>
                <tbody>
                <?php foreach ($report['rows'] as $row): ?>
                    <tr>
                        <td><?= h($row['display_name'] ?: $row['rsn']) ?><?= (int)$row['is_active'] === 1 ? '' : ' ' . badge('archived') ?><small><?= h($row['rsn']) ?></small></td>
                        <td class="right amount"><?= h(GP::format($row['current_held'])) ?></td>
                        <td class="right amount"><?= h(GP::format($row['received_amount'])) ?><small><?= (int)$row['received_count'] ?> payments</small></td>
                        <td class="right amount"><?= h(GP::format($row['reconciled_amount'])) ?><small><?= (int)$row['reconciled_count'] ?> handovers</small></td>
                        <td><?= h(local_datetime($row['last_reconciled_at'] ?? null)) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$report['rows']): ?><tr><td colspan="5" class="empty">No treasury users found.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php
}

function render_account_activity_report(array $report): void
{
    $account = $report['account'];
    ?>
    <section class="metric-grid">
        <div class="metric"><span>Opening balance</span><strong><?= h(GP::format($report['opening_balance'])) ?></strong><small><?= h($account['code']) ?> · <?= h($account['name']) ?></small></div>
        <div class="metric"><span>Debits</span><strong><?= h(GP::format($report['debits'])) ?></strong><small>Period total</small></div>
        <div class="metric"><span>Credits</span><strong><?= h(GP::format($report['credits'])) ?></strong><small>Period total</small></div>
        <div class="metric"><span>Closing balance</span><strong><?= h(GP::format($report['closing_balance'])) ?></strong><small>Normal balance: <?= h($account['normal_balance']) ?></small></div>
    </section>
    <section class="card">
        <div class="section-header"><div><h2>Account activity</h2><p class="muted"><code><?= h($account['code']) ?></code> <?= h($account['name']) ?> · <?= h(ucwords($account['account_type'])) ?></p></div><strong class="amount"><?= h(GP::format($report['period_movement'])) ?></strong></div>
        <?php render_account_activity_rows($report['rows']); ?>
    </section>
    <?php
}

function render_account_activity_rows(array $rows): void
{
    if (!$rows) {
        echo '<p class="empty">No account activity found for this period.</p>';
        return;
    }
    ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Date</th><th>Description</th><th>Memo / RSN</th><th class="right">Debit</th><th class="right">Credit</th><th class="right">Movement</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= h(local_datetime($row['occurred_at'])) ?><small><?= h($row['transaction_type']) ?></small></td>
                    <td><a href="<?= h(url_for('transaction_detail', ['uuid' => $row['transaction_uuid']])) ?>"><?= h($row['description']) ?></a><small><?= h($row['app_name'] ?: 'Manual') ?></small></td>
                    <td><?= h($row['memo'] ?: '—') ?><small><?= h($row['player_rsn'] ?: ($row['admin_display_name'] ?: $row['admin_rsn'] ?: '—')) ?></small></td>
                    <td class="right amount"><?= (int)$row['debit_amount'] > 0 ? h(GP::format($row['debit_amount'])) : '—' ?></td>
                    <td class="right amount"><?= (int)$row['credit_amount'] > 0 ? h(GP::format($row['credit_amount'])) : '—' ?></td>
                    <td class="right amount"><?= h(GP::format($row['movement'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function render_integrations(array $apps): void
{
    ?>
    <section class="card">
        <div class="section-header">
            <div>
                <h2>Integrations</h2>
                <p class="muted">Manage source apps/integrations that can raise Treasury requests through the API.</p>
            </div>
            <div class="actions-cell">
                <a class="button primary" href="<?= h(url_for('integration_new')) ?>">New integration</a>
                <a class="button" href="api-docs.php" target="_blank" rel="noopener">API docs</a>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Name</th><th>Slug</th><th>Description</th><th>Usage</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($apps as $app): ?>
                    <?php
                        $isManual = (string)$app['slug'] === AppService::SYSTEM_MANUAL_SLUG;
                        $isActive = (int)$app['is_active'] === 1;
                        $usageTotal = (int)$app['api_key_count'] + (int)$app['payment_request_count'] + (int)$app['payout_request_count'] + (int)$app['transaction_count'] + (int)$app['account_count'];
                    ?>
                    <tr>
                        <td><strong><?= h($app['name']) ?></strong><?= $isManual ? '<small>Locked internal source</small>' : '' ?></td>
                        <td><code><?= h($app['slug']) ?></code></td>
                        <td><?= h($app['description'] ?: '—') ?></td>
                        <td><?= (int)$app['active_api_key_count'] ?> active keys<small><?= (int)$app['payment_request_count'] ?> money-in · <?= (int)$app['payout_request_count'] ?> money-out · <?= (int)$app['transaction_count'] ?> ledger · <?= (int)$app['account_count'] ?> accounts</small></td>
                        <td><?= $isActive ? badge('active') : badge('archived') ?></td>
                        <td class="actions-cell">
                            <?php if ($isManual): ?>
                                <span class="muted">System</span>
                            <?php else: ?>
                                <a class="button small" href="<?= h(url_for('integration_edit', ['id' => (int)$app['id']])) ?>">Edit</a>
                                <a class="button small" href="<?= h(url_for('api_keys', ['app_id' => (int)$app['id']])) ?>">API keys</a>
                                <?php if (!$isActive): ?>
                                    <form method="post" class="row-action"><input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>"><input type="hidden" name="action" value="restore_app"><input type="hidden" name="app_id" value="<?= (int)$app['id'] ?>"><button class="button small primary" type="submit">Restore</button></form>
                                <?php endif; ?>
                                <?php if ($usageTotal === 0): ?>
                                    <form method="post" class="row-action" onsubmit="return confirm('Delete this unused integration? This cannot be undone.');"><input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>"><input type="hidden" name="action" value="delete_app"><input type="hidden" name="app_id" value="<?= (int)$app['id'] ?>"><button class="button small danger" type="submit">Delete</button></form>
                                <?php elseif ($isActive): ?>
                                    <form method="post" class="row-action" onsubmit="return confirm('Archive this integration? Existing history and keys will remain.');"><input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>"><input type="hidden" name="action" value="archive_app"><input type="hidden" name="app_id" value="<?= (int)$app['id'] ?>"><button class="button small" type="submit">Archive</button></form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$apps): ?><tr><td colspan="6" class="empty">No integrations found.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php
}

function render_integration_form(?int $appId): void
{
    $service = new AppService();
    $app = null;
    if ($appId !== null && $appId > 0) {
        try {
            $app = $service->get($appId);
        } catch (Throwable $e) {
            render_not_found($e->getMessage(), 'integrations');
            return;
        }
        if ($service->isManualApp($app)) {
            render_not_found('Manual Entry is a locked internal integration.', 'integrations');
            return;
        }
    }
    ?>
    <section class="card">
        <div class="section-header">
            <div>
                <h2><?= $app ? 'Edit integration' : 'New integration' ?></h2>
                <p class="muted"><?= $app ? 'Update source app details. Take care changing slugs once an app is integrated.' : 'Create a source app/integration. The slug is generated automatically from the name.' ?></p>
            </div>
            <a class="button" href="<?= h(url_for('integrations')) ?>">Back to integrations</a>
        </div>
        <form method="post" class="grid-form">
            <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
            <input type="hidden" name="action" value="<?= $app ? 'update_app' : 'create_app' ?>">
            <?php if ($app): ?><input type="hidden" name="app_id" value="<?= (int)$app['id'] ?>"><?php endif; ?>
            <label>Name <input name="name" required value="<?= h($app['name'] ?? '') ?>" placeholder="Runes of Power"></label>
            <?php if ($app): ?>
                <label>Slug <input name="slug" required value="<?= h($app['slug']) ?>"><small>Changing this may affect integrations using stored source app details.</small></label>
            <?php endif; ?>
            <label class="wide">Description <input name="description" value="<?= h($app['description'] ?? '') ?>" placeholder="Optional integration notes"></label>
            <div class="form-actions"><button class="button primary" type="submit"><?= $app ? 'Save integration' : 'Create integration' ?></button></div>
        </form>
    </section>
    <?php
}

function render_api_keys(array $apps, array $apiKeys): void
{
    $selectedAppId = (int)($_GET['app_id'] ?? 0);
    $activeApps = array_values(array_filter($apps, fn(array $app): bool => (int)$app['is_active'] === 1 && (string)$app['slug'] !== AppService::SYSTEM_MANUAL_SLUG));
    if ($selectedAppId > 0) {
        $apiKeys = array_values(array_filter($apiKeys, fn(array $key): bool => (int)$key['app_id'] === $selectedAppId));
    }
    ?>
    <section class="card">
        <div class="section-header">
            <div>
                <h2>API keys</h2>
                <p class="muted">Create, edit, revoke, restore, delete unused keys, or regenerate replacement raw keys.</p>
            </div>
            <div class="actions-cell">
                <a class="button primary" href="<?= h(url_for('api_key_new', $selectedAppId > 0 ? ['app_id' => $selectedAppId] : [])) ?>">New API key</a>
                <a class="button" href="<?= h(url_for('integrations')) ?>">Integrations</a>
            </div>
        </div>
        <form method="get" class="filter-form filter-panel">
            <input type="hidden" name="page" value="api_keys">
            <label>Integration
                <select name="app_id" onchange="this.form.submit()">
                    <option value="">All integrations</option>
                    <?php foreach ($activeApps as $app): ?>
                        <option value="<?= (int)$app['id'] ?>" <?= $selectedAppId === (int)$app['id'] ? 'selected' : '' ?>><?= h($app['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <a class="button" href="<?= h(url_for('api_keys')) ?>">Clear</a>
        </form>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Key</th><th>Integration</th><th>Scopes</th><th>Created</th><th>Last used</th><th>Expires</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($apiKeys as $key): ?>
                    <?php $isActive = (int)$key['is_active'] === 1; $scopes = $key['scopes_array'] ?? []; ?>
                    <tr>
                        <td><strong><?= h($key['key_name']) ?></strong><small>ID <?= (int)$key['id'] ?></small></td>
                        <td><?= h($key['app_name']) ?><small><code><?= h($key['app_slug']) ?></code></small></td>
                        <td><?php foreach ($scopes as $scope): ?><code class="scope-code"><?= h($scope) ?></code> <?php endforeach; ?></td>
                        <td><?= h(local_datetime($key['created_at'])) ?></td>
                        <td><?= h(local_datetime($key['last_used_at'] ?? null)) ?></td>
                        <td><?= h(local_datetime($key['expires_at'] ?? null)) ?></td>
                        <td><?= $isActive ? badge('active') : badge('revoked') ?></td>
                        <td class="actions-cell">
                            <a class="button small" href="<?= h(url_for('api_key_edit', ['id' => (int)$key['id']])) ?>">Edit</a>
                            <?php if ($isActive): ?>
                                <form method="post" class="row-action" onsubmit="return confirm('Revoke this API key? Existing integrations using it will stop working.');"><input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>"><input type="hidden" name="action" value="revoke_api_key"><input type="hidden" name="api_key_id" value="<?= (int)$key['id'] ?>"><button class="button small" type="submit">Revoke</button></form>
                            <?php else: ?>
                                <form method="post" class="row-action"><input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>"><input type="hidden" name="action" value="restore_api_key"><input type="hidden" name="api_key_id" value="<?= (int)$key['id'] ?>"><button class="button small primary" type="submit">Restore</button></form>
                            <?php endif; ?>
                            <?php if (empty($key['last_used_at'])): ?>
                                <form method="post" class="row-action" onsubmit="return confirm('Delete this unused API key record?');"><input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>"><input type="hidden" name="action" value="delete_api_key"><input type="hidden" name="api_key_id" value="<?= (int)$key['id'] ?>"><button class="button small danger" type="submit">Delete</button></form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$apiKeys): ?><tr><td colspan="8" class="empty">No API keys found.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php
}

function render_api_key_form(?int $keyId, array $apps): void
{
    $service = new AppService();
    $key = null;
    if ($keyId !== null && $keyId > 0) {
        try {
            $key = $service->apiKey($keyId);
        } catch (Throwable $e) {
            render_not_found($e->getMessage(), 'api_keys');
            return;
        }
    }
    $selectedAppId = (int)($_GET['app_id'] ?? ($key['app_id'] ?? 0));
    $activeApps = array_values(array_filter($apps, fn(array $app): bool => (int)$app['is_active'] === 1 && (string)$app['slug'] !== AppService::SYSTEM_MANUAL_SLUG));
    $scopes = $key['scopes_array'] ?? [];
    ?>
    <section class="card">
        <div class="section-header">
            <div>
                <h2><?= $key ? 'Edit API key' : 'New API key' ?></h2>
                <p class="muted"><?= $key ? 'Edit permissions and expiry, or regenerate the raw key.' : 'Generate a new raw API key. Copy it from the success message; it is shown once only.' ?></p>
            </div>
            <a class="button" href="<?= h(url_for('api_keys')) ?>">Back to API keys</a>
        </div>
        <?php if (!$activeApps && !$key): ?>
            <p class="empty">Create an active integration before generating API keys.</p>
        <?php else: ?>
            <form method="post" class="grid-form">
                <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
                <input type="hidden" name="action" value="<?= $key ? 'update_api_key' : 'create_api_key' ?>">
                <?php if ($key): ?><input type="hidden" name="api_key_id" value="<?= (int)$key['id'] ?>"><?php endif; ?>
                <label>Integration
                    <select name="app_id" <?= $key ? 'disabled' : 'required' ?>>
                        <option value="">Select…</option>
                        <?php foreach ($activeApps as $app): ?>
                            <option value="<?= (int)$app['id'] ?>" <?= $selectedAppId === (int)$app['id'] ? 'selected' : '' ?>><?= h($app['name']) ?> (<?= h($app['slug']) ?>)</option>
                        <?php endforeach; ?>
                        <?php if ($key && !in_array((int)$key['app_id'], array_map(fn($a) => (int)$a['id'], $activeApps), true)): ?>
                            <option value="<?= (int)$key['app_id'] ?>" selected><?= h($key['app_name']) ?> (archived)</option>
                        <?php endif; ?>
                    </select>
                </label>
                <label>Key name <input name="key_name" required value="<?= h($key['key_name'] ?? '') ?>" placeholder="Production integration"></label>
                <label>Expires on <input type="date" name="expires_at" value="<?= h(!empty($key['expires_at']) ? substr((string)$key['expires_at'], 0, 10) : '') ?>"><small>Leave blank for no expiry.</small></label>
                <div class="wide">
                    <label>Scopes</label>
                    <div class="scope-grid">
                        <?php foreach (AppService::AVAILABLE_SCOPES as $scope => $label): ?>
                            <label class="scope-check"><input type="checkbox" name="scopes[]" value="<?= h($scope) ?>" <?= in_array($scope, $scopes, true) ? 'checked' : '' ?>> <span><code><?= h($scope) ?></code><small><?= h($label) ?></small></span></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="form-actions"><button class="button primary" type="submit"><?= $key ? 'Save API key' : 'Generate API key' ?></button></div>
            </form>
            <?php if ($key): ?>
                <div class="danger-zone">
                    <h2>Regenerate raw key</h2>
                    <p class="muted">This immediately invalidates the old raw key. The replacement key is shown once in the success message.</p>
                    <form method="post" onsubmit="return confirm('Regenerate this API key? The current raw key will stop working immediately.');">
                        <input type="hidden" name="_csrf" value="<?= h(Csrf::token()) ?>">
                        <input type="hidden" name="action" value="regenerate_api_key">
                        <input type="hidden" name="api_key_id" value="<?= (int)$key['id'] ?>">
                        <button class="button danger" type="submit">Regenerate key</button>
                    </form>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
    <?php
}


function render_source_apps(array $apps, array $apiKeys): void
{
    render_integrations($apps);
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
        <p class="muted"><a href="<?= h(url_for('integrations')) ?>">Open Integrations</a> to manage source apps, or <a href="<?= h(url_for('api_keys')) ?>">open API Keys</a> to manage API access.</p>
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
                        <strong><a href="<?= h(url_for('transaction_detail', ['uuid' => $transaction['transaction_uuid']])) ?>"><?= h($transaction['description']) ?></a></strong>
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
                        <p class="muted">Related transaction: <a href="<?= h(url_for('transaction_detail', ['uuid' => $transaction['related_transaction_uuid']])) ?>"><code><?= h($transaction['related_transaction_uuid']) ?></code></a></p>
                    <?php endif; ?>
                    <?php if (!empty($transaction['reversal_uuid'])): ?>
                        <div class="notice-inline warning-text">This transaction has been reversed by <a href="<?= h(url_for('transaction_detail', ['uuid' => $transaction['reversal_uuid']])) ?>"><code><?= h($transaction['reversal_uuid']) ?></code></a>.</div>
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
