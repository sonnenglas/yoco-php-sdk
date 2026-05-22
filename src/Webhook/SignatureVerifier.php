<?php

declare(strict_types=1);

namespace Sonnenglas\Yoco\Webhook;

use InvalidArgumentException;
use JsonException;
use Sonnenglas\Yoco\Dto\WebhookEvent;
use Sonnenglas\Yoco\Exceptions\SignatureVerificationException;

final class SignatureVerifier
{
    public const DEFAULT_TOLERANCE_SECONDS = 180;

    public const MAX_TOLERANCE_SECONDS = 3600;

    /** Maximum allowed raw webhook body size (1 MiB). */
    public const MAX_BODY_BYTES = 1_048_576;

    /** Defensive limit for nested JSON depth. */
    private const MAX_JSON_DEPTH = 64;

    private const SECRET_PREFIX = 'whsec_';

    /** @var (callable(): int)|null */
    private $clock;

    /**
     * @param (callable(): int)|null $clock Optional override for the current
     *                                      timestamp source. Useful for tests
     *                                      and for verifying historical events.
     */
    public function __construct(
        private readonly string $secret,
        ?callable $clock = null,
    ) {
        $this->clock = $clock;
    }

    /**
     * @param array<string, string|list<string>> $headers
     *
     * @throws InvalidArgumentException
     * @throws SignatureVerificationException
     */
    public function verify(
        string $rawBody,
        array $headers,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
    ): WebhookEvent {
        if ($toleranceSeconds < 0 || $toleranceSeconds > self::MAX_TOLERANCE_SECONDS) {
            throw new InvalidArgumentException(
                'toleranceSeconds must be between 0 and '.self::MAX_TOLERANCE_SECONDS,
            );
        }

        if (strlen($rawBody) > self::MAX_BODY_BYTES) {
            throw new SignatureVerificationException(
                'Webhook body exceeds the maximum allowed size of '.self::MAX_BODY_BYTES.' bytes',
            );
        }

        $normalised = $this->normaliseHeaders($headers);

        $id = $this->requireHeader($normalised, 'webhook-id');
        $timestamp = $this->requireHeader($normalised, 'webhook-timestamp');
        $signatureHeader = $this->requireHeader($normalised, 'webhook-signature');

        $this->assertTimestampWithinTolerance($timestamp, $toleranceSeconds);

        $rawSecret = $this->decodeSecret();
        $expected = $this->computeSignature($rawSecret, $id, $timestamp, $rawBody);

        $this->assertSignatureMatches($signatureHeader, $expected);

        return $this->parseEvent($rawBody);
    }

    /**
     * @param array<string, string|list<string>> $headers
     *
     * @return array<string, string>
     */
    private function normaliseHeaders(array $headers): array
    {
        $normalised = [];

        foreach ($headers as $name => $value) {
            $key = strtolower($name);

            if (is_array($value)) {
                $first = $value[0] ?? null;
                if (is_string($first)) {
                    $normalised[$key] = $first;
                }
                continue;
            }

            $normalised[$key] = $value;
        }

        return $normalised;
    }

    /**
     * @param array<string, string> $headers
     */
    private function requireHeader(array $headers, string $name): string
    {
        $value = $headers[$name] ?? null;

        if (! is_string($value) || $value === '') {
            throw new SignatureVerificationException("Missing required header: {$name}");
        }

        return $value;
    }

    private function assertTimestampWithinTolerance(string $timestamp, int $toleranceSeconds): void
    {
        if (! ctype_digit($timestamp)) {
            throw new SignatureVerificationException('Webhook timestamp is not a valid integer');
        }

        $now = $this->clock !== null ? ($this->clock)() : time();
        $delta = abs($now - (int) $timestamp);

        if ($delta > $toleranceSeconds) {
            throw new SignatureVerificationException(
                'Webhook timestamp is outside the tolerance window',
            );
        }
    }

    private function decodeSecret(): string
    {
        if (! str_starts_with($this->secret, self::SECRET_PREFIX)) {
            throw new SignatureVerificationException('Webhook secret must start with "whsec_"');
        }

        $decoded = base64_decode(substr($this->secret, strlen(self::SECRET_PREFIX)), true);

        if (! is_string($decoded) || $decoded === '') {
            throw new SignatureVerificationException('Webhook secret has invalid base64 encoding');
        }

        return $decoded;
    }

    private function computeSignature(string $rawSecret, string $id, string $timestamp, string $body): string
    {
        $signedPayload = $id.'.'.$timestamp.'.'.$body;

        return base64_encode(hash_hmac('sha256', $signedPayload, $rawSecret, true));
    }

    /**
     * Standard Webhooks defines the `webhook-signature` header as a
     * space-separated list of `<version>,<sig>` tokens. Today only `v1` is
     * defined; any other prefix (legacy `v0,`, future `v2,`, custom schemes)
     * is silently skipped so that callers running mixed-version webhook
     * deliverers do not fail outright. If NONE of the entries match the
     * expected `v1` signature, the verification fails.
     */
    private function assertSignatureMatches(string $headerValue, string $expected): void
    {
        $sawV1 = false;

        foreach (explode(' ', $headerValue) as $entry) {
            $entry = trim($entry);

            if ($entry === '' || ! str_starts_with($entry, 'v1,')) {
                continue;
            }

            $sawV1 = true;
            $candidate = substr($entry, 3);

            if (hash_equals($expected, $candidate)) {
                return;
            }
        }

        throw new SignatureVerificationException(
            $sawV1
                ? 'No matching signature found'
                : 'No v1 signature present in webhook-signature header — only unsupported schemes (e.g. v0, v2) were provided',
        );
    }

    private function parseEvent(string $rawBody): WebhookEvent
    {
        try {
            $decoded = json_decode($rawBody, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new SignatureVerificationException('Webhook body is not valid JSON', 0, $e);
        }

        if (! is_array($decoded)) {
            throw new SignatureVerificationException('Webhook body is not valid JSON');
        }

        $id = $decoded['id'] ?? null;
        $type = $decoded['type'] ?? null;
        $createdDate = $decoded['createdDate'] ?? null;
        $payload = $decoded['payload'] ?? null;

        if (! is_string($id) || ! is_string($type) || ! is_string($createdDate) || ! is_array($payload)) {
            throw new SignatureVerificationException('Webhook event is missing required fields');
        }

        /** @var array<string, mixed> $payload */
        return new WebhookEvent(
            id: $id,
            type: $type,
            createdDate: $createdDate,
            payload: $payload,
        );
    }
}
