<?php

declare(strict_types=1);

namespace FastGtp;

final readonly class Board
{
    /** @param string $sgf The position as SGF, as the engine writes it. */
    public function __construct(
        public int $size,
        public float $komi,
        public string $sgf,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $fields = new Fields($data);

        return new self($fields->int('size'), $fields->float('komi'), $fields->string('sgf'));
    }
}
