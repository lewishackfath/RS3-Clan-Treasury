<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'Treasury\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use Treasury\Support\Env;

Env::load(dirname(__DIR__) . '/.env');
