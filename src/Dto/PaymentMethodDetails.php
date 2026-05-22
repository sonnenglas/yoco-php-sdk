<?php

declare(strict_types=1);

namespace Sonnenglas\Yoco\Dto;

use Sonnenglas\Yoco\Exceptions\ApiException;

final readonly class PaymentMethodDetails
{
    public function __construct(
        public string $type,
        public ?CardDetails $card = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $type = $data['type'] ?? null;

        if (! is_string($type)) {
            throw new ApiException('Yoco paymentMethodDetails.type must be a string', 0, $data);
        }

        $cardData = $data['card'] ?? null;
        $card = null;
        if ($cardData !== null) {
            if (! is_array($cardData)) {
                throw new ApiException('Yoco paymentMethodDetails.card must be an object when present', 0, $data);
            }
            /** @var array<string, mixed> $cardData */
            $card = CardDetails::fromArray($cardData);
        }

        return new self(type: $type, card: $card);
    }
}
