<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Backlog = 'backlog';
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Blocked = 'blocked';
    case Review = 'review';
    case Done = 'done';
    case Cancelled = 'cancelled';

    /**
     * Whether the task is finished. Used to decide if completed_at applies.
     */
    public function isClosed(): bool
    {
        return match ($this) {
            self::Done, self::Cancelled => true,
            default => false,
        };
    }
}
