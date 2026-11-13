<?php

declare(strict_types=1);

namespace App\Http;

use App\Support\Config;
use App\Support\View;
use Throwable;

/**
 * Turns an exception into an HTML error page.
 *
 * HttpException  -> its own status and a short message for the visitor.
 * anything else  -> generic 500 page. The log line holds only the class name,
 *                   code and location, never the message, because database
 *                   errors can contain bound values such as emails or hashes.
 */
final class ErrorHandler
{
    public static function render(Throwable $e): void
    {
        if (!$e instanceof HttpException) {
            error_log(sprintf('[forum] %s (code %s) at %s:%d', get_class($e), (string) $e->getCode(), basename($e->getFile()), $e->getLine()));
            $e = new HttpException(500, 'Something went wrong on the server. Nothing was saved.');
        }

        try {
            View::render('error', [
                'page_title' => $e->status . ' ' . $e->title(),
                'status' => $e->status,
                'title' => $e->title(),
                'message' => $e->getMessage(),
                'action_url' => $e->actionUrl === null ? null : Config::get('app.base_path') . $e->actionUrl,
                'action_label' => $e->actionLabel,
            ], $e->status);
        } catch (Throwable) {
            // The error page itself failed (for example the database is down).
            http_response_code($e->status);
            header('Content-Type: text/plain; charset=utf-8');
            echo $e->status . ' ' . $e->title() . "\n" . $e->getMessage() . "\n";
        }
    }
}
