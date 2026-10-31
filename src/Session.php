<?php

declare(strict_types=1);

namespace FastGtp;

/** One engine process on the server. */
final readonly class Session
{
    public function __construct(
        public string $id,
        public Engine $engine,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self((string) $data['session_id'], Engine::fromArray($data['engine']));
    }
}
