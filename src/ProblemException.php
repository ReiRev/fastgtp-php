<?php

declare(strict_types=1);

namespace FastGtp;

use RuntimeException;

/**
 * An error response from fastgtp, read from its RFC 9457 problem details.
 *
 * The problem types are listed in fastgtp's docs/problems.md. Use is() to
 * tell them apart, for example is('queue-full').
 */
final class ProblemException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $type,
        public readonly string $title,
        public readonly ?string $detail = null,
        public readonly ?string $field = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($detail ?? $title, $status);
    }

    /** Whether this is the problem type with this name, such as "illegal-move". */
    public function is(string $name): bool
    {
        return str_ends_with($this->type, '#' . $name);
    }
}
