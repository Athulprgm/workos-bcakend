<?php

namespace App\Enums;

enum BugStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Fixed = 'fixed';
    case Retesting = 'retesting';
    case Closed = 'closed';

    public function isClosed(): bool
    {
        return $this === self::Closed;
    }
}
