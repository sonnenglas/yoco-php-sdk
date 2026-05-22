<?php

declare(strict_types=1);

namespace Sonnenglas\Yoco\Exceptions;

/**
 * Thrown when Yoco returns HTTP 409 — the request collides with an in-flight
 * idempotent request that has the same Idempotency-Key but is still being
 * processed. Retry after a short delay.
 */
final class IdempotencyConflictException extends ApiException
{
}
