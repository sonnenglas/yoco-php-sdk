<?php

declare(strict_types=1);

namespace Sonnenglas\Yoco\Dto;

final readonly class WebhookEvent
{
    public const TYPE_PAYMENT_SUCCEEDED = 'payment.succeeded';

    public const TYPE_PAYMENT_FAILED = 'payment.failed';

    public const TYPE_REFUND_SUCCEEDED = 'refund.succeeded';

    public const TYPE_REFUND_FAILED = 'refund.failed';

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $createdDate,
        public array $payload,
    ) {}

    /**
     * Returns a typed payload when this is a `payment.*` event, or null
     * otherwise. The raw `$payload` array remains available for forward
     * compatibility with new fields.
     */
    public function asPaymentPayload(): ?PaymentEventPayload
    {
        if (! str_starts_with($this->type, 'payment.')) {
            return null;
        }

        return PaymentEventPayload::fromArray($this->payload);
    }

    /**
     * Returns a typed payload when this is a `refund.*` event, or null
     * otherwise.
     */
    public function asRefundPayload(): ?RefundEventPayload
    {
        if (! str_starts_with($this->type, 'refund.')) {
            return null;
        }

        return RefundEventPayload::fromArray($this->payload);
    }
}
