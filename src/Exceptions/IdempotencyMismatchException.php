<?php

declare(strict_types=1);

namespace Sonnenglas\Yoco\Exceptions;

/**
 * Thrown when Yoco returns HTTP 422 from the Checkout API — the request body,
 * method, or path differs from the original request stored under the supplied
 * Idempotency-Key. Generate a new Idempotency-Key or re-send the exact original
 * payload.
 *
 * NOTE: in Yoco's Checkout API 422 is reserved for idempotency mismatches and
 * does NOT signal generic request-body validation errors — those return 400.
 */
final class IdempotencyMismatchException extends ApiException
{
}
