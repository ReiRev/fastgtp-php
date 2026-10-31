<?php

declare(strict_types=1);

namespace FastGtp;

use Closure;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use LogicException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * A client for a fastgtp server, and where Session handles come from.
 *
 * Error responses, any status outside 2xx, and a 2xx body that is not the
 * JSON expected are thrown as ProblemException. Such a body keeps its status,
 * so a ProblemException can carry a 2xx status. Errors from the HTTP client
 * itself, such as a refused connection, are thrown as they are. An empty,
 * "." or ".." id throws InvalidArgumentException.
 */
final class Client
{
    private readonly Http $http;

    /**
     * @param array<string, string> $headers Sent with every request, for example
     *     the credentials of a proxy in front of the server.
     * @param (Closure(float): void)|null $sleep Waits for the given seconds.
     */
    public function __construct(
        string $baseUri,
        ClientInterface $http,
        RequestFactoryInterface $requests,
        StreamFactoryInterface $streams,
        array $headers = [],
        ?Closure $sleep = null,
    ) {
        $this->http = new Http($baseUri, $http, $requests, $streams, $headers, $sleep);
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
        $data = $this->http->json('POST', '/sessions');

        return new Session($this->http, (string) $data['session_id']);
    }

    /** The session with this id, for example one kept from an earlier request. Sends nothing. */
    public function session(string $id): Session
    {
        return new Session($this->http, $id);
    }
}
