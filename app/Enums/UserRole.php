<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Two roles, one users table.
 *
 * The audited inherited codebase ran a second `admins` table with its own guard
 * and middleware; docs/architecture.md §14 rejects that. A role column is
 * enough for a platform with exactly two kinds of account, and it keeps
 * ownership checks in one place.
 */
enum UserRole: string
{
    case Student = 'student';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Student => 'Student',
            self::Admin => 'Administrator',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
