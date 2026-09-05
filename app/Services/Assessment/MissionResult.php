<?php

declare(strict_types=1);

namespace App\Services\Assessment;

/**
 * The verdict on one run at a mission, after it has been persisted.
 *
 * Returned only in response to a run the student has already committed to and
 * which is already recorded — as a `mission_attempts` row and as one `attempts`
 * row per step (docs/architecture.md §9). The same property that makes the
 * quiz's reveal safe applies here, and `MissionStepResult` explains how it is
 * preserved when a whole sequence is graded at once.
 *
 * `completed` is the strong claim: **every** step correct. "Reached the last
 * step" is a different and much weaker statement, and mastery and Handover
 * 10's achievements both want this one — a pathway traced with a wrong turn in
 * it was not traced.
 */
final readonly class MissionResult
{
    /**
     * @param  list<MissionStepResult>  $steps
     */
    public function __construct(
        public int $missionAttemptId,
        public int $score,
        public int $maxScore,
        public bool $completed,
        public array $steps,
        /** One templated sentence, built from the outcomes — never by an LLM. */
        public string $feedback,
    ) {}

    public function correctCount(): int
    {
        return count(array_filter(
            $this->steps,
            static fn (MissionStepResult $step): bool => $step->isCorrect(),
        ));
    }

    /**
     * Zero-based index of the first step that went wrong, or null if none did.
     *
     * This is what acceptance criterion 2 asks for — "an out-of-order attempt
     * reports exactly which step went wrong" — and it is also the step whose
     * target `MissionStepResult` reveals.
     */
    public function firstMissedStep(): ?int
    {
        foreach ($this->steps as $step) {
            if (! $step->isCorrect()) {
                return $step->index;
            }
        }

        return null;
    }
}
