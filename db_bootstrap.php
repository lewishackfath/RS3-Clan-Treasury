<?php

declare(strict_types=1);

define('TREASURY_SKIP_AUTO_DB_BOOTSTRAP', true);
require __DIR__ . '/src/bootstrap.php';

use Treasury\DatabaseBootstrap;

DatabaseBootstrap::run(true);

echo "Database bootstrap completed successfully.\n";
