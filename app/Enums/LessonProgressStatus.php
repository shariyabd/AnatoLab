<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How far one student has got through one lesson.
 *
 * Three states rather than a bare `completed` boolean because F10 reads these
 * rows to compute mastery and to recommend the next activity
 * (docs/architecture.md §10): "started and abandoned" and "never opened" are
 * different signals, and a boolean cannot tell them apart.
 *
 * There is no `not_started`. A student who has never opened a lesson has no
 * row at all — storing one would mean writing a row per student per lesson on
 * first page load, and "no row" already says it.
 */
enum LessonProgressStatus: string
{
    case InProgress = 'in_progress';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::InProgress => 'In progress',
            self::Completed => 'Completed',
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
