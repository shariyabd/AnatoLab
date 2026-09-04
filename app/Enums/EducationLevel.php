<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Reading level for lesson copy and AI tutor answers (PRD §11).
 *
 * Also a retrieval filter: knowledge chunks are indexed per level so a
 * 13-year-old and a pre-med student get different source material rather than
 * the same passage rephrased (PRD §12, docs/architecture.md §8).
 */
enum EducationLevel: string
{
    case MiddleSchool = 'middle_school';
    case HighSchool = 'high_school';
    case Advanced = 'advanced';

    public function label(): string
    {
        return match ($this) {
            self::MiddleSchool => 'Middle school',
            self::HighSchool => 'High school',
            self::Advanced => 'Advanced',
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
