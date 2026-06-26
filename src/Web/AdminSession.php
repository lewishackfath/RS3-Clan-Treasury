<?php

declare(strict_types=1);

namespace Treasury\Web;

use Treasury\Support\Env;

final class AdminSession
{
    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name('rs3_gp_treasury');
            session_start();
        }
    }

    public static function login(string $password): bool
    {
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
        $_SESSION['login_time'] = time();
        return true;
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
        $_SESSION['acting_admin_id'] = $adminId;
    }
}
