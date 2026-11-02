<?php

declare(strict_types=1);

namespace FastGtp\KataGo;

/** Why a query failed, so the caller can decide whether to send it again. */
enum FailureReason: string
{
    /** KataGo refused the query, such as for an illegal move. Sending it again fails again. */
    case InvalidQuery = 'INVALID_QUERY';

    /** KataGo could not be started, or stopped before the query finished. Sending it again may work. */
    case KataGoError = 'KATAGO_ERROR';

    /** The query ran longer than the server allows. Ask for fewer visits or fewer turns. */
    case TimedOut = 'TIMED_OUT';
}
