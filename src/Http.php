<?php

declare(strict_types=1);

namespace FastGtp;

use Closure;
use InvalidArgumentException;
use JsonException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Sends the requests of Client and of the handles it gives out.
 *
 * @internal
 */
final class Http
{
    private readonly string $baseUri;

    /** @var Closure(float): void */
    private readonly Closure $sleep;

    /**
     * @param array<string, string> $headers
     * @param (Closure(float): void)|null $sleep
     */
    public function __construct(
        string $baseUri,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly array $headers = [],
        ?Closure $sleep = null,
    ) {
        $this->baseUri = rtrim($baseUri, '/');
        $this->sleep = $sleep ?? static fn(float $seconds) => usleep((int) ($seconds * 1_000_000));
    }

    public function sleep(float $seconds): void
    {
        ($this->sleep)($seconds);
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
        [$decoded, $response] = $this->send($method, $path, $body);
        // "{}" decodes to [], so only a non-empty list is not an object.
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new ProblemException($response->getStatusCode(), 'about:blank', 'The response is not a JSON object');
        }

        return [$decoded, $response];
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{0: mixed, 1: ResponseInterface} The decoded body and the response.
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

        $response = $this->http->sendRequest($request);
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
            throw new ProblemException($status, 'about:blank', 'The response is not JSON');
        }

        return [$decoded, $response];
    }

    public static function retryAfter(ResponseInterface $response): ?int
    {
        $value = $response->getHeaderLine('Retry-After');

        return ctype_digit($value) ? (int) $value : null;
    }

    private function problem(ResponseInterface $response, string $text): ProblemException
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

        return new ProblemException(
            $status,
            (string) ($problem['type'] ?? 'about:blank'),
            (string) ($problem['title'] ?? $response->getReasonPhrase()),
            isset($problem['detail']) ? (string) $problem['detail'] : null,
            isset($problem['field']) ? (string) $problem['field'] : null,
            self::retryAfter($response),
        );
    }
}
