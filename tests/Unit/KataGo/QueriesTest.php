<?php

declare(strict_types=1);

namespace FastGtp\Tests\Unit\KataGo;

use FastGtp\FastGtpException;
use FastGtp\KataGo\KataGo;
use FastGtp\KataGo\QueryStatus;
use FastGtp\KataGo\TimeoutException;
use FastGtp\Tests\Unit\FakeHttp;
use PHPUnit\Framework\TestCase;

final class QueriesTest extends TestCase
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

    /** A KataGo whose waits are recorded in $http->sleeps instead of slept. */
    private static function katago(FakeHttp $http): KataGo
    {
        return new KataGo($http->transporter(), 'default');
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
        $this->assertNull($running->startedAt);

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

        $result = self::katago($http)->analyze(self::QUERY);

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
            ->answer(200, self::query('RUNNING'), ['Retry-After' => '1'])
            ->answer(204);

        try {
            self::katago($http)->query('q1')->wait(1.5);
            $this->fail('No exception');
        } catch (TimeoutException $e) {
            // Callers who catch FastGtpException catch this too.
            $this->assertInstanceOf(FastGtpException::class, $e);
        }
        // It sleeps only the half second left, and reads once more before deleting.
        $this->assertEqualsWithDelta([1.0, 0.5], $http->sleeps, 0.01);
        $this->assertSame('DELETE', $http->requests[3]->getMethod());
        $this->assertSame('/katago/default/queries/q1', $http->requests[3]->getUri()->getPath());
    }

    public function testWaitReturnsAQueryThatFinishesAtTheDeadline(): void
    {
        $http = (new FakeHttp())
            ->answer(200, self::query('RUNNING'), ['Retry-After' => '5'])
            ->answer(200, self::query('SUCCEEDED'));

        $result = self::katago($http)->query('q1')->wait(1.0);
        $this->assertSame(QueryStatus::Succeeded, $result->status);
        $this->assertEqualsWithDelta([1.0], $http->sleeps, 0.01);
    }

    public function testTimeoutIgnoresAQueryAlreadyRemoved(): void
    {
        $http = (new FakeHttp())
            ->answer(200, self::query('RUNNING'), ['Retry-After' => '5'])
            ->answer(200, self::query('RUNNING'), ['Retry-After' => '5'])
            ->answer(404, ['type' => self::PROBLEMS . 'query-not-found', 'title' => 'Query not found', 'status' => 404]);

        $this->expectException(TimeoutException::class);
        self::katago($http)->query('q1')->wait(1.0);
    }

    public function testQueueFull(): void
    {
        $http = (new FakeHttp())->answer(503, [
            'type' => self::PROBLEMS . 'queue-full',
            'title' => 'Queue full',
            'status' => 503,
        ], ['Retry-After' => '5']);
        try {
            self::katago($http)->analyze(self::QUERY);
            $this->fail('No exception');
        } catch (FastGtpException $e) {
            $this->assertTrue($e->is('queue-full'));
            $this->assertSame(5, $e->retryAfter);
        }
    }

    public function testBodyUnlikeFastgtps(): void
    {
        $http = (new FakeHttp())
            ->answer(201, ['id' => '..'])
            ->answer(200, self::query('DONE'))
            ->answer(200, self::query('RUNNING', ['createdAt' => 'yesterday-ish']))
            ->answer(200, self::query('FAILED', ['error' => 'Illegal move']));
        $katago = self::katago($http);
        $calls = [
            fn() => $katago->createQuery(self::QUERY),
            fn() => $katago->query('q1')->get(),
            fn() => $katago->query('q1')->get(),
            fn() => $katago->query('q1')->get(),
        ];
        foreach ($calls as $i => $call) {
            try {
                $call();
                $this->fail("Response $i was accepted");
            } catch (FastGtpException $e) {
                $this->assertNotNull($e->status);
            }
        }
    }

    public function testIdsThatWouldChangeThePath(): void
    {
        $katago = self::katago(new FakeHttp());
        foreach (['', '.', '..'] as $id) {
            try {
                $katago->query($id);
                $this->fail("\"$id\" was accepted");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
