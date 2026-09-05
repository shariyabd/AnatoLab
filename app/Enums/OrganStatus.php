<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether an organ is visible to students.
 *
 * `draft` is the default in the migration, so a row created by the admin tool
 * or an import is invisible until someone publishes it deliberately.
 */
enum OrganStatus: string
{
    case Draft = 'draft';
    case Published = 'published';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
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
