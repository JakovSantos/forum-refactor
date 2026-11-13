<?php

declare(strict_types=1);

namespace App\Support;

use Mustache_Engine;
use Mustache_Loader_FilesystemLoader;

/**
 * Renders Mustache templates from app/Views.
 *
 * Escaping: every {{ name }} tag goes through htmlspecialchars(). No template
 * in this project uses {{{ name }}} or {{& name }}, so user text is never
 * written into the page as raw HTML.
 */
final class View
{
    private static ?Mustache_Engine $engine = null;

    public static function engine(): Mustache_Engine
    {
        if (self::$engine === null) {
            $dir = BASE_DIR . '/app/Views';

            self::$engine = new Mustache_Engine([
                'loader' => new Mustache_Loader_FilesystemLoader($dir, ['extension' => '.mustache']),
                'partials_loader' => new Mustache_Loader_FilesystemLoader($dir, ['extension' => '.mustache']),
                'escape' => static fn ($value): string => htmlspecialchars(
                    (string) $value,
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8'
                ),
            ]);
        }

        return self::$engine;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function render(string $template, array $data = [], int $status = 200): void
    {
        $user = Auth::user();

        // Values every page needs. Controllers can override any of them.
        $globals = [
            'app_name' => Config::get('app.name'),
            'base' => Config::get('app.base_path'),
            'csrf_token' => Csrf::token(),
            'current_user' => $user === null ? null : ['username' => $user->username],
            'flash_success' => Session::pullFlash('success'),
            'flash_error' => Session::pullFlash('error'),
        ];

        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');

        echo self::engine()->render($template, $data + $globals);
    }
}
