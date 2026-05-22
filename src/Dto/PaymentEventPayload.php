<?php

declare(strict_types=1);

namespace Sonnenglas\Yoco\Dto;

use Sonnenglas\Yoco\Exceptions\ApiException;

/**
 * Typed view of the `payload` field on `payment.succeeded` and
 * `payment.failed` webhook events.
 *
 * Use {@see WebhookEvent::asPaymentPayload()} to obtain this from a verified
 * event — the raw payload remains available on `WebhookEvent::$payload` for
 * forward compatibility with new fields.
 */
final readonly class PaymentEventPayload
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
        public array $metadata = [],
        public ?PaymentMethodDetails $paymentMethodDetails = null,
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
            throw new ApiException('Yoco payment event payload missing required fields (id, amount, currency, status)', 0, $payload);
        }

        $mode = $payload['mode'] ?? null;
        if ($mode !== null && ! is_string($mode)) {
            throw new ApiException('Yoco payment event mode must be a string when present', 0, $payload);
        }

        $merchantId = $payload['merchantId'] ?? null;
        if ($merchantId !== null && ! is_string($merchantId)) {
            throw new ApiException('Yoco payment event merchantId must be a string when present', 0, $payload);
        }

        $metadata = $payload['metadata'] ?? [];
        if (! is_array($metadata)) {
            throw new ApiException('Yoco payment event metadata must be an object', 0, $payload);
        }
        /** @var array<string, mixed> $metadata */

        $details = $payload['paymentMethodDetails'] ?? null;
        $paymentMethodDetails = null;
        if ($details !== null) {
            if (! is_array($details)) {
                throw new ApiException('Yoco payment event paymentMethodDetails must be an object when present', 0, $payload);
            }
            /** @var array<string, mixed> $details */
            $paymentMethodDetails = PaymentMethodDetails::fromArray($details);
        }

        return new self(
            id: $id,
            amount: $amount,
            currency: $currency,
            status: $status,
            mode: $mode,
            merchantId: $merchantId,
            metadata: $metadata,
            paymentMethodDetails: $paymentMethodDetails,
        );
    }
}
