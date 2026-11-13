<?php

declare(strict_types=1);

/**
 * Copy this file to config/database.local.php and fill in your own values.
 * database.local.php is listed in .gitignore, so it is never committed.
 */
return [
    'driver' => 'mysql',
    'host' => '127.0.0.1',
    'port' => '3306',
    'database' => 'nested_forum',
    'username' => 'your_db_user',
    'password' => 'your_db_password',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'strict' => true,
    'timezone' => '+00:00',
];
