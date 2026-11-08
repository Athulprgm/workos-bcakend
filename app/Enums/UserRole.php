<?php

namespace App\Enums;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Developer = 'developer';
    case GeneralManager = 'general_manager';
    case Tester = 'tester';

    /**
     * Legacy role kept for accounts created before the developer role existed.
     */
    case User = 'user';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin, self::Admin, self::Developer, self::User, self::GeneralManager => 'Admin',
            self::Tester => 'Tester',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SuperAdmin, self::Admin, self::Developer, self::User, self::GeneralManager => 'Full workspace access, team, and tester management.',
            self::Tester => 'Restricted to the Testing module and assigned projects.',
        };
    }

    /**
     * Roles that an Admin may assign from the Team screen.
     *
     * In this 2-module architecture:
     * - Admin has full control
     * - Tester is added by Admin
     *
     * @return array<int, string>
     */
    public static function assignableValues(): array
    {
        return [
            self::Admin->value,
            self::Tester->value,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function labelFor(?string $role): string
    {
        return self::tryFrom((string) $role)?->label() ?? 'Team member';
    }
}
