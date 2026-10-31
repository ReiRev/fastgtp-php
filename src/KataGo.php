<?php

declare(strict_types=1);

namespace FastGtp;

/** One KataGo analysis engine on the server. Each method sends one request, except analyze(). */
final class KataGo
{
    private readonly string $path;

    /** @internal Use Client::katago() or Client::katagos(). */
    public function __construct(
        private readonly Http $http,
        public readonly string $name,
    ) {
        $this->path = '/katago/' . Http::segment($name);
    }

    public function version(): KataGoVersion
    {
        return KataGoVersion::fromArray($this->http->json('GET', $this->path . '/version'));
    }

    /** @return list<array<string, mixed>> The loaded models, as KataGo's query_models reports them. */
    public function models(): array
    {
        return $this->http->json('GET', $this->path . '/models')['models'];
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
        $data = $this->http->json('POST', $this->path . '/queries', $query);

        return new Query($this->http, $this->path, (string) $data['id']);
    }

    /** The query with this id, for example one kept from an earlier request. Sends nothing. */
    public function query(string $id): Query
    {
        return new Query($this->http, $this->path, $id);
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
