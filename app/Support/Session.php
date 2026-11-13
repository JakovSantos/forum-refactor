<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Session handling: cookie settings, ID regeneration, logout and flash messages.
 */
final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // Refuse session IDs the server did not create, and never put the ID in a URL.
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');

        session_name('forum_sid');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    }

    /** New session ID, old session file deleted. Called right after a successful login. */
    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    /** Empties the session, expires the cookie and destroys the session file. */
    public static function destroy(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $p['path'],
                'domain' => $p['domain'],
                'secure' => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'],
            ]);
        }

        session_destroy();
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['flash'][$type] = $message;
    }

    /** Returns a flash message once and removes it. */
    public static function pullFlash(string $type): ?string
    {
        $message = $_SESSION['flash'][$type] ?? null;
        unset($_SESSION['flash'][$type]);

        return is_string($message) ? $message : null;
    }

    private static function isHttps(): bool
    {
        if (Config::get('app.secure_cookies') === true) {
            return true;
        }

        $https = $_SERVER['HTTPS'] ?? '';

        return $https !== '' && strtolower((string) $https) !== 'off';
    }
}
