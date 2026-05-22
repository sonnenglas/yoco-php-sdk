<?php

declare(strict_types=1);

namespace Sonnenglas\Yoco\Dto;

use Sonnenglas\Yoco\Exceptions\ApiException;

/**
 * Card details for a `card` payment method on a webhook payload.
 */
final readonly class CardDetails
{
    public function __construct(
        public int $expiryMonth,
        public int $expiryYear,
        public string $maskedCard,
        public string $scheme,
        public ?string $cardHolder = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $expiryMonth = $data['expiryMonth'] ?? null;
        $expiryYear = $data['expiryYear'] ?? null;
        $maskedCard = $data['maskedCard'] ?? null;
        $scheme = $data['scheme'] ?? null;
        $cardHolder = $data['cardHolder'] ?? null;

        if (! is_int($expiryMonth) || ! is_int($expiryYear) || ! is_string($maskedCard) || ! is_string($scheme)) {
            throw new ApiException('Yoco card details missing required fields', 0, $data);
        }

        if ($cardHolder !== null && ! is_string($cardHolder)) {
            throw new ApiException('Yoco card cardHolder must be a string when present', 0, $data);
        }

        return new self(
            expiryMonth: $expiryMonth,
            expiryYear: $expiryYear,
            maskedCard: $maskedCard,
            scheme: $scheme,
            cardHolder: $cardHolder,
        );
    }
}
