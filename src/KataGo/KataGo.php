<?php

declare(strict_types=1);

namespace FastGtp\KataGo;

use FastGtp\Fields;
use FastGtp\HttpTransporter;

/**
 * One KataGo analysis engine on the server. Each method sends one request.
 *
 * The name says which config and model it runs, and the server keeps that
 * fixed, so a name kept from an earlier request still means the same KataGo.
 */
final class KataGo
{
    private readonly string $path;

    /** @internal Use FastGtpClient::katago() or FastGtpClient::katagos(). */
    public function __construct(
        private readonly HttpTransporter $transporter,
        public readonly string $name,
    ) {
        $this->path = '/katago/' . HttpTransporter::segment($name);
    }

    public function version(): Version
    {
        return $this->transporter->read('GET', $this->path . '/version', null, Version::fromArray(...));
    }

    /**
     * The loaded models, as KataGo's query_models reports them. They are kept
     * as arrays because KataGo adds fields from version to version.
     *
     * @return list<array<string, mixed>>
     */
    public function models(): array
    {
        return $this->transporter->read('GET', $this->path . '/models', null, fn(array $data) => (new Fields($data))->objects('models'));
    }
}
