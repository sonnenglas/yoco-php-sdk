<?php

declare(strict_types=1);

namespace Sonnenglas\Yoco\Dto;

use Sonnenglas\Yoco\Exceptions\ApiException;

/**
 * Typed view of the `payload` field on `refund.succeeded` and `refund.failed`
 * webhook events.
 *
 * Use {@see WebhookEvent::asRefundPayload()} to obtain this from a verified
 * event.
 */
final readonly class RefundEventPayload
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public int $amount,
        public string $currency,
        public string $status,
        public ?string $mode = null,
        public ?string $merchantId = null,
        public ?int $refundableAmount = null,
        public array $metadata = [],
        public ?string $failureReason = null,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $id = $payload['id'] ?? null;
        $amount = $payload['amount'] ?? null;
        $currency = $payload['currency'] ?? null;
        $status = $payload['status'] ?? null;

        if (! is_string($id) || ! is_int($amount) || ! is_string($currency) || ! is_string($status)) {
            throw new ApiException('Yoco refund event payload missing required fields (id, amount, currency, status)', 0, $payload);
        }

        $mode = $payload['mode'] ?? null;
        if ($mode !== null && ! is_string($mode)) {
            throw new ApiException('Yoco refund event mode must be a string when present', 0, $payload);
        }

        $merchantId = $payload['merchantId'] ?? null;
        if ($merchantId !== null && ! is_string($merchantId)) {
            throw new ApiException('Yoco refund event merchantId must be a string when present', 0, $payload);
        }

        $refundableAmount = $payload['refundableAmount'] ?? null;
        if ($refundableAmount !== null && ! is_int($refundableAmount)) {
            throw new ApiException('Yoco refund event refundableAmount must be an int when present', 0, $payload);
        }

        $metadata = $payload['metadata'] ?? [];
        if (! is_array($metadata)) {
            throw new ApiException('Yoco refund event metadata must be an object', 0, $payload);
        }
        /** @var array<string, mixed> $metadata */

        $failureReason = $payload['failureReason'] ?? null;
        if ($failureReason !== null && ! is_string($failureReason)) {
            throw new ApiException('Yoco refund event failureReason must be a string when present', 0, $payload);
        }

        return new self(
            id: $id,
            amount: $amount,
            currency: $currency,
            status: $status,
            mode: $mode,
            merchantId: $merchantId,
            refundableAmount: $refundableAmount,
            metadata: $metadata,
            failureReason: $failureReason,
        );
    }
}
