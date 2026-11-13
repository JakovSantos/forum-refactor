<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\HttpException;

/**
 * Small helpers for reading request data safely.
 */
final class Input
{
    /**
     * Turns a URL segment into a positive integer ID.
     * Anything else (letters, 0, negative, leading zeros, huge numbers, spaces)
     * is a malformed ID and gets 400 Bad Request.
     */
    public static function id(string $raw): int
    {
        if (preg_match('/^[1-9][0-9]{0,17}$/', $raw) !== 1) {
            throw new HttpException(400, 'The ID in the address is not a valid number.');
        }

        return (int) $raw;
    }

    /** A trimmed single-line POST field, or an empty string if it is missing or not text. */
    public static function line(string $key): string
    {
        $value = $_POST[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    /** A POST field kept exactly as typed (used for passwords). */
    public static function raw(string $key): string
    {
        $value = $_POST[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * A multi-line POST field: valid UTF-8, no NUL bytes, \n line endings, trimmed.
     * Returns null when the value is missing, not text or not valid UTF-8.
     */
    public static function text(string $key): ?string
    {
        $value = $_POST[$key] ?? null;

        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            return null;
        }

        $value = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $value);

        return trim($value);
    }
}
