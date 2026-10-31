<?php

declare(strict_types=1);

namespace FastGtp\KataGo;

use FastGtp\Fields;

/** What KataGo reports for its query_version action. */
final readonly class Version
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
