<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case Planning = 'planning';
    case InProgress = 'in_progress';
    case OnHold = 'on_hold';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    // Legacy statuses for backward compatibility with existing tests
    case Active = 'active';
    case Paused = 'paused';
    case Archived = 'archived';

    public function isOpen(): bool
    {
        return match ($this) {
            self::Planning, self::InProgress, self::OnHold, self::Active, self::Paused => true,
            self::Completed, self::Cancelled, self::Archived => false,
        };
    }
}
