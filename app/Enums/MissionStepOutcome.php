<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How one mission step went.
 *
 * Three outcomes rather than a boolean because
 * docs/handovers/09-missions.md scores three of them: a step reached with a
 * hint is right, and is worth less than a step reached without one. Collapsing
 * it to `is_correct` would lose the distinction the scoring table exists to
 * make — and `attempts.hint_used` alone cannot express it, because a hint can
 * be read and the step still missed.
 *
 * The verdict is produced by App\Services\Assessment\MissionService from the
 * mission's own target sequence, server-side, and never from anything the
 * client sends (invariant 4).
 */
enum MissionStepOutcome: string
{
    case Correct = 'correct';
    case CorrectAfterHint = 'correct_after_hint';
    case Wrong = 'wrong';

    /** Whether this step counts as answered correctly, hint or no hint. */
    public function isCorrect(): bool
    {
        return $this !== self::Wrong;
    }

    public function label(): string
    {
        return match ($this) {
            self::Correct => 'Correct',
            self::CorrectAfterHint => 'Correct, after a hint',
            self::Wrong => 'Not this one',
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
