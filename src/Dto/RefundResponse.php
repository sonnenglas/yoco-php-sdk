<?php

declare(strict_types=1);

namespace Sonnenglas\Yoco\Dto;

use Sonnenglas\Yoco\Exceptions\ApiException;

/**
 * Response from `POST /api/checkouts/{id}/refund`.
 *
 * Note: Yoco's refund endpoint typically returns a synchronous acknowledgement
 * (`status: "pending"` or `"succeeded"`) and the final outcome is delivered
 * asynchronously via the `refund.succeeded` / `refund.failed` webhook event.
 * The amount and currency are NOT returned in this response — they can be
 * retrieved from the webhook payload or by fetching the payment from the main
 * Yoco API.
 */
final readonly class RefundResponse
{
    public function __construct(
        public string $id,
        public string $status,
        public ?string $refundId = null,
        public ?string $message = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $id = $data['id'] ?? null;
        $status = $data['status'] ?? null;
        $refundId = $data['refundId'] ?? null;
        $message = $data['message'] ?? null;

        if (! is_string($id) || ! is_string($status)) {
            throw new ApiException('Yoco refund response missing required fields (id, status)', 0, $data);
        }

        if ($refundId !== null && ! is_string($refundId)) {
            throw new ApiException('Yoco refund refundId must be a string when present', 0, $data);
        }

        if ($message !== null && ! is_string($message)) {
            throw new ApiException('Yoco refund message must be a string when present', 0, $data);
        }

        return new self(
            id: $id,
            status: $status,
            refundId: $refundId,
            message: $message,
        );
    }
}
