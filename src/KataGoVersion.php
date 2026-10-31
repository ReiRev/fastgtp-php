<?php

declare(strict_types=1);

namespace FastGtp;

final readonly class KataGoVersion
{
    public function __construct(
        public string $version,
        public string $gitHash,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $fields = new Fields($data);

        return new self($fields->string('version'), $fields->string('git_hash'));
    }
}
