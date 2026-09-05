<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a mission is visible to students.
 *
 * Mirrors LessonStatus and OrganStatus deliberately — same two states, same
 * default, same meaning — so Handover 13's admin publishes every content type
 * by one rule rather than four. `draft` is the migration default, so a mission
 * created by an import or the authoring tool is invisible until someone
 * publishes it on purpose.
 */
enum MissionStatus: string
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
