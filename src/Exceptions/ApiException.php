<?php

declare(strict_types=1);

namespace Sonnenglas\Yoco\Exceptions;

use Throwable;

class ApiException extends YocoException
{
    /**
     * @param array<string, mixed> $responseBody
     */
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly array $responseBody = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
