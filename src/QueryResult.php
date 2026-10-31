<?php

declare(strict_types=1);

namespace FastGtp;

use DateTimeImmutable;

/**
 * A KataGo analysis query and what KataGo has sent for it so far.
 *
 * Responses, warnings and the error are kept as KataGo writes them, as
 * arrays, because KataGo adds fields from version to version. See KataGo's
 * analysis engine documentation for what they hold.
 */
final readonly class QueryResult
{
    /**
     * @param array<string, mixed> $query The query as it was sent.
     * @param list<array<string, mixed>> $responses One per analyzed turn, in turn order.
     * @param list<array<string, mixed>> $warnings
     * @param array<string, mixed>|null $error Set only when the status is FAILED.
     * @param int|null $retryAfter Seconds to wait before reading it again, while it runs.
     */
    public function __construct(
        public string $id,
        public QueryStatus $status,
        public array $query,
        public array $responses,
        public array $warnings,
        public ?array $error,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $finishedAt,
        public ?int $retryAfter = null,
    ) {}

    public function isFinished(): bool
    {
        return $this->status->isFinished();
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, ?int $retryAfter = null): self
    {
        $time = static fn(?string $value): ?DateTimeImmutable
            => $value === null ? null : new DateTimeImmutable($value);

        return new self(
            (string) $data['id'],
            QueryStatus::from($data['status']),
            $data['query'],
            array_values($data['responses']),
            array_values($data['warnings']),
            $data['error'] ?? null,
            new DateTimeImmutable($data['createdAt']),
            $time($data['startedAt'] ?? null),
            $time($data['finishedAt'] ?? null),
            $retryAfter,
        );
    }
}
