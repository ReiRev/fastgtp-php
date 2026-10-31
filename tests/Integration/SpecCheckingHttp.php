<?php

declare(strict_types=1);

namespace FastGtp\Tests\Integration;

use Opis\JsonSchema\Validator;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Passes requests on, and checks each response body against the server's
 * own OpenAPI document, so the tests notice when the client and the spec
 * no longer agree.
 */
final class SpecCheckingHttp implements ClientInterface
{
    private const SPEC_URI = 'https://fastgtp.test/openapi.json';

    private readonly Validator $validator;

    /** @var list<string> */
    public array $checked = [];

    /** @param array<string, mixed> $spec The decoded /openapi.json. */
    public function __construct(private readonly ClientInterface $inner, private readonly array $spec)
    {
        $this->validator = new Validator();
        $this->validator->resolver()->registerRaw(json_encode($spec), self::SPEC_URI);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $response = $this->inner->sendRequest($request);
        $body = (string) $response->getBody();
        $response->getBody()->rewind();

        $method = strtolower($request->getMethod());
        $path = $request->getUri()->getPath();
        $status = (string) $response->getStatusCode();
        $schema = $this->schemaFor($method, $path, $status, $response->getHeaderLine('Content-Type'));
        if ($schema === null) {
            if ($body !== '') {
                throw new RuntimeException("$method $path $status has a body the spec does not describe");
            }
        } else {
            $result = $this->validator->validate(json_decode($body), (object) ['$ref' => self::SPEC_URI . $schema]);
            if (!$result->isValid()) {
                $error = $result->error();
                throw new RuntimeException("$method $path $status does not match $schema: {$error->message()} at " . implode('/', $error->data()->fullPath()));
            }
        }
        $this->checked[] = "$method $path $status";

        return $response;
    }

    /** The response schema's reference, such as "#/components/schemas/Board", or null without a body. */
    private function schemaFor(string $method, string $path, string $status, string $contentType): ?string
    {
        foreach ($this->spec['paths'] as $template => $operations) {
            $pattern = '#^' . preg_replace('#\\\\\{[^/]+\\\\\}#', '[^/]+', preg_quote($template, '#')) . '$#';
            if (!preg_match($pattern, $path) || !isset($operations[$method])) {
                continue;
            }
            $responses = $operations[$method]['responses'];
            $response = $responses[$status] ?? $responses['default']
                ?? throw new RuntimeException("$method $template has no response $status in the spec");
            if (isset($response['$ref'])) {
                $response = $this->spec['components']['responses'][basename($response['$ref'])];
            }
            $type = strtok($contentType, ';');
            if (!isset($response['content'])) {
                return null;
            }

            return $response['content'][$type]['schema']['$ref']
                ?? throw new RuntimeException("$method $template $status has no $type content in the spec");
        }
        throw new RuntimeException("$method $path is not in the spec");
    }
}
