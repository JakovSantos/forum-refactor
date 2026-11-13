<?php

declare(strict_types=1);

/**
 * Database settings for Eloquent.
 *
 * Order of precedence:
 *   1. config/database.local.php  (ignored by Git, copy the .example file)
 *   2. environment variables DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
 *   3. the XAMPP defaults below (user root, empty password)
 *
 * No real credentials are stored in this file.
 */
$local = __DIR__ . '/database.local.php';

if (is_file($local)) {
    return require $local;
}

$pass = getenv('DB_PASS');

return [
    'driver' => 'mysql',
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'port' => getenv('DB_PORT') ?: '3306',
    'database' => getenv('DB_NAME') ?: 'nested_forum',
    'username' => getenv('DB_USER') ?: 'root',
    'password' => $pass === false ? '' : $pass,
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'strict' => true,
    // Every connection works in UTC so created_at values are UTC.
    'timezone' => '+00:00',
];
