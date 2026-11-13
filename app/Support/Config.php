<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Reads the arrays returned by config/app.php and config/database.php.
 * Usage: Config::get('app.limits.max_reply_depth')
 */
final class Config
{
    /** @var array<string, mixed> */
    private static array $items = [];

    public static function load(string $dir): void
    {
        foreach (['app', 'database'] as $name) {
            self::$items[$name] = require $dir . '/' . $name . '.php';
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$items;

        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }

        return $value;
    }
}
