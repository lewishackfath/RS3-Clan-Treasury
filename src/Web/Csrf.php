<?php

declare(strict_types=1);

namespace Treasury\Web;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function validate(): void
    {
        $provided = $_POST['_csrf'] ?? '';
        if (!is_string($provided) || !hash_equals(self::token(), $provided)) {
            throw new \RuntimeException('The form security token was invalid. Please try again.', 419);
        }
    }
}
