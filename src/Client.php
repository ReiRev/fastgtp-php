<?php

declare(strict_types=1);

namespace FastGtp;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use InvalidArgumentException;
use JsonException;
use LogicException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * A client for a fastgtp server.
 *
 * Every method sends one request. Error responses, any status outside 2xx,
 * and a body that is not JSON are thrown as ProblemException. Errors from
 * the HTTP client itself, such as a refused connection, are thrown as they
 * are.
 */
final class Client
{
    private readonly string $baseUri;

    /**
     * @param array<string, string> $headers Sent with every request, for example
     *     the credentials of a proxy in front of the server.
     */
    public function __construct(
        string $baseUri,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly array $headers = [],
    ) {
        $this->baseUri = rtrim($baseUri, '/');
    }

    /**
     * Build a client on Guzzle, which must be installed.
     *
     * @param array<string, string> $headers
     */
    public static function create(string $baseUri, array $headers = [], float $timeoutSeconds = 30.0): self
    {
        if (!class_exists(GuzzleClient::class)) {
            throw new LogicException('Client::create() needs guzzlehttp/guzzle. Pass a PSR-18 client to the constructor instead.');
        }
        $factory = new HttpFactory();

        return new self($baseUri, new GuzzleClient(['timeout' => $timeoutSeconds]), $factory, $factory, $headers);
    }

    /** Start an engine. */
    public function createSession(): Session
    {
        return Session::fromArray($this->json('POST', '/sessions'));
    }

    public function getSession(string $sessionId): Session
    {
        return Session::fromArray($this->json('GET', $this->session($sessionId)));
    }

    /** Stop the engine. */
    public function deleteSession(string $sessionId): void
    {
        $this->send('DELETE', $this->session($sessionId));
    }

    public function getBoard(string $sessionId): Board
    {
        return Board::fromArray($this->json('GET', $this->session($sessionId) . '/board'));
    }

    /** Replace the board with an empty one. */
    public function setBoard(string $sessionId, int $size, float $komi): Board
    {
        $body = ['size' => $size, 'komi' => $komi];

        return Board::fromArray($this->json('PUT', $this->session($sessionId) . '/board', $body));
    }

    /**
     * Replace the board with a position from an SGF game record.
     *
     * @param int|null $moveCount Load only this many moves; 0 gives the setup position.
     */
    public function loadSgf(string $sessionId, string $sgf, ?int $moveCount = null): Board
    {
        $body = ['sgf' => $sgf];
        if ($moveCount !== null) {
            $body['move_count'] = $moveCount;
        }

        return Board::fromArray($this->json('PUT', $this->session($sessionId) . '/board', $body));
    }

    /** Play a move. An illegal move throws a ProblemException of type "illegal-move". */
    public function play(string $sessionId, Color $color, string $vertex): Move
    {
        $body = ['color' => $color->value, 'vertex' => $vertex];

        return Move::fromArray($this->json('POST', $this->session($sessionId) . '/moves', $body));
    }

    /** Let the engine choose and play a move. It may resign. */
    public function generateMove(string $sessionId, Color $color): Move
    {
        $body = ['color' => $color->value];

        return Move::fromArray($this->json('POST', $this->session($sessionId) . '/moves', $body));
    }

    /** Take back the last move. */
    public function undo(string $sessionId): void
    {
        $this->send('DELETE', $this->session($sessionId) . '/moves/last');
    }

    private function session(string $sessionId): string
    {
        return '/sessions/' . self::segment($sessionId);
    }

    private static function segment(string $id): string
    {
        // URL handling would drop "." and "..", and an empty id would leave
        // an empty segment, so each would reach another route.
        if ($id === '' || $id === '.' || $id === '..') {
            throw new InvalidArgumentException("Not a valid id: \"$id\"");
        }

        return rawurlencode($id);
    }

    /** @param array<string, mixed>|null $body */
    private function json(string $method, string $path, ?array $body = null): mixed
    {
        return $this->send($method, $path, $body)[0];
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{0: mixed, 1: ResponseInterface} The decoded body and the response.
     */
    private function send(string $method, string $path, ?array $body = null): array
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

    private static function retryAfter(ResponseInterface $response): ?int
    {
        $value = $response->getHeaderLine('Retry-After');

        return ctype_digit($value) ? (int) $value : null;
    }
}
