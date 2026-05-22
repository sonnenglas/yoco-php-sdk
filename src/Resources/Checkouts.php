<?php

declare(strict_types=1);

namespace Sonnenglas\Yoco\Resources;

use InvalidArgumentException;
use Random\RandomException;
use Sonnenglas\Yoco\Dto\CheckoutResponse;
use Sonnenglas\Yoco\Dto\CreateCheckoutRequest;
use Sonnenglas\Yoco\Dto\RefundResponse;
use Sonnenglas\Yoco\Exceptions\ApiException;

class Checkouts extends BaseResource
{
    /**
     * Create a Yoco hosted checkout session.
     *
     * @param string|null $idempotencyKey Optional client-supplied Idempotency-Key.
     *                                    If null, a fresh UUID v4 is generated.
     *                                    Re-using the same key for retries makes
     *                                    the request safe to repeat (Yoco returns
     *                                    the original response and does NOT create
     *                                    a duplicate checkout).
     *
     * @throws ApiException
     */
    public function create(CreateCheckoutRequest $request, ?string $idempotencyKey = null): CheckoutResponse
    {
        $headers = ['Idempotency-Key' => $idempotencyKey ?? self::generateIdempotencyKey()];

        $response = $this->http->post('/checkouts', $request->toArray(), $headers);

        return CheckoutResponse::fromArray($response);
    }

    /**
     * Refund a successful checkout, fully or partially.
     *
     * @param string      $checkoutId      The id of the checkout to refund.
     * @param int|null    $amount          Refund amount in cents. Pass null to
     *                                     refund the full original amount.
     * @param string|null $idempotencyKey  Optional client-supplied Idempotency-Key
     *                                     (auto-generated UUID v4 if null).
     *
     * @throws ApiException
     */
    public function refund(string $checkoutId, ?int $amount = null, ?string $idempotencyKey = null): RefundResponse
    {
        if ($checkoutId === '') {
            throw new InvalidArgumentException('checkoutId must not be empty');
        }
        if ($amount !== null && $amount < 1) {
            throw new InvalidArgumentException('amount must be a positive integer when provided');
        }

        // Full refund: send NO body (Yoco rejects `[]` JSON arrays with
        // "Missing or incorrect value was provided for field unknown").
        // Partial refund: explicit object with the amount.
        $body = $amount === null ? null : ['amount' => $amount];
        $headers = ['Idempotency-Key' => $idempotencyKey ?? self::generateIdempotencyKey()];

        $response = $this->http->post(
            '/checkouts/'.rawurlencode($checkoutId).'/refund',
            $body,
            $headers,
        );

        return RefundResponse::fromArray($response);
    }

    private static function generateIdempotencyKey(): string
    {
        try {
            $bytes = random_bytes(16);
        } catch (RandomException $e) {
            throw new ApiException('Failed to generate Idempotency-Key', 0, [], $e);
        }

        // RFC 4122 v4
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return sprintf(
            '%s-%s-%s-%s-%s',
            bin2hex(substr($bytes, 0, 4)),
            bin2hex(substr($bytes, 4, 2)),
            bin2hex(substr($bytes, 6, 2)),
            bin2hex(substr($bytes, 8, 2)),
            bin2hex(substr($bytes, 10, 6)),
        );
    }
}
