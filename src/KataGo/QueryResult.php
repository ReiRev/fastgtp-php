<?php

declare(strict_types=1);

namespace FastGtp\KataGo;

use DateTimeImmutable;
use Exception;
use FastGtp\Fields;
use FastGtp\HttpTransporter;
use Psr\Http\Message\ResponseInterface;
use UnexpectedValueException;

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
     * @param list<array<string, mixed>> $responses At most one per analyzed turn, in turn order.
     * @param list<array<string, mixed>> $warnings
     * @param array<string, mixed>|null $error Set only when the status is FAILED.
     * @param int|null $retryAfter Seconds to wait before reading it again, while it is not finished.
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
    public static function fromResponse(array $data, ResponseInterface $response): self
    {
        $fields = new Fields($data);
        $status = QueryStatus::tryFrom($fields->string('status'))
            ?? throw new UnexpectedValueException('The response has no valid "status"');
        $fields->object('query');
        $optional = static fn(string $key): bool => ($data[$key] ?? null) !== null;
        if ($optional('error')) {
            $fields->object('error');
        }

        return new self(
            $fields->id('id'),
            $status,
            $data['query'],
            $fields->objects('responses'),
            $fields->objects('warnings'),
            $optional('error') ? $data['error'] : null,
            self::time($fields->string('createdAt')),
            $optional('startedAt') ? self::time($fields->string('startedAt')) : null,
            $optional('finishedAt') ? self::time($fields->string('finishedAt')) : null,
            HttpTransporter::retryAfter($response),
        );
    }

    private static function time(string $value): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (Exception $e) {
            throw new UnexpectedValueException("The response has a time that is not valid: \"$value\"", 0, $e);
        }
    }
}
