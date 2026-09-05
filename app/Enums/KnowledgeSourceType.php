<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What kind of thing a knowledge document came from (PRD §10).
 *
 * A closed set rather than a free string because it is shown next to a citation
 * a student reads: "Textbook" and "Teacher lesson" carry different authority,
 * and the difference has to survive an import.
 *
 * Every case must be redistributable material — the corpus is subject to the
 * same licence gate as the 3D assets (PRD §42, docs/licence-log.md).
 */
enum KnowledgeSourceType: string
{
    case Textbook = 'textbook';
    case Reference = 'reference';
    case Curriculum = 'curriculum';
    case Lesson = 'lesson';

    public function label(): string
    {
        return match ($this) {
            self::Textbook => 'Textbook',
            self::Reference => 'Anatomy reference',
            self::Curriculum => 'Curriculum material',
            self::Lesson => 'Teacher lesson',
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
