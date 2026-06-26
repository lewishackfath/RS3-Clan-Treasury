<?php

declare(strict_types=1);

namespace Treasury\Web;

use Treasury\Support\Env;
use Treasury\Services\AdminService;

final class AdminSession
{
    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name('rs3_gp_treasury');
            session_start();
        }
    }

    public static function passwordLoginEnabled(): bool
    {
        return Env::bool('ADMIN_PASSWORD_LOGIN_ENABLED', true);
    }

    public static function login(string $password): bool
    {
        if (!self::passwordLoginEnabled()) {
            return false;
        }

        $expected = Env::get('ADMIN_UI_PASSWORD', '');
        if ($expected === '') {
            $expected = Env::get('ADMIN_API_TOKEN', '');
        }

        if ($expected === '' || $expected === 'change_this_to_a_long_random_secret') {
            throw new \RuntimeException('Admin UI password is not configured. Set ADMIN_UI_PASSWORD in .env.');
        }

        if (!hash_equals($expected, $password)) {
            return false;
        }

        $_SESSION['admin_logged_in'] = true;
        $_SESSION['auth_method'] = 'password';
        $_SESSION['discord_user'] = null;
        $_SESSION['login_time'] = time();
        return true;
    }

    public static function loginWithDiscord(array $discordUser, ?array $authorisation = null): void
    {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['auth_method'] = 'discord';
        $_SESSION['discord_user'] = [
            'id' => (string)($discordUser['id'] ?? ''),
            'username' => (string)($discordUser['username'] ?? ''),
            'global_name' => (string)($discordUser['global_name'] ?? ''),
            'avatar' => (string)($discordUser['avatar'] ?? ''),
            'authorisation_method' => (string)($authorisation['method'] ?? ''),
        ];
        $_SESSION['login_time'] = time();

        $admin = (new AdminService())->findByDiscordUserId((string)($discordUser['id'] ?? ''));
        if ($admin) {
            self::setActingAdminId((int)$admin['id']);
        } elseif (!empty($authorisation['admin']['id'])) {
            self::setActingAdminId((int)$authorisation['admin']['id']);
        } else {
            unset($_SESSION['acting_admin_id']);
        }
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
        }
        session_destroy();
    }

    public static function isLoggedIn(): bool
    {
        return !empty($_SESSION['admin_logged_in']);
    }

    public static function authMethod(): string
    {
        return (string)($_SESSION['auth_method'] ?? 'password');
    }

    public static function discordUser(): ?array
    {
        $user = $_SESSION['discord_user'] ?? null;
        return is_array($user) ? $user : null;
    }

    public static function discordUserId(): ?string
    {
        $user = self::discordUser();
        return $user && !empty($user['id']) ? (string)$user['id'] : null;
    }

    public static function displayName(): string
    {
        $user = self::discordUser();
        if (!$user) {
            return 'Password login';
        }

        return (string)($user['global_name'] ?: $user['username'] ?: ('Discord user ' . $user['id']));
    }

    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) {
            header('Location: ?page=login');
            exit;
        }
    }

    public static function actingAdminId(): ?int
    {
        return isset($_SESSION['acting_admin_id']) ? (int)$_SESSION['acting_admin_id'] : null;
    }

    public static function setActingAdminId(int $adminId): void
    {
        if (self::authMethod() === 'discord' && Env::bool('DISCORD_LOCK_ACTING_ADMIN_TO_LOGIN', false)) {
            $discordUserId = self::discordUserId();
            $admin = $discordUserId ? (new AdminService())->findByDiscordUserId($discordUserId) : null;
            if (!$admin || (int)$admin['id'] !== $adminId) {
                throw new \RuntimeException('Acting admin is locked to your Discord-linked treasury admin.');
            }
        }

        $_SESSION['acting_admin_id'] = $adminId;
    }
}
