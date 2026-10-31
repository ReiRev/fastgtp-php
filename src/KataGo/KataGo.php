<?php

declare(strict_types=1);

namespace FastGtp\KataGo;

use FastGtp\Fields;
use FastGtp\HttpTransporter;

/**
 * One KataGo analysis engine on the server. Each method sends one request,
 * except analyze().
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

    /**
     * Queue an analysis query. Use the returned Query to read, wait for or delete it.
     *
     * @param array<string, mixed> $query A query of KataGo's analysis engine, without "id",
     *     for example ['rules' => 'japanese', 'boardXSize' => 19, 'boardYSize' => 19,
     *     'moves' => [['B', 'Q16']]]. An object such as "rules" must not be an empty array,
     *     which would be sent as [].
     */
    public function createQuery(array $query): Query
    {
        return $this->transporter->read(
            'POST',
            $this->path . '/queries',
            $query,
            fn(array $data) => new Query($this->transporter, $this->path, (new Fields($data))->id('id')),
        );
    }

    /** The query with this id, for example one kept from an earlier request. Sends nothing. */
    public function query(string $id): Query
    {
        return new Query($this->transporter, $this->path, $id);
    }

    /**
     * Send a query and wait until it has SUCCEEDED or FAILED. See Query::wait().
     *
     * @param array<string, mixed> $query See createQuery().
     */
    public function analyze(array $query, float $timeoutSeconds = 600.0): QueryResult
    {
        return $this->createQuery($query)->wait($timeoutSeconds);
    }
}
