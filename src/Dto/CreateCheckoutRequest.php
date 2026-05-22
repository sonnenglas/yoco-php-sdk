<?php

declare(strict_types=1);

namespace Sonnenglas\Yoco\Dto;

use InvalidArgumentException;

final readonly class CreateCheckoutRequest
{
    public const MIN_AMOUNT_CENTS = 200;

    public const SUPPORTED_CURRENCY = 'ZAR';

    /**
     * @param array<string, scalar> $metadata
     * @param list<LineItem>        $lineItems
     */
    public function __construct(
        public int $amount,
        public string $currency,
        public string $successUrl,
        public string $cancelUrl,
        public ?string $failureUrl = null,
        public array $metadata = [],
        public array $lineItems = [],
        public ?int $totalDiscount = null,
        public ?int $totalTaxAmount = null,
        public ?int $subtotalAmount = null,
        public ?string $externalId = null,
    ) {
        if ($amount < self::MIN_AMOUNT_CENTS) {
            throw new InvalidArgumentException(
                'amount must be at least '.self::MIN_AMOUNT_CENTS.' cents (R2.00)',
            );
        }

        if ($currency !== self::SUPPORTED_CURRENCY) {
            throw new InvalidArgumentException(
                'Yoco only supports '.self::SUPPORTED_CURRENCY.' currency',
            );
        }

        if ($successUrl === '') {
            throw new InvalidArgumentException('successUrl must not be empty');
        }

        if ($cancelUrl === '') {
            throw new InvalidArgumentException('cancelUrl must not be empty');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'amount' => $this->amount,
            'currency' => $this->currency,
            'successUrl' => $this->successUrl,
            'cancelUrl' => $this->cancelUrl,
        ];

        if ($this->failureUrl !== null) {
            $data['failureUrl'] = $this->failureUrl;
        }

        if ($this->metadata !== []) {
            $data['metadata'] = $this->metadata;
        }

        if ($this->lineItems !== []) {
            $data['lineItems'] = array_map(
                static fn (LineItem $item): array => $item->toArray(),
                $this->lineItems,
            );
        }

        if ($this->totalDiscount !== null) {
            $data['totalDiscount'] = $this->totalDiscount;
        }

        if ($this->totalTaxAmount !== null) {
            $data['totalTaxAmount'] = $this->totalTaxAmount;
        }

        if ($this->subtotalAmount !== null) {
            $data['subtotalAmount'] = $this->subtotalAmount;
        }

        if ($this->externalId !== null) {
            $data['externalId'] = $this->externalId;
        }

        return $data;
    }
}
