<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How hard the student wants questions and missions to be.
 *
 * Distinct from EducationLevel: reading level and challenge level are not the
 * same axis. A high-school student can want advanced questions written in
 * plain language.
 */
enum DifficultyPreference: string
{
    case Beginner = 'beginner';
    case Intermediate = 'intermediate';
    case Advanced = 'advanced';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
