<?php

declare(strict_types=1);

namespace FastGtp\Tests\Unit;

use FastGtp\HttpTransporter;
use FastGtp\FastGtpException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;

final class HttpTransporterTest extends TestCase
{
    private const PROBLEMS = 'https://github.com/ReiRev/fastgtp/blob/main/docs/problems.md#';

    public function testRequest(): void
    {
        $http = (new FakeHttp())->answer(200, ['size' => 9]);
        $data = $http->transporter(['X-Token' => 'secret'])->json('PUT', '/sessions/s/board', ['komi' => 7.0]);

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
        [$body] = $http->transporter()->send('DELETE', '/sessions/s');

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
            $http->transporter()->json('POST', '/sessions/s/moves', ['color' => 'BLACK', 'vertex' => 'E5']);
            $this->fail('No exception');
        } catch (FastGtpException $e) {
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
            $http->transporter()->json('POST', '/sessions');
            $this->fail('No exception');
        } catch (FastGtpException $e) {
            $this->assertSame(5, $e->retryAfter);
            $this->assertSame('Too many sessions', $e->getMessage());
        }
    }

    public function testRedirectIsNotSuccess(): void
    {
        // As from a proxy that sends clients without credentials to log in.
        $http = (new FakeHttp())->answer(302, null, ['Location' => '/login']);
        try {
            $http->transporter()->send('DELETE', '/sessions/s');
            $this->fail('No exception');
        } catch (FastGtpException $e) {
            $this->assertSame(302, $e->status);
        }
    }

    public function testErrorThatIsNotAProblem(): void
    {
        // For example a proxy's own error page.
        $http = (new FakeHttp())->answer(502, '<html>Bad gateway</html>');
        try {
            $http->transporter()->json('GET', '/sessions/s');
            $this->fail('No exception');
        } catch (FastGtpException $e) {
            $this->assertSame(502, $e->status);
            $this->assertSame('about:blank', $e->type);
            $this->assertSame('Bad Gateway', $e->title);
        }
    }

    public function testSuccessThatIsNotAJsonObject(): void
    {
        // HTML from a proxy, an empty body and lists are all not the object expected.
        $http = (new FakeHttp())->answer(200, '<html>Log in</html>')->answer(200)->answer(200, [1, 2])->answer(200, '[]');
        $client = $http->transporter();
        for ($i = 0; $i < 4; $i++) {
            try {
                $client->json('GET', '/sessions/s/board');
                $this->fail("Response $i was accepted");
            } catch (FastGtpException $e) {
                $this->assertSame(200, $e->status);
            }
        }
    }

    public function testHttpClientFailureIsWrapped(): void
    {
        $refused = new ConnectException('Connection refused', new Request('GET', 'http://fastgtp.test/'));
        $http = (new FakeHttp())->fail($refused);
        try {
            $http->transporter()->json('GET', '/sessions/s');
            $this->fail('No exception');
        } catch (FastGtpException $e) {
            $this->assertNull($e->status);
            $this->assertSame('Connection refused', $e->getMessage());
            $this->assertSame($refused, $e->getPrevious());
        }
    }

    public function testObjectsThatLookLikeLists(): void
    {
        $http = (new FakeHttp())->answer(200, '{}')->answer(200, '{"0": "a"}');
        $this->assertSame([], $http->transporter()->json('GET', '/x'));
        $this->assertSame(['a'], $http->transporter()->json('GET', '/x'));
    }

    public function testBodyThatCanBeReadOnce(): void
    {
        // As with Guzzle's "stream" option.
        $body = new NoSeekStream(Utils::streamFor('{"size":9}'));
        $http = new FakeHttp([new Response(200, ['Content-Type' => 'application/json'], $body)]);
        $this->assertSame(['size' => 9], $http->transporter()->json('GET', '/x'));
    }

    public function testErrorWithoutReasonPhrase(): void
    {
        $http = (new FakeHttp())->answer(599);
        try {
            $http->transporter()->json('GET', '/x');
            $this->fail('No exception');
        } catch (FastGtpException $e) {
            $this->assertSame('HTTP 599', $e->getMessage());
        }
    }

    public function testErrorBodyFromAnotherServer(): void
    {
        // As FastAPI's default validation error, where "detail" is a list.
        $http = (new FakeHttp())->answer(422, ['detail' => [['msg' => 'x']], 'title' => ['a' => 1]]);
        try {
            $http->transporter()->json('GET', '/x');
            $this->fail('No exception');
        } catch (FastGtpException $e) {
            $this->assertSame('Unprocessable Entity', $e->title);
            $this->assertNull($e->detail);
            $this->assertSame('about:blank', $e->type);
        }
    }

    public function testIdsThatWouldChangeThePath(): void
    {
        $this->assertSame('a%2Fb', HttpTransporter::segment('a/b'));
        foreach (['', '.', '..'] as $id) {
            try {
                HttpTransporter::segment($id);
                $this->fail("\"$id\" was accepted");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
