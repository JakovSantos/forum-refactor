<?php

declare(strict_types=1);

namespace App\Http;

use RuntimeException;

/**
 * Thrown anywhere in the app to stop the request with a specific HTTP status.
 * The front controller turns it into an error page.
 */
final class HttpException extends RuntimeException
{
    private const TITLES = [
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        422 => 'Unprocessable Content',
        500 => 'Server Error',
    ];

    public function __construct(
        public readonly int $status,
        string $message,
        public readonly ?string $actionUrl = null,
        public readonly ?string $actionLabel = null,
    ) {
        parent::__construct($message, $status);
    }

    public function title(): string
    {
        return self::TITLES[$this->status] ?? 'Error';
    }
}
