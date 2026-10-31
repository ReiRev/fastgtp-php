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
        return new self((string) $data['version'], (string) $data['git_hash']);
    }
}
