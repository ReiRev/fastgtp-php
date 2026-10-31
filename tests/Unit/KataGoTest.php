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

        $katagos = $client->katagos();
        $this->assertSame(['default', 'human'], array_map(fn($k) => $k->name, $katagos));
        $version = $katagos[0]->version();
        $this->assertSame('1.18.2', $version->version);
        $this->assertSame('abc', $version->gitHash);
        $this->assertSame(1, $client->katago('default')->models()[0]['futureField']);
        $this->assertSame('/katago/default/models', $http->requests[2]->getUri()->getPath());
    }

    public function testCreateAndReadQuery(): void
    {
        $response = ['id' => 'q1', 'turnNumber' => 0, 'rootInfo' => ['winrate' => 0.47], 'moveInfos' => []];
        $http = (new FakeHttp())
            ->answer(201, self::query('QUEUED'), ['Retry-After' => '1', 'Location' => '/katago/default/queries/q1'])
            ->answer(200, self::query('RUNNING'), ['Retry-After' => '1'])
            ->answer(200, self::query('SUCCEEDED', [
                'responses' => [$response],
                'startedAt' => '2026-10-31T01:00:01.000Z',
                'finishedAt' => '2026-10-31T01:00:02.500Z',
            ]));
        $katago = $http->client()->katago('default');

        $query = $katago->createQuery(self::QUERY);
        $this->assertSame('q1', $query->id);
        // An empty moves list is sent as a JSON array.
        $this->assertStringContainsString('"moves":[]', (string) $http->requests[0]->getBody());

        $running = $query->get();
        $this->assertSame(QueryStatus::Running, $running->status);
        $this->assertSame(1, $running->retryAfter);

        $done = $katago->query('q1')->get();
        $this->assertTrue($done->isFinished());
        $this->assertNull($done->retryAfter);
        $this->assertSame(0.47, $done->responses[0]['rootInfo']['winrate']);
        $this->assertSame('2026-10-31T01:00:02.500+00:00', $done->finishedAt->format('Y-m-d\TH:i:s.vP'));
        $this->assertSame('/katago/default/queries/q1', $http->requests[2]->getUri()->getPath());
    }

    public function testAnalyzeWaitsAsAsked(): void
    {
        $http = (new FakeHttp())
            ->answer(201, self::query('QUEUED'), ['Retry-After' => '2'])
            ->answer(200, self::query('QUEUED'), ['Retry-After' => '2'])
            ->answer(200, self::query('RUNNING'), ['Retry-After' => '1'])
            ->answer(200, self::query('FAILED', ['error' => ['error' => 'Illegal move 1: E5', 'field' => 'moves']]));

        $result = $http->client()->katago('default')->analyze(self::QUERY);

        $this->assertSame(QueryStatus::Failed, $result->status);
        $this->assertSame('moves', $result->error['field']);
        $this->assertSame([2.0, 1.0], $http->sleeps);
        $this->assertSame('GET', $http->requests[3]->getMethod());
    }

    public function testWaitTimesOutAndDeletes(): void
    {
        $http = (new FakeHttp())
            ->answer(200, self::query('QUEUED'), ['Retry-After' => '1'])
            ->answer(200, self::query('RUNNING'), ['Retry-After' => '1'])
            ->answer(204);

        try {
            $http->client()->katago('default')->query('q1')->wait(1.5);
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
            ->answer(200, self::query('RUNNING'), ['Retry-After' => '5'])
            ->answer(404, ['type' => self::PROBLEMS . 'query-not-found', 'title' => 'Query not found', 'status' => 404]);

        $this->expectException(TimeoutException::class);
        $http->client()->katago('default')->query('q1')->wait(1.0);
    }

    public function testIdsThatWouldChangeThePath(): void
    {
        $katago = (new FakeHttp())->client()->katago('default');
        foreach (['', '.', '..'] as $id) {
            try {
                $katago->query($id);
                $this->fail("\"$id\" was accepted");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        (new FakeHttp())->client()->katago('..');
    }

    public function testQueueFull(): void
    {
        $http = (new FakeHttp())->answer(503, [
            'type' => self::PROBLEMS . 'queue-full',
            'title' => 'Queue full',
            'status' => 503,
        ], ['Retry-After' => '5']);
        try {
            $http->client()->katago('default')->analyze(self::QUERY);
            $this->fail('No exception');
        } catch (ProblemException $e) {
            $this->assertTrue($e->is('queue-full'));
            $this->assertSame(5, $e->retryAfter);
        }
    }
}
