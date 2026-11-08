<?php

namespace App\Enums;

enum BugPriority: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    /**
     * Sort weight, highest priority first.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Critical => 4,
            self::High => 3,
            self::Medium => 2,
            self::Low => 1,
        };
    }
}
