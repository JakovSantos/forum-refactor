<?php

declare(strict_types=1);

/**
 * Front controller. Every request enters here.
 *
 * Local run (from the project root):
 *   php -S 127.0.0.1:8000 -t public public/index.php
 */

// With PHP's built-in server, let real files (CSS, SVG) be served directly.
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

    if ($path !== '/' && is_file(__DIR__ . $path)) {
        return false;
    }
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Http\ErrorHandler;
use App\Support\Config;
use Bramus\Router\Router;

$router = new Router();
$router->setBasePath((string) Config::get('app.base_path'));

(require BASE_DIR . '/config/routes.php')($router);

// Buffer the page so a failure halfway through never leaves half a page behind.
ob_start();

try {
    $router->run();
    ob_end_flush();
} catch (Throwable $e) {
    ob_end_clean();
    ErrorHandler::render($e);
}
