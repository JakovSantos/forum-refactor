<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\HttpException;

/**
 * Session-backed CSRF token. Every form that changes state (register, log in,
 * log out, post a comment or reply) carries it in a hidden "_token" field.
 */
final class Csrf
{
    private const KEY = '_csrf_token';

    public static function token(): string
    {
        $token = $_SESSION[self::KEY] ?? null;

        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::KEY] = $token;
        }

        return $token;
    }

    /** Throws the token away so the next page gets a fresh one. */
    public static function reset(): void
    {
        unset($_SESSION[self::KEY]);
    }

    public static function isValid(mixed $submitted): bool
    {
        $expected = $_SESSION[self::KEY] ?? null;

        return is_string($submitted)
            && $submitted !== ''
            && is_string($expected)
            && $expected !== ''
            && hash_equals($expected, $submitted);
    }

    /** Call at the top of every POST handler. */
    public static function require(): void
    {
        if (!self::isValid($_POST['_token'] ?? null)) {
            throw new HttpException(
                403,
                'The security token was missing or did not match. Nothing was saved. Reload the page and try again.'
            );
        }
    }
}
