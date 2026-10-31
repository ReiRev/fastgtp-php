<?php

declare(strict_types=1);

namespace FastGtp\Tests\Unit;

use FastGtp\Client;
use FastGtp\Color;
use FastGtp\FastGtpException;
use PHPUnit\Framework\TestCase;

final class SessionsTest extends TestCase
{
    private const PROBLEMS = 'https://github.com/ReiRev/fastgtp/blob/main/docs/problems.md#';

    private const ENGINE = ['name' => 'GNU Go', 'version' => '3.8', 'protocol_version' => '2', 'commands' => ['play']];

    public function testCreateSession(): void
    {
        $http = (new FakeHttp())->answer(201, ['session_id' => 'abc', 'engine' => self::ENGINE]);
        $session = $http->client(['X-Token' => 'secret'])->createSession();

        $this->assertSame('abc', $session->id);
        $request = $http->requests[0];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('http://fastgtp.test/sessions', (string) $request->getUri());
        $this->assertSame('secret', $request->getHeaderLine('X-Token'));
        $this->assertNull($http->body(0));
    }

    public function testHttpClientIsFoundWhenNotGiven(): void
    {
        // Guzzle is installed for development, so discovery finds it.
        $this->assertSame('abc', (new Client('http://fastgtp.test'))->session('abc')->id);
    }

    public function testSessionFromIdSendsNothingUntilUsed(): void
    {
        $http = (new FakeHttp())->answer(200, ['session_id' => 'abc', 'engine' => self::ENGINE]);
        $session = $http->client()->session('abc');
        $this->assertSame([], $http->requests);

        $info = $session->info();
        $this->assertSame('abc', $info->id);
        $this->assertSame('GNU Go', $info->engine->name);
        $this->assertSame('2', $info->engine->protocolVersion);
        $this->assertSame('/sessions/abc', $http->requests[0]->getUri()->getPath());
    }

    public function testSessionIdIsEscaped(): void
    {
        $http = (new FakeHttp())->answer(204);
        $http->client()->session('a/b')->delete();

        $this->assertSame('DELETE', $http->requests[0]->getMethod());
        $this->assertSame('/sessions/a%2Fb', $http->requests[0]->getUri()->getPath());
    }

    public function testBoards(): void
    {
        $board = ['size' => 9, 'komi' => 7.0, 'sgf' => '(;SZ[9])'];
        $http = (new FakeHttp())->answer(200, $board)->answer(200, $board)->answer(200, $board);
        $session = $http->client()->session('s');

        $this->assertSame(9, $session->setBoard(9, 7.0)->size);
        $session->loadSgf('(;SZ[9];B[ee])', 0);
        $this->assertSame('(;SZ[9])', $session->board()->sgf);

        $this->assertSame('PUT', $http->requests[0]->getMethod());
        $this->assertSame('/sessions/s/board', $http->requests[0]->getUri()->getPath());
        // 7.0 stays a decimal number in the JSON.
        $this->assertStringContainsString('"komi":7.0', (string) $http->requests[0]->getBody());
        $this->assertSame(['sgf' => '(;SZ[9];B[ee])', 'move_count' => 0], $http->body(1));
        $this->assertSame('GET', $http->requests[2]->getMethod());
    }

    public function testMoves(): void
    {
        $http = (new FakeHttp())
            ->answer(201, ['color' => 'BLACK', 'vertex' => 'E5'])
            ->answer(201, ['color' => 'WHITE', 'vertex' => 'RESIGN'])
            ->answer(204);
        $session = $http->client()->session('s');

        $this->assertSame('E5', $session->play(Color::Black, 'E5')->vertex);
        $this->assertTrue($session->generateMove(Color::White)->isResignation());
        $session->undo();

        $this->assertSame(['color' => 'BLACK', 'vertex' => 'E5'], $http->body(0));
        $this->assertSame(['color' => 'WHITE'], $http->body(1));
        $this->assertSame('/sessions/s/moves/last', $http->requests[2]->getUri()->getPath());
    }

    public function testBadBodyFromAHandle(): void
    {
        // The handles must read data through the check for a JSON object.
        $http = (new FakeHttp())->answer(200)->answer(200, [1, 2]);
        $session = $http->client()->session('s');
        foreach ([1, 2] as $_) {
            try {
                $session->board();
                $this->fail('No exception');
            } catch (FastGtpException $e) {
                $this->assertSame(200, $e->status);
            }
        }
    }

    public function testBodyUnlikeFastgtps(): void
    {
        // As from another service behind the same proxy: JSON objects, but not fastgtp's.
        $engine = ['name' => 'x'];
        $http = (new FakeHttp())
            ->answer(200, ['size' => 9])
            ->answer(201, ['vertex' => 'E5'])
            ->answer(201, ['color' => 'black', 'vertex' => 'E5'])
            ->answer(200, ['session_id' => 's', 'engine' => $engine])
            ->answer(201, ['session_id' => '', 'engine' => self::ENGINE]);
        $client = $http->client();
        $session = $client->session('s');
        $calls = [
            fn() => $session->board(),
            fn() => $session->play(Color::Black, 'E5'),
            fn() => $session->play(Color::Black, 'E5'),
            fn() => $session->info(),
            fn() => $client->createSession(),
        ];
        foreach ($calls as $i => $call) {
            try {
                $call();
                $this->fail("Response $i was accepted");
            } catch (FastGtpException $e) {
                $this->assertSame($i === 0 || $i === 3 ? 200 : 201, $e->status);
            }
        }
    }

    public function testKomiMayArriveAsAnInteger(): void
    {
        $http = (new FakeHttp())->answer(200, '{"size": 9, "komi": 7, "sgf": "(;SZ[9])"}');
        $this->assertSame(7.0, $http->client()->session('s')->board()->komi);
    }

    public function testIdsThatWouldChangeThePath(): void
    {
        $client = (new FakeHttp())->client();
        foreach (['', '.', '..'] as $id) {
            try {
                $client->session($id);
                $this->fail("\"$id\" was accepted");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
