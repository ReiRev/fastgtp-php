<?php

declare(strict_types=1);

namespace FastGtp;

use InvalidArgumentException;
use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Sends requests to a fastgtp server and reads their responses.
 *
 * @internal
 */
final class HttpTransporter
{
    private readonly string $baseUri;

    /** @param array<string, string> $headers */
    public function __construct(
        string $baseUri,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly array $headers = [],
    ) {
        $this->baseUri = rtrim($baseUri, '/');
    }

    /** An id as one path segment. */
    public static function segment(string $id): string
    {
        // URL handling would drop "." and "..", and an empty id would leave
        // an empty segment, so each would reach another route.
        if ($id === '' || $id === '.' || $id === '..') {
            throw new InvalidArgumentException("Not a valid id: \"$id\"");
        }

        return rawurlencode($id);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    public function json(string $method, string $path, ?array $body = null): array
    {
        return $this->jsonAndResponse($method, $path, $body)[0];
    }

    /**
     * Send a request whose response must have a JSON object as its body.
     *
     * @param array<string, mixed>|null $body
     * @return array{0: array<string, mixed>, 1: ResponseInterface}
     */
    public function jsonAndResponse(string $method, string $path, ?array $body = null): array
    {
        [$decoded, $response, $text] = $this->send($method, $path, $body);
        // Decoded to arrays, "{}" and "[]" look the same, so look at the text.
        if (!is_array($decoded) || !str_starts_with(ltrim($text), '{')) {
            throw new FastGtpException('The response is not a JSON object', $response->getStatusCode());
        }

        return [$decoded, $response];
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{0: mixed, 1: ResponseInterface, 2: string} The decoded body, the
     *     response, and the body as text, since a body stream may be readable only once.
     */
    public function send(string $method, string $path, ?array $body = null): array
    {
        $request = $this->requests->createRequest($method, $this->baseUri . $path)
            ->withHeader('Accept', 'application/json, application/problem+json');
        foreach ($this->headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body !== null) {
            // Without JSON_PRESERVE_ZERO_FRACTION a komi of 7.0 would be sent as 7.
            $json = json_encode($body, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES);
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streams->createStream($json));
        }

        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            // So that callers catch one exception type for every failure.
            throw new FastGtpException($e->getMessage(), previous: $e);
        }
        $status = $response->getStatusCode();
        $text = (string) $response->getBody();
        // A PSR-18 client does not follow redirects. A proxy that sends a
        // client without credentials to its login page answers 3xx, and
        // that must not pass for success.
        if ($status < 200 || $status >= 300) {
            throw $this->problem($response, $text);
        }

        try {
            $decoded = $text === '' ? null : json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new FastGtpException('The response is not JSON', $status);
        }

        return [$decoded, $response, $text];
    }

    public static function retryAfter(ResponseInterface $response): ?int
    {
        $value = $response->getHeaderLine('Retry-After');

        return ctype_digit($value) ? (int) $value : null;
    }

    private function problem(ResponseInterface $response, string $text): FastGtpException
    {
        $status = $response->getStatusCode();
        try {
            $problem = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $problem = null;
        }
        // A proxy in front of fastgtp can answer with an HTML page instead.
        if (!is_array($problem)) {
            $problem = [];
        }

        // Another server's error body, such as a list in "detail", must not
        // be cast to a string, which would raise a warning.
        $string = static fn(string $key): ?string => is_string($problem[$key] ?? null) ? $problem[$key] : null;
        $title = $string('title') ?? $response->getReasonPhrase();

        return new FastGtpException(
            $title !== '' ? $title : "HTTP $status",
            $status,
            $string('type') ?? 'about:blank',
            $string('detail'),
            $string('field'),
            self::retryAfter($response),
        );
    }
}
