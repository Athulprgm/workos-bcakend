<?php

namespace App\Enums;

enum TaskPriority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Urgent = 'urgent';

    /**
     * Sort weight, highest priority first.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Urgent => 4,
            self::High => 3,
            self::Medium => 2,
            self::Low => 1,
        };
    }
}
