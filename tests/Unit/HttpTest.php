<?php

declare(strict_types=1);

namespace FastGtp\Tests\Unit;

use FastGtp\Http;
use FastGtp\ProblemException;
use PHPUnit\Framework\TestCase;

final class HttpTest extends TestCase
{
    private const PROBLEMS = 'https://github.com/ReiRev/fastgtp/blob/main/docs/problems.md#';

    public function testRequest(): void
    {
        $http = (new FakeHttp())->answer(200, ['size' => 9]);
        $data = $http->http(['X-Token' => 'secret'])->json('PUT', '/sessions/s/board', ['komi' => 7.0]);

        $this->assertSame(['size' => 9], $data);
        $request = $http->requests[0];
        $this->assertSame('PUT', $request->getMethod());
        $this->assertSame('http://fastgtp.test/sessions/s/board', (string) $request->getUri());
        $this->assertSame('secret', $request->getHeaderLine('X-Token'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        // 7.0 stays a decimal number in the JSON.
        $this->assertSame('{"komi":7.0}', (string) $request->getBody());
    }

    public function testRequestWithoutBody(): void
    {
        $http = (new FakeHttp())->answer(204);
        [$body] = $http->http()->send('DELETE', '/sessions/s');

        $this->assertNull($body);
        $this->assertNull($http->body(0));
        $this->assertSame('', $http->requests[0]->getHeaderLine('Content-Type'));
    }

    public function testProblemIsThrown(): void
    {
        $http = (new FakeHttp())->answer(409, [
            'type' => self::PROBLEMS . 'illegal-move',
            'title' => 'Illegal move',
            'status' => 409,
            'detail' => 'illegal move',
            'field' => 'vertex',
        ]);
        try {
            $http->http()->json('POST', '/sessions/s/moves', ['color' => 'BLACK', 'vertex' => 'E5']);
            $this->fail('No exception');
        } catch (ProblemException $e) {
            $this->assertTrue($e->is('illegal-move'));
            $this->assertFalse($e->is('move'));
            $this->assertSame(409, $e->status);
            $this->assertSame('vertex', $e->field);
            $this->assertSame('illegal move', $e->getMessage());
        }
    }

    public function testRetryAfterIsRead(): void
    {
        $http = (new FakeHttp())->answer(503, [
            'type' => self::PROBLEMS . 'too-many-sessions',
            'title' => 'Too many sessions',
            'status' => 503,
        ], ['Retry-After' => '5']);
        try {
            $http->http()->json('POST', '/sessions');
            $this->fail('No exception');
        } catch (ProblemException $e) {
            $this->assertSame(5, $e->retryAfter);
            $this->assertSame('Too many sessions', $e->getMessage());
        }
    }

    public function testRedirectIsNotSuccess(): void
    {
        // As from a proxy that sends clients without credentials to log in.
        $http = (new FakeHttp())->answer(302, null, ['Location' => '/login']);
        try {
            $http->http()->send('DELETE', '/sessions/s');
            $this->fail('No exception');
        } catch (ProblemException $e) {
            $this->assertSame(302, $e->status);
        }
    }

    public function testErrorThatIsNotAProblem(): void
    {
        // For example a proxy's own error page.
        $http = (new FakeHttp())->answer(502, '<html>Bad gateway</html>');
        try {
            $http->http()->json('GET', '/sessions/s');
            $this->fail('No exception');
        } catch (ProblemException $e) {
            $this->assertSame(502, $e->status);
            $this->assertSame('about:blank', $e->type);
            $this->assertSame('Bad Gateway', $e->title);
        }
    }

    public function testSuccessThatIsNotAJsonObject(): void
    {
        // HTML from a proxy, an empty body and a list are all not the object expected.
        $http = (new FakeHttp())->answer(200, '<html>Log in</html>')->answer(200)->answer(200, [1, 2]);
        $client = $http->http();
        for ($i = 0; $i < 3; $i++) {
            try {
                $client->json('GET', '/sessions/s/board');
                $this->fail("Response $i was accepted");
            } catch (ProblemException $e) {
                $this->assertSame(200, $e->status);
            }
        }
    }

    public function testEmptyObjectIsAnObject(): void
    {
        $http = (new FakeHttp())->answer(200, '{}');
        $this->assertSame([], $http->http()->json('GET', '/x'));
    }

    public function testIdsThatWouldChangeThePath(): void
    {
        $this->assertSame('a%2Fb', Http::segment('a/b'));
        foreach (['', '.', '..'] as $id) {
            try {
                Http::segment($id);
                $this->fail("\"$id\" was accepted");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
