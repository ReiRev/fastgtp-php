<?php

declare(strict_types=1);

namespace FastGtp\Tests\Unit;

use FastGtp\Client;
use FastGtp\Http;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Answers requests from a queue of responses and keeps every request. */
final class FakeHttp implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<float> */
    public array $sleeps = [];

    /** @param list<ResponseInterface> $responses */
    public function __construct(private array $responses = []) {}

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

        return array_shift($this->responses) ?? throw new \LogicException('No response queued');
    }

    /** @param array<string, string> $headers */
    public function client(array $headers = []): Client
    {
        $factory = new HttpFactory();

        return new Client('http://fastgtp.test/', $this, $factory, $factory, $headers, function (float $s): void {
            $this->sleeps[] = $s;
        });
    }

    /** @param array<string, string> $headers */
    public function http(array $headers = []): Http
    {
        $factory = new HttpFactory();

        return new Http('http://fastgtp.test/', $this, $factory, $factory, $headers, function (float $s): void {
            $this->sleeps[] = $s;
        });
    }

    /** @return array<string, mixed>|null */
    public function body(int $index): ?array
    {
        $text = (string) $this->requests[$index]->getBody();

        return $text === '' ? null : json_decode($text, true);
    }
}
