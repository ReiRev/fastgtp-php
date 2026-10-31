<?php

declare(strict_types=1);

namespace FastGtp;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * A client for a fastgtp server, and where Session handles come from.
 *
 * A failed request throws FastGtpException. An empty, "." or ".." id throws
 * InvalidArgumentException.
 */
final class Client
{
    private readonly HttpTransporter $transporter;

    /**
     * @param ClientInterface|null $http Any PSR-18 client, for example Guzzle with a timeout.
     *     Without one, an installed client is found.
     * @param array<string, string> $headers Sent with every request, for example
     *     the credentials of a proxy in front of the server.
     * @param RequestFactoryInterface|null $requests Found when not given, like $http.
     * @param StreamFactoryInterface|null $streams Found when not given, like $http.
     */
    public function __construct(
        string $baseUri,
        ?ClientInterface $http = null,
        array $headers = [],
        ?RequestFactoryInterface $requests = null,
        ?StreamFactoryInterface $streams = null,
    ) {
        $this->transporter = new HttpTransporter(
            $baseUri,
            $http ?? Psr18ClientDiscovery::find(),
            $requests ?? Psr17FactoryDiscovery::findRequestFactory(),
            $streams ?? Psr17FactoryDiscovery::findStreamFactory(),
            $headers,
        );
    }

    /** Start an engine. */
    public function createSession(): Session
    {
        $data = $this->transporter->json('POST', '/sessions');

        return new Session($this->transporter, (string) $data['session_id']);
    }

    /** The session with this id, for example one kept from an earlier request. Sends nothing. */
    public function session(string $id): Session
    {
        return new Session($this->transporter, $id);
    }
}
