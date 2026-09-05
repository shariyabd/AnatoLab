<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The three levels mastery is tracked at (docs/architecture.md §6, §10).
 *
 * Ordered from finest to coarsest, which is also the order the rollup runs in:
 * structure scores roll up into an organ, organ scores into a body system.
 * `rollsUpInto()` names that edge once, so the job does not restate the
 * hierarchy and a fourth level would be a change in one place.
 *
 * The values are the strings stored in `learning_mastery.topic_type` and sent
 * to the client, so they are part of the API contract — mirrored in
 * resources/js/types/progress.ts.
 */
enum TopicType: string
{
    case Structure = 'structure';
    case Organ = 'organ';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Structure => 'Structure',
            self::Organ => 'Organ',
            self::System => 'Body system',
        };
    }

    /**
     * The level this one aggregates into, or null at the top.
     */
    public function rollsUpInto(): ?self
    {
        return match ($this) {
            self::Structure => self::Organ,
            self::Organ => self::System,
            self::System => null,
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
