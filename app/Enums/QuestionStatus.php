<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a question may be served to a student.
 *
 * Three states rather than two because Handover 13 reviews AI-generated
 * questions: `review` is "written, not yet trusted", and a question in that
 * state must never reach a quiz (docs/handovers/07-assessment-engine.md,
 * docs/architecture.md §8.4). `draft` is the migration default, so a row
 * created by an import or the admin tool is invisible until published
 * deliberately.
 */
enum QuestionStatus: string
{
    case Draft = 'draft';
    case Review = 'review';
    case Published = 'published';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Review => 'Awaiting review',
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
