<?php

declare(strict_types=1);

namespace App\Support;

final class Response
{
    /** Redirect after a POST so a refresh does not send the form again. */
    public static function redirect(string $path): never
    {
        header('Location: ' . Config::get('app.base_path') . $path, true, 302);
        exit;
    }
}
