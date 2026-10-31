<?php

declare(strict_types=1);

namespace FastGtp\Tests\Unit;

use FastGtp\ProblemException;
use FastGtp\QueryStatus;
use FastGtp\TimeoutException;
use PHPUnit\Framework\TestCase;

final class KataGoTest extends TestCase
{
    private const PROBLEMS = 'https://github.com/ReiRev/fastgtp/blob/main/docs/problems.md#';

    private const QUERY = ['rules' => 'japanese', 'boardXSize' => 19, 'boardYSize' => 19, 'moves' => []];

    /** @return array<string, mixed> */
    private static function query(string $status, array $more = []): array
    {
        return $more + [
            'id' => 'q1',
            'status' => $status,
            'query' => self::QUERY,
            'responses' => [],
            'warnings' => [],
            'createdAt' => '2026-10-31T01:00:00.000Z',
        ];
    }

    public function testListVersionAndModels(): void
    {
        $http = (new FakeHttp())
            ->answer(200, ['items' => [['id' => 'default'], ['id' => 'human']]])
            ->answer(200, ['version' => '1.18.2', 'git_hash' => 'abc'])
            ->answer(200, ['models' => [['name' => 'm.bin.gz', 'futureField' => 1]]]);
        $client = $http->client();

        $this->assertSame(['default', 'human'], $client->listKataGos());
        $this->assertSame('1.18.2', $client->getKataGoVersion('default')['version']);
        $this->assertSame(1, $client->getKataGoModels('default')[0]['futureField']);
        $this->assertSame('/katago/default/models', $http->requests[2]->getUri()->getPath());
    }

    public function testCreateAndReadQuery(): void
    {
        $response = ['id' => 'q1', 'turnNumber' => 0, 'rootInfo' => ['winrate' => 0.47], 'moveInfos' => []];
        $http = (new FakeHttp())
            ->answer(201, self::query('QUEUED'), ['Retry-After' => '1', 'Location' => '/katago/default/queries/q1'])
            ->answer(200, self::query('SUCCEEDED', [
                'responses' => [$response],
                'startedAt' => '2026-10-31T01:00:01.000Z',
                'finishedAt' => '2026-10-31T01:00:02.500Z',
            ]));
        $client = $http->client();

        $created = $client->createQuery('default', self::QUERY);
        $this->assertSame(QueryStatus::Queued, $created->status);
        $this->assertSame(1, $created->retryAfter);
        // An empty moves list is sent as a JSON array.
        $this->assertStringContainsString('"moves":[]', (string) $http->requests[0]->getBody());

        $done = $client->getQuery('default', 'q1');
        $this->assertTrue($done->isFinished());
        $this->assertNull($done->retryAfter);
        $this->assertSame(0.47, $done->responses[0]['rootInfo']['winrate']);
        $this->assertSame('2026-10-31T01:00:02.500+00:00', $done->finishedAt->format('Y-m-d\TH:i:s.vP'));
    }

    public function testAnalyzeWaitsAsAsked(): void
    {
        $http = (new FakeHttp())
            ->answer(201, self::query('QUEUED'), ['Retry-After' => '2'])
            ->answer(200, self::query('RUNNING'), ['Retry-After' => '1'])
            ->answer(200, self::query('FAILED', ['error' => ['error' => 'Illegal move 1: E5', 'field' => 'moves']]));

        $query = $http->client()->analyze('default', self::QUERY);

        $this->assertSame(QueryStatus::Failed, $query->status);
        $this->assertSame('moves', $query->error['field']);
        $this->assertSame([2.0, 1.0], $http->sleeps);
        $this->assertSame('GET', $http->requests[2]->getMethod());
    }

    public function testAnalyzeTimesOutAndDeletes(): void
    {
        $http = (new FakeHttp())
            ->answer(201, self::query('QUEUED'), ['Retry-After' => '1'])
            ->answer(200, self::query('RUNNING'), ['Retry-After' => '1'])
            ->answer(204);

        try {
            $http->client()->analyze('default', self::QUERY, 1.5);
            $this->fail('No exception');
        } catch (TimeoutException) {
        }
        $this->assertSame([1.0], $http->sleeps);
        $this->assertSame('DELETE', $http->requests[2]->getMethod());
        $this->assertSame('/katago/default/queries/q1', $http->requests[2]->getUri()->getPath());
    }

    public function testTimeoutIgnoresAQueryAlreadyRemoved(): void
    {
        $http = (new FakeHttp())
            ->answer(201, self::query('RUNNING'), ['Retry-After' => '5'])
            ->answer(404, ['type' => self::PROBLEMS . 'query-not-found', 'title' => 'Query not found', 'status' => 404]);

        $this->expectException(TimeoutException::class);
        $http->client()->analyze('default', self::QUERY, 1.0);
    }

    public function testQueueFull(): void
    {
        $http = (new FakeHttp())->answer(503, [
            'type' => self::PROBLEMS . 'queue-full',
            'title' => 'Queue full',
            'status' => 503,
        ], ['Retry-After' => '5']);
        try {
            $http->client()->analyze('default', self::QUERY);
            $this->fail('No exception');
        } catch (ProblemException $e) {
            $this->assertTrue($e->is('queue-full'));
            $this->assertSame(5, $e->retryAfter);
        }
    }
}
