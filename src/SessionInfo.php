<?php

declare(strict_types=1);

namespace FastGtp;

/** What the server reports about a session. */
final readonly class SessionInfo
{
    public function __construct(
        public string $id,
        public Engine $engine,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $fields = new Fields($data);
        $fields->object('engine');

        return new self($fields->string('session_id'), Engine::fromArray($data['engine']));
    }
}
