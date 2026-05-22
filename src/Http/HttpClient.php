<?php

declare(strict_types=1);

namespace Sonnenglas\Yoco\Http;

use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Sonnenglas\Yoco\Exceptions\ApiException;
use Sonnenglas\Yoco\Exceptions\AuthenticationException;
use Sonnenglas\Yoco\Exceptions\IdempotencyConflictException;
use Sonnenglas\Yoco\Exceptions\IdempotencyMismatchException;
use Sonnenglas\Yoco\Exceptions\RateLimitException;
use Sonnenglas\Yoco\Exceptions\ValidationException;

final class HttpClient
{
    /** Maximum allowed response body size (1 MiB). Yoco API responses are
     *  small JSON documents; anything bigger is almost certainly a bug or a
     *  misbehaving proxy and must not be parsed into memory. */
    public const MAX_RESPONSE_BYTES = 1_048_576;

    /** Defensive limit for nested JSON depth. */
    private const MAX_JSON_DEPTH = 64;

    private readonly string $baseUri;

    public function __construct(
        private readonly string $secretKey,
        string $baseUri,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly string $userAgent = 'sonnenglas-yoco-php-sdk',
    ) {
        $this->baseUri = rtrim($baseUri, '/');
    }

    /**
     * @param array<string, mixed>|null $body         Pass `null` to send a POST
     *                                                with no body and no
     *                                                Content-Type — required
     *                                                for Yoco endpoints that
     *                                                reject empty JSON arrays
     *                                                (e.g. full refund).
     * @param array<string, string>     $extraHeaders
     *
     * @return array<string, mixed>
     */
    public function post(string $path, ?array $body, array $extraHeaders = []): array
    {
        return $this->request('POST', $path, $body, $extraHeaders);
    }

    /**
     * @param array<string, string> $extraHeaders
     *
     * @return array<string, mixed>
     */
    public function get(string $path, array $extraHeaders = []): array
    {
        return $this->request('GET', $path, null, $extraHeaders);
    }

    /**
     * @param array<string, string> $extraHeaders
     *
     * @return array<string, mixed>
     */
    public function delete(string $path, array $extraHeaders = []): array
    {
        return $this->request('DELETE', $path, null, $extraHeaders);
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $extraHeaders
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body, array $extraHeaders = []): array
    {
        $request = $this->buildRequest($method, $path, $body, $extraHeaders);

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            // Do NOT include $e->getMessage() — some PSR-18 implementations
            // embed the full outgoing request (including Authorization header
            // with the API secret) in their message. Keep the original as
            // previous() so callers who know what they're doing can opt-in.
            throw new ApiException(
                sprintf('HTTP transport error (%s)', $e::class),
                0,
                [],
                $e,
            );
        }

        return $this->decodeResponse($response);
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $extraHeaders
     */
    private function buildRequest(string $method, string $path, ?array $body, array $extraHeaders = []): RequestInterface
    {
        $uri = $this->baseUri.'/'.ltrim($path, '/');

        $request = $this->requestFactory->createRequest($method, $uri)
            ->withHeader('Authorization', 'Bearer '.$this->secretKey)
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', $this->userAgent);

        foreach ($extraHeaders as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            try {
                $encoded = json_encode($body, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new ApiException('Failed to encode request body: '.$e->getMessage(), 0, [], $e);
            }

            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($encoded));
        }

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(ResponseInterface $response): array
    {
        $statusCode = $response->getStatusCode();
        $rawBody = (string) $response->getBody();

        if (strlen($rawBody) > self::MAX_RESPONSE_BYTES) {
            throw new ApiException(
                'Response body exceeds the maximum allowed size of '.self::MAX_RESPONSE_BYTES.' bytes',
                $statusCode,
            );
        }

        $payload = $this->parseJson($rawBody, $statusCode);

        if ($statusCode >= 200 && $statusCode < 300) {
            return $payload;
        }

        $message = $this->extractMessage($payload, $statusCode);

        // Status code mapping reflects Yoco's Checkout API:
        //   400 → validation error (invalid request body / parameters)
        //   401 → defensive: Checkout API does not return 401, but proxies/CDNs may
        //   403 → missing or invalid API key
        //   409 → idempotency conflict (in-flight request with same key)
        //   422 → idempotency mismatch (replayed key with different body)
        //   429 → defensive: Checkout API does not document 429, but other Yoco
        //         APIs and proxies may rate limit
        //   any other 4xx/5xx → generic ApiException
        throw match (true) {
            $statusCode === 400 => new ValidationException($message, $statusCode, $payload),
            $statusCode === 401 => new AuthenticationException($message, $statusCode, $payload),
            $statusCode === 403 => new AuthenticationException($message, $statusCode, $payload),
            $statusCode === 409 => new IdempotencyConflictException($message, $statusCode, $payload),
            $statusCode === 422 => new IdempotencyMismatchException($message, $statusCode, $payload),
            $statusCode === 429 => new RateLimitException(
                $message,
                $statusCode,
                $payload,
                null,
                self::parseRetryAfter($response->getHeaderLine('Retry-After')),
            ),
            default => new ApiException($message, $statusCode, $payload),
        };
    }

    /**
     * Parse a `Retry-After` response header into a number of seconds.
     *
     * Per RFC 7231 §7.1.3, the value can be either:
     *   - a non-negative integer (seconds) — most common
     *   - an HTTP-date (e.g. "Wed, 21 Oct 2026 07:28:00 GMT")
     *
     * Returns null when the header is missing/garbage, 0 if the HTTP-date is
     * already in the past.
     */
    private static function parseRetryAfter(string $headerValue): ?int
    {
        if ($headerValue === '') {
            return null;
        }

        if (ctype_digit($headerValue)) {
            return (int) $headerValue;
        }

        $timestamp = strtotime($headerValue);
        if ($timestamp === false) {
            return null;
        }

        return max(0, $timestamp - time());
    }

    /**
     * @return array<string, mixed>
     */
    private function parseJson(string $rawBody, int $statusCode): array
    {
        if ($rawBody === '') {
            return [];
        }

        try {
            $decoded = json_decode($rawBody, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ApiException('Invalid JSON response from Yoco API', $statusCode, [], $e);
        }

        if (! is_array($decoded)) {
            throw new ApiException('Invalid JSON response from Yoco API', $statusCode);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Yoco surfaces error messages under a few different keys depending on the
     * endpoint:
     *   - `message` — used by most Checkout API errors
     *   - `description` — used by refund decline responses, idempotency errors
     *   - `error.{message,description}` — nested style
     *   - `errors[0].{message,description}` — JSON:API style
     *
     * @param array<string, mixed> $payload
     */
    private function extractMessage(array $payload, int $statusCode): string
    {
        foreach (['message', 'description', 'error_description'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $error = $payload['error'] ?? null;
        if (is_array($error)) {
            foreach (['message', 'description'] as $key) {
                $value = $error[$key] ?? null;
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        $errors = $payload['errors'] ?? null;
        if (is_array($errors) && isset($errors[0]) && is_array($errors[0])) {
            foreach (['message', 'description'] as $key) {
                $value = $errors[0][$key] ?? null;
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        return "HTTP {$statusCode} from Yoco API";
    }
}
