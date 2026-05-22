<?php

declare(strict_types=1);

namespace Sonnenglas\Yoco\Exceptions;

use Throwable;

final class RateLimitException extends ApiException
{
    /**
     * @param array<string, mixed> $responseBody
     * @param int|null             $retryAfter Number of seconds the caller
     *                                         should wait before retrying, as
     *                                         parsed from the Retry-After
     *                                         response header. `null` if the
     *                                         header was missing or unparseable.
     */
    public function __construct(
        string $message,
        int $statusCode,
        array $responseBody = [],
        ?Throwable $previous = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $statusCode, $responseBody, $previous);
    }
}
