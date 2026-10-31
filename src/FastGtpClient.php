<?php

declare(strict_types=1);

namespace FastGtp;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface;

/**
 * A client for a fastgtp server, and where Session and KataGo handles come from.
 *
 * A failed request throws FastGtpException. An empty, "." or ".." id throws
 * InvalidArgumentException. The constructor throws php-http/discovery's
 * NotFoundException when no PSR-18 client is given or installed, or when no
 * PSR-17 factory is installed. Guzzle provides both.
 */
final class FastGtpClient
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
        // The PSR-17 factories only build requests, and every HTTP client
        // package ships them, so they are always found rather than passed.
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
        return $this->transporter->read(
            'POST',
            '/sessions',
            null,
            fn(array $data) => new Session($this->transporter, (new Fields($data))->id('session_id')),
        );
    }

    /** @return list<KataGo> The KataGos the server runs. */
    public function katagos(): array
    {
        return $this->transporter->read('GET', '/katago', null, fn(array $data) => array_map(
            fn(array $item) => new KataGo($this->transporter, (new Fields($item))->id('id')),
            (new Fields($data))->objects('items'),
        ));
    }

    /** The KataGo with this name, such as "default". Sends nothing. */
    public function katago(string $name): KataGo
    {
        return new KataGo($this->transporter, $name);
    }

    /** The session with this id, for example one kept from an earlier request. Sends nothing. */
    public function session(string $id): Session
    {
        return new Session($this->transporter, $id);
    }
}
