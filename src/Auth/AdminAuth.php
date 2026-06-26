<?php

declare(strict_types=1);

namespace Treasury\Auth;

use Treasury\Support\Env;

final class AdminAuth
{
    public static function requireAdminToken(): void
    {
        $expected = Env::get('ADMIN_API_TOKEN', '');
        $provided = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '';

        if ($expected === '' || $expected === 'change_this_to_a_long_random_secret') {
            throw new \RuntimeException('Admin API token is not configured', 500);
        }

        if (!hash_equals($expected, $provided)) {
            throw new \RuntimeException('Invalid admin token', 401);
        }
    }
}
