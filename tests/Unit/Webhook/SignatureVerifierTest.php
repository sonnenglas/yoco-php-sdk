<?php

declare(strict_types=1);

namespace Sonnenglas\Yoco\Tests\Unit\Webhook;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sonnenglas\Yoco\Dto\WebhookEvent;
use Sonnenglas\Yoco\Exceptions\SignatureVerificationException;
use Sonnenglas\Yoco\Webhook\SignatureVerifier;

final class SignatureVerifierTest extends TestCase
{
    private const RAW_SECRET_BYTES = 'this-is-a-32-byte-test-secret!!!';

    private string $secret;

    private string $rawBody;

    protected function setUp(): void
    {
        $this->secret = 'whsec_'.base64_encode(self::RAW_SECRET_BYTES);
        $this->rawBody = json_encode([
            'id' => 'evt_123',
            'type' => 'payment.succeeded',
            'createdDate' => '2026-05-20T10:00:00Z',
            'payload' => [
                'id' => 'p_abc',
                'amount' => 900,
                'currency' => 'ZAR',
                'status' => 'succeeded',
                'metadata' => ['orderNumber' => '12345'],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function it_verifies_a_valid_signature_and_returns_parsed_event(): void
    {
        $verifier = new SignatureVerifier($this->secret);
        $timestamp = (string) time();
        $headers = $this->makeHeaders('msg_test', $timestamp, $this->rawBody);

        $event = $verifier->verify($this->rawBody, $headers);

        $this->assertInstanceOf(WebhookEvent::class, $event);
        $this->assertSame('evt_123', $event->id);
        $this->assertSame('payment.succeeded', $event->type);
        $this->assertSame('2026-05-20T10:00:00Z', $event->createdDate);
        $this->assertSame('p_abc', $event->payload['id']);
    }

    #[Test]
    public function it_throws_when_signature_does_not_match(): void
    {
        $verifier = new SignatureVerifier($this->secret);
        $headers = [
            'webhook-id' => 'msg_test',
            'webhook-timestamp' => (string) time(),
            'webhook-signature' => 'v1,'.base64_encode('definitely-not-correct'),
        ];

        $this->expectException(SignatureVerificationException::class);
        $this->expectExceptionMessage('No matching signature found');
        $verifier->verify($this->rawBody, $headers);
    }

    #[Test]
    public function it_throws_when_timestamp_is_too_old(): void
    {
        $verifier = new SignatureVerifier($this->secret);
        $timestamp = (string) (time() - 300);
        $headers = $this->makeHeaders('msg_test', $timestamp, $this->rawBody);

        $this->expectException(SignatureVerificationException::class);
        $this->expectExceptionMessage('Webhook timestamp is outside the tolerance window');
        $verifier->verify($this->rawBody, $headers);
    }

    #[Test]
    public function it_throws_when_timestamp_is_too_far_in_future(): void
    {
        $verifier = new SignatureVerifier($this->secret);
        $timestamp = (string) (time() + 300);
        $headers = $this->makeHeaders('msg_test', $timestamp, $this->rawBody);

        $this->expectException(SignatureVerificationException::class);
        $verifier->verify($this->rawBody, $headers);
    }

    #[Test]
    public function it_accepts_signature_within_default_tolerance(): void
    {
        $verifier = new SignatureVerifier($this->secret);
        $timestamp = (string) (time() - 170);
        $headers = $this->makeHeaders('msg_test', $timestamp, $this->rawBody);

        $event = $verifier->verify($this->rawBody, $headers);
        $this->assertSame('evt_123', $event->id);
    }

    #[Test]
    public function it_accepts_one_of_multiple_signatures_for_secret_rotation(): void
    {
        $verifier = new SignatureVerifier($this->secret);
        $timestamp = (string) time();
        $correct = $this->computeSignature($this->secret, 'msg_test', $timestamp, $this->rawBody);
        $headers = [
            'webhook-id' => 'msg_test',
            'webhook-timestamp' => $timestamp,
            'webhook-signature' => 'v1,'.base64_encode('wrong-sig-from-old-secret').' v1,'.$correct,
        ];

        $event = $verifier->verify($this->rawBody, $headers);
        $this->assertSame('evt_123', $event->id);
    }

    #[Test]
    public function it_throws_when_webhook_id_header_is_missing(): void
    {
        $verifier = new SignatureVerifier($this->secret);
        $headers = [
            'webhook-timestamp' => (string) time(),
            'webhook-signature' => 'v1,sig',
        ];

        $this->expectException(SignatureVerificationException::class);
        $this->expectExceptionMessage('Missing required header: webhook-id');
        $verifier->verify($this->rawBody, $headers);
    }

    #[Test]
    public function it_throws_when_webhook_timestamp_header_is_missing(): void
    {
        $verifier = new SignatureVerifier($this->secret);
        $headers = [
            'webhook-id' => 'msg_test',
            'webhook-signature' => 'v1,sig',
        ];

        $this->expectException(SignatureVerificationException::class);
        $this->expectExceptionMessage('Missing required header: webhook-timestamp');
        $verifier->verify($this->rawBody, $headers);
    }

    #[Test]
    public function it_throws_when_webhook_signature_header_is_missing(): void
    {
        $verifier = new SignatureVerifier($this->secret);
        $headers = [
            'webhook-id' => 'msg_test',
            'webhook-timestamp' => (string) time(),
        ];

        $this->expectException(SignatureVerificationException::class);
        $this->expectExceptionMessage('Missing required header: webhook-signature');
        $verifier->verify($this->rawBody, $headers);
    }

    #[Test]
    public function it_handles_array_header_values_from_psr7(): void
    {
        $verifier = new SignatureVerifier($this->secret);
        $timestamp = (string) time();
        $sig = $this->computeSignature($this->secret, 'msg_test', $timestamp, $this->rawBody);
        $headers = [
            'webhook-id' => ['msg_test'],
            'webhook-timestamp' => [$timestamp],
            'webhook-signature' => ['v1,'.$sig],
        ];

        $event = $verifier->verify($this->rawBody, $headers);
        $this->assertSame('evt_123', $event->id);
    }

    #[Test]
    public function it_is_case_insensitive_for_header_names(): void
    {
        $verifier = new SignatureVerifier($this->secret);
        $timestamp = (string) time();
        $sig = $this->computeSignature($this->secret, 'msg_test', $timestamp, $this->rawBody);
        $headers = [
            'Webhook-Id' => 'msg_test',
            'Webhook-Timestamp' => $timestamp,
            'Webhook-Signature' => 'v1,'.$sig,
        ];

        $event = $verifier->verify($this->rawBody, $headers);
        $this->assertSame('evt_123', $event->id);
    }

    #[Test]
    public function it_throws_when_secret_has_no_whsec_prefix(): void
    {
        $verifier = new SignatureVerifier(base64_encode(self::RAW_SECRET_BYTES));
        $timestamp = (string) time();
        $headers = $this->makeHeaders('msg_test', $timestamp, $this->rawBody);

        $this->expectException(SignatureVerificationException::class);
        $this->expectExceptionMessage('Webhook secret must start with "whsec_"');
        $verifier->verify($this->rawBody, $headers);
    }

    #[Test]
    public function it_throws_when_body_is_not_valid_json(): void
    {
        $verifier = new SignatureVerifier($this->secret);
        $timestamp = (string) time();
        $body = 'not-json';
        $headers = $this->makeHeaders('msg_test', $timestamp, $body);

        $this->expectException(SignatureVerificationException::class);
        $this->expectExceptionMessage('Webhook body is not valid JSON');
        $verifier->verify($body, $headers);
    }

    #[Test]
    public function it_throws_when_body_lacks_required_event_fields(): void
    {
        $verifier = new SignatureVerifier($this->secret);
        $timestamp = (string) time();
        $body = json_encode(['onlySomeField' => true], JSON_THROW_ON_ERROR);
        $headers = $this->makeHeaders('msg_test', $timestamp, $body);

        $this->expectException(SignatureVerificationException::class);
        $this->expectExceptionMessage('Webhook event is missing required fields');
        $verifier->verify($body, $headers);
    }

    #[Test]
    public function it_respects_custom_tolerance_seconds(): void
    {
        $verifier = new SignatureVerifier($this->secret);
        $timestamp = (string) (time() - 60);
        $headers = $this->makeHeaders('msg_test', $timestamp, $this->rawBody);

        $this->expectException(SignatureVerificationException::class);
        $verifier->verify($this->rawBody, $headers, toleranceSeconds: 30);
    }

    #[Test]
    public function it_rejects_negative_tolerance_seconds(): void
    {
        $verifier = new SignatureVerifier($this->secret);
        $headers = $this->makeHeaders('msg_test', (string) time(), $this->rawBody);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('toleranceSeconds must be between 0 and 3600');
        $verifier->verify($this->rawBody, $headers, toleranceSeconds: -1);
    }

    #[Test]
    public function it_rejects_tolerance_seconds_above_one_hour(): void
    {
        $verifier = new SignatureVerifier($this->secret);
        $headers = $this->makeHeaders('msg_test', (string) time(), $this->rawBody);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('toleranceSeconds must be between 0 and 3600');
        $verifier->verify($this->rawBody, $headers, toleranceSeconds: 3601);
    }

    #[Test]
    public function it_accepts_tolerance_seconds_at_upper_boundary(): void
    {
        $verifier = new SignatureVerifier($this->secret);
        $headers = $this->makeHeaders('msg_test', (string) time(), $this->rawBody);

        $event = $verifier->verify($this->rawBody, $headers, toleranceSeconds: 3600);
        $this->assertSame('evt_123', $event->id);
    }

    /**
     * Deterministic regression test — uses a hardcoded (secret, id, timestamp,
     * body, expected_sig) vector. If anybody ever changes the signature
     * algorithm (e.g. swaps base64 for hex), this test fails even though tests
     * that compute the signature with the same algorithm would still pass.
     */
    #[Test]
    public function it_verifies_known_signature_vector(): void
    {
        $secret = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';
        $msgId = 'msg_p5jXN8AQM9LWM0D4loKWxJek';
        $timestamp = '1614265330';
        $body = '{"id":"evt_known","type":"payment.succeeded","createdDate":"2021-02-25T15:42:10Z","payload":{"id":"p_known","amount":100,"currency":"ZAR","status":"succeeded"}}';
        $expectedSig = 'fBj8bFkvVMJhSDUf33On6wmYBssC/rR7wVJLpUhsxxg=';

        // Pin the clock to the timestamp's epoch so it falls inside tolerance.
        $verifier = new SignatureVerifier($secret, fn (): int => 1614265330);

        $event = $verifier->verify($body, [
            'webhook-id' => $msgId,
            'webhook-timestamp' => $timestamp,
            'webhook-signature' => 'v1,'.$expectedSig,
        ]);

        $this->assertSame('evt_known', $event->id);
        $this->assertSame('payment.succeeded', $event->type);
    }

    #[Test]
    public function it_accepts_v1_signature_alongside_unknown_prefixes(): void
    {
        $verifier = new SignatureVerifier($this->secret);
        $timestamp = (string) time();
        $sig = $this->computeSignature($this->secret, 'msg_test', $timestamp, $this->rawBody);

        $headers = [
            'webhook-id' => 'msg_test',
            'webhook-timestamp' => $timestamp,
            'webhook-signature' => 'v0,legacy v1,'.$sig.' v2,future',
        ];

        $event = $verifier->verify($this->rawBody, $headers);
        $this->assertSame('evt_123', $event->id);
    }

    #[Test]
    public function it_throws_with_explicit_message_when_only_unknown_prefixes_present(): void
    {
        $verifier = new SignatureVerifier($this->secret);
        $headers = [
            'webhook-id' => 'msg_test',
            'webhook-timestamp' => (string) time(),
            'webhook-signature' => 'v0,legacy v2,future',
        ];

        $this->expectException(SignatureVerificationException::class);
        $this->expectExceptionMessage('No v1 signature present');
        $verifier->verify($this->rawBody, $headers);
    }

    #[Test]
    public function it_rejects_known_vector_with_corrupted_signature(): void
    {
        $secret = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';
        $msgId = 'msg_p5jXN8AQM9LWM0D4loKWxJek';
        $timestamp = '1614265330';
        $body = '{"id":"evt_known","type":"payment.succeeded","createdDate":"2021-02-25T15:42:10Z","payload":{"id":"p_known","amount":100,"currency":"ZAR","status":"succeeded"}}';
        // One character flipped at the end.
        $corruptedSig = 'fBj8bFkvVMJhSDUf33On6wmYBssC/rR7wVJLpUhsxxx=';

        $verifier = new SignatureVerifier($secret, fn (): int => 1614265330);

        $this->expectException(SignatureVerificationException::class);
        $verifier->verify($body, [
            'webhook-id' => $msgId,
            'webhook-timestamp' => $timestamp,
            'webhook-signature' => 'v1,'.$corruptedSig,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function makeHeaders(string $id, string $timestamp, string $body): array
    {
        $sig = $this->computeSignature($this->secret, $id, $timestamp, $body);

        return [
            'webhook-id' => $id,
            'webhook-timestamp' => $timestamp,
            'webhook-signature' => 'v1,'.$sig,
        ];
    }

    private function computeSignature(string $secret, string $id, string $timestamp, string $body): string
    {
        $rawSecret = base64_decode(substr($secret, strlen('whsec_')), true);
        $this->assertIsString($rawSecret);

        $signedPayload = "{$id}.{$timestamp}.{$body}";

        return base64_encode(hash_hmac('sha256', $signedPayload, $rawSecret, true));
    }
}
