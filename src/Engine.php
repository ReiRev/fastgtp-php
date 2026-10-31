<?php

declare(strict_types=1);

namespace FastGtp;

/** What a session's engine reports about itself. */
final readonly class Engine
{
    /** @param list<string> $commands The GTP commands the engine has. */
    public function __construct(
        public string $name,
        public string $version,
        public string $protocolVersion,
        public array $commands,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $fields = new Fields($data);

        return new self(
            $fields->string('name'),
            $fields->string('version'),
            $fields->string('protocol_version'),
            $fields->strings('commands'),
        );
    }
}
