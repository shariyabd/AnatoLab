<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a simulation is offered to students.
 *
 * Mirrors OrganStatus and LessonStatus deliberately — same two states, same
 * default, same meaning — so publishing content is one rule across the
 * platform rather than three. `draft` is the migration default, so a
 * simulation whose configuration is still being authored cannot be run.
 *
 * That default matters more here than elsewhere: a simulation's behaviour
 * lives in a JSON column, and a half-written `configuration` is a broken
 * state machine rather than a page with a missing paragraph.
 */
enum SimulationStatus: string
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
