<?php

declare(strict_types=1);

namespace FastGtp;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface;
use UnexpectedValueException;

/**
 * A client for a fastgtp server, and where Session handles come from.
 *
 * A failed request throws FastGtpException. An empty, "." or ".." id throws
 * InvalidArgumentException. Without a PSR-18 client, given or installed, the
 * constructor throws php-http/discovery's NotFoundException.
 */
final class Client
{
    private readonly HttpTransporter $transporter;

    /**
     * @param ClientInterface|null $http Any PSR-18 client, for example Guzzle with a timeout.
     *     Without one, an installed client is found.
     * @param array<string, string> $headers Sent with every request, for example
     *     the credentials of a proxy in front of the server.
     */
    public function __construct(string $baseUri, ?ClientInterface $http = null, array $headers = [])
    {
        // The PSR-17 factories only build requests, so they are always found.
        $this->transporter = new HttpTransporter(
            $baseUri,
            $http ?? Psr18ClientDiscovery::find(),
            Psr17FactoryDiscovery::findRequestFactory(),
            Psr17FactoryDiscovery::findStreamFactory(),
            $headers,
        );
    }

    /** Start an engine. */
    public function createSession(): Session
    {
        return $this->transporter->read('POST', '/sessions', null, function (array $data): Session {
            $id = (new Fields($data))->string('session_id');
            if (in_array($id, ['', '.', '..'], true)) {
                throw new UnexpectedValueException('The response has no valid "session_id"');
            }

            return new Session($this->transporter, $id);
        });
    }

    /** The session with this id, for example one kept from an earlier request. Sends nothing. */
    public function session(string $id): Session
    {
        return new Session($this->transporter, $id);
    }
}
