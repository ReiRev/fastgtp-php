<?php

declare(strict_types=1);

namespace FastGtp\KataGo;

enum QueryStatus: string
{
    case Queued = 'QUEUED';
    case Running = 'RUNNING';
    case Succeeded = 'SUCCEEDED';
    case Failed = 'FAILED';

    public function isFinished(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }
}
