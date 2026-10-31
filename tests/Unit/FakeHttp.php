<?php

declare(strict_types=1);

namespace FastGtp\Tests\Unit;

use FastGtp\FastGtpClient;
use FastGtp\HttpTransporter;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Answers requests from a queue of responses and keeps every request. */
final class FakeHttp implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @param list<ResponseInterface|ClientExceptionInterface> $responses */
    public function __construct(private array $responses = []) {}

    /** Fail the next request as the HTTP client would, for example on a refused connection. */
    public function fail(ClientExceptionInterface $exception): self
    {
        $this->responses[] = $exception;

        return $this;
    }

    /**
     * @param mixed $body Encoded as JSON unless it is a string.
     * @param array<string, string> $headers
     */
    public function answer(int $status, mixed $body = null, array $headers = []): self
    {
        $type = $status >= 400 ? 'application/problem+json' : 'application/json';
        $text = is_string($body) ? $body : ($body === null ? '' : json_encode($body));
        $this->responses[] = new Response($status, ['Content-Type' => $type] + $headers, $text);

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $next = array_shift($this->responses) ?? throw new \LogicException('No response queued');
        if ($next instanceof ClientExceptionInterface) {
            throw $next;
        }

        return $next;
    }

    /** @param array<string, string> $headers */
    public function client(array $headers = []): FastGtpClient
    {
        return new FastGtpClient('http://fastgtp.test/', $this, $headers);
    }

    /** @param array<string, string> $headers */
    public function transporter(array $headers = []): HttpTransporter
    {
        $factory = new HttpFactory();

        return new HttpTransporter('http://fastgtp.test/', $this, $factory, $factory, $headers);
    }

    /** @return array<string, mixed>|null */
    public function body(int $index): ?array
    {
        $text = (string) $this->requests[$index]->getBody();

        return $text === '' ? null : json_decode($text, true);
    }
}
