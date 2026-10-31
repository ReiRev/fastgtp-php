<?php

declare(strict_types=1);

namespace FastGtp\KataGo;

use FastGtp\FastGtpException;

/**
 * A KataGo query did not finish in the time the caller allowed. The query
 * was deleted, which stops it. Catching FastGtpException catches this too.
 */
final class TimeoutException extends FastGtpException {}
