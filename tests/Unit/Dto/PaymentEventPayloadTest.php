<?php

declare(strict_types=1);

namespace Sonnenglas\Yoco\Tests\Unit\Dto;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sonnenglas\Yoco\Dto\PaymentEventPayload;
use Sonnenglas\Yoco\Dto\WebhookEvent;
use Sonnenglas\Yoco\Exceptions\ApiException;
use Sonnenglas\Yoco\Tests\Fixtures\FixtureLoader;

final class PaymentEventPayloadTest extends TestCase
{
    #[Test]
    public function it_parses_a_full_payment_succeeded_payload(): void
    {
        $event = FixtureLoader::asArray('event-payment-succeeded');
        $this->assertIsArray($event['payload']);
        /** @var array<string, mixed> $payload */
        $payload = $event['payload'];

        $parsed = PaymentEventPayload::fromArray($payload);

        $this->assertSame('p_LdcyhqMXcEsCsh4f72L8Vu76', $parsed->id);
        $this->assertSame(10000, $parsed->amount);
        $this->assertSame('ZAR', $parsed->currency);
        $this->assertSame('succeeded', $parsed->status);
        $this->assertSame('live', $parsed->mode);
        $this->assertNotNull($parsed->paymentMethodDetails);
        $this->assertSame('card', $parsed->paymentMethodDetails->type);
        $this->assertNotNull($parsed->paymentMethodDetails->card);
        $this->assertSame('424242XXXXXX4242', $parsed->paymentMethodDetails->card->maskedCard);
        $this->assertSame('visa', $parsed->paymentMethodDetails->card->scheme);
        $this->assertSame('ORD-100', $parsed->metadata['orderNumber']);
    }

    #[Test]
    public function webhook_event_returns_payment_payload_for_payment_types(): void
    {
        $event = new WebhookEvent(
            id: 'evt_1',
            type: 'payment.succeeded',
            createdDate: '2026-05-22T10:00:00Z',
            payload: ['id' => 'p_1', 'amount' => 200, 'currency' => 'ZAR', 'status' => 'succeeded'],
        );

        $payload = $event->asPaymentPayload();

        $this->assertNotNull($payload);
        $this->assertSame('p_1', $payload->id);
        $this->assertNull($event->asRefundPayload());
    }

    #[Test]
    public function webhook_event_returns_null_for_non_payment_types(): void
    {
        $event = new WebhookEvent(
            id: 'evt_1',
            type: 'refund.succeeded',
            createdDate: '2026-05-22T10:00:00Z',
            payload: [],
        );

        $this->assertNull($event->asPaymentPayload());
    }

    #[Test]
    public function it_rejects_payload_missing_required_fields(): void
    {
        $this->expectException(ApiException::class);
        PaymentEventPayload::fromArray(['id' => 'p_1']);
    }
}
