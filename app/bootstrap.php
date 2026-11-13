<?php

declare(strict_types=1);

/**
 * Starts everything a request needs before the router runs:
 * autoloading, configuration, timezone, Eloquent, the session and basic
 * security headers.
 */

use App\Support\Config;
use App\Support\Database;
use App\Support\Session;

define('BASE_DIR', dirname(__DIR__));

require BASE_DIR . '/vendor/autoload.php';

Config::load(BASE_DIR . '/config');

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

// Notices and warnings are logged, never printed into the page.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; img-src 'self'; "
    . "style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

Database::boot();
Session::start();
