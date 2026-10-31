<?php

declare(strict_types=1);

namespace FastGtp;

final readonly class Move
{
    /** @param string $vertex A point such as "D4", "PASS", or "RESIGN" for a generated move. */
    public function __construct(
        public Color $color,
        public string $vertex,
    ) {}

    public function isResignation(): bool
    {
        return $this->vertex === 'RESIGN';
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(Color::from($data['color']), (string) $data['vertex']);
    }
}
