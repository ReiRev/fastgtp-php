<?php

declare(strict_types=1);

namespace FastGtp;

use RuntimeException;

/** A KataGo query did not finish in the time the caller allowed. */
final class TimeoutException extends RuntimeException {}
