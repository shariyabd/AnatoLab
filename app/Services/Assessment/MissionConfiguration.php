<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use App\Enums\MissionStepOutcome;
use App\Models\Mission;

/**
 * `missions.configuration`, parsed — the steps and the scoring table.
 *
 * The parsing lives here rather than in a model cast because the result is an
 * answer key with behaviour attached, and models in this codebase hold state
 * only (docs/engineering.md §3, App\Models\Mission).
 *
 * Tolerant of a malformed step and intolerant of a malformed mission: a step
 * that names no target is dropped, and a mission left with no steps is one
 * `MissionService` refuses to serve. The alternative — serving it — is a
 * mission a student cannot finish, which is exactly what
 * `AssessmentService::answerableQuestions()` exists to prevent on the quiz
 * side.
 *
 * `type` is read from the `missions.type` column, not from the JSON.
 * docs/architecture.md §9 sketches it inside the configuration and §6 gives it
 * a column; a value in both places is a value that can disagree with itself,
 * and only the column can be indexed or filtered on.
 */
final readonly class MissionConfiguration
{
    /** What a step is worth when nothing is authored (docs/architecture.md §9). */
    private const DEFAULT_CORRECT = 10;

    private const DEFAULT_AFTER_HINT = 6;

    private const DEFAULT_WRONG = 0;

    /**
     * @param  list<MissionStep>  $steps
     */
    public function __construct(
        public array $steps,
        public int $correctPoints = self::DEFAULT_CORRECT,
        public int $afterHintPoints = self::DEFAULT_AFTER_HINT,
        public int $wrongPoints = self::DEFAULT_WRONG,
    ) {}

    public static function fromMission(Mission $mission): self
    {
        $configuration = $mission->configuration;

        /** @var array<array-key, mixed> $rawSteps */
        $rawSteps = is_array($configuration['steps'] ?? null) ? $configuration['steps'] : [];

        $steps = [];

        foreach ($rawSteps as $rawStep) {
            if (! is_array($rawStep)) {
                continue;
            }

            $step = MissionStep::fromArray($rawStep);

            if ($step !== null) {
                $steps[] = $step;
            }
        }

        /** @var array<array-key, mixed> $scoring */
        $scoring = is_array($configuration['scoring'] ?? null) ? $configuration['scoring'] : [];

        return new self(
            steps: $steps,
            correctPoints: self::points($scoring, 'correct', self::DEFAULT_CORRECT),
            afterHintPoints: self::points($scoring, 'after_hint', self::DEFAULT_AFTER_HINT),
            wrongPoints: self::points($scoring, 'wrong', self::DEFAULT_WRONG),
        );
    }

    public function stepCount(): int
    {
        return count($this->steps);
    }

    public function step(int $index): ?MissionStep
    {
        return $this->steps[$index] ?? null;
    }

    /** Every step correct, without a hint — the number a score is read against. */
    public function maxScore(): int
    {
        return $this->correctPoints * $this->stepCount();
    }

    public function pointsFor(MissionStepOutcome $outcome): int
    {
        return match ($outcome) {
            MissionStepOutcome::Correct => $this->correctPoints,
            MissionStepOutcome::CorrectAfterHint => $this->afterHintPoints,
            MissionStepOutcome::Wrong => $this->wrongPoints,
        };
    }

    /**
     * One authored award, floored at zero.
     *
     * `missions.score` is unsigned, and a mission authored with a negative
     * penalty would either fail to insert or wrap. A mission is worth points
     * or it is worth none; it never takes points away
     * (the `mission_attempts` migration says the same from the schema side).
     *
     * @param  array<array-key, mixed>  $scoring
     */
    private static function points(array $scoring, string $key, int $default): int
    {
        $value = $scoring[$key] ?? null;

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return $default;
        }

        return max(0, (int) $value);
    }
}
