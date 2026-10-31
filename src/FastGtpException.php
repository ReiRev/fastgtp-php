<?php

declare(strict_types=1);

namespace FastGtp;

use RuntimeException;
use Throwable;

/**
 * A request to fastgtp that failed: an error response, a response that is
 * not what fastgtp sends, or no response at all.
 *
 * For an error response, the fields are read from its RFC 9457 problem
 * details, whose types are listed in fastgtp's docs/problems.md. Use is() to
 * tell them apart, for example is('illegal-move'). When no response arrived,
 * such as a refused connection, $status is null and getPrevious() holds the
 * HTTP client's exception.
 */
class FastGtpException extends RuntimeException
{
    public function __construct(
        public readonly string $title,
        public readonly ?int $status = null,
        public readonly string $type = 'about:blank',
        public readonly ?string $detail = null,
        public readonly ?string $field = null,
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($detail ?? $title, $status ?? 0, $previous);
    }

    /** Whether this is the problem type with this name, such as "illegal-move". */
    public function is(string $name): bool
    {
        return str_ends_with($this->type, '#' . $name);
    }
}
