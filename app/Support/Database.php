<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Capsule\Manager as Capsule;
use PDO;

/**
 * Boots Eloquent. Eloquent talks to MySQL through PDO and sends every value as
 * a bound parameter, so no SQL string in this project contains user input.
 */
final class Database
{
    public static function boot(): void
    {
        $capsule = new Capsule();

        $capsule->addConnection(Config::get('database') + [
            'options' => [
                // Real prepared statements, not client-side string escaping.
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        ]);

        $capsule->setAsGlobal();
        $capsule->bootEloquent();
    }
}
