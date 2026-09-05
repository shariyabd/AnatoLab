<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use App\Enums\MissionStepOutcome;

/**
 * How one step of one run was graded.
 *
 * `revealedStructureId` is the only answer-key-shaped field on this object,
 * and it is set on **at most one step per run**: the first one that was wrong.
 * That limit is the whole design, so it is worth stating plainly.
 *
 * docs/architecture.md §9 permits a graded response to name the right answer —
 * that is what lets the viewer flash the correct marker green on a miss — and
 * `AttemptResult` explains why it is safe there: probing for the answer costs
 * a recorded wrong attempt in the student's own mastery record. A mission
 * submits every step in one request, so revealing *every* target would sell
 * the entire sequence for a single throwaway run, and the student could then
 * replay it perfectly. Revealing only the first miss restores the quiz's
 * economics exactly — one recorded attempt buys at most one target — and it is
 * also where a traced pathway actually breaks: every step after the first
 * wrong turn is downstream of a mistake the student has not yet had explained.
 *
 * Every other field is feedback the student has earned by committing to an
 * answer: which steps were right, which were not, and the authored
 * `explanation` for each. Handover 13's admin Resources may expose the full
 * target sequence; nothing a student can reach ever does.
 */
final readonly class MissionStepResult
{
    public function __construct(
        /** Zero-based position in the mission's configured steps. */
        public int $index,
        public MissionStepOutcome $outcome,
        public int $points,
        public string $prompt,
        /** Authored, and it names the target in prose — post-grading only. */
        public ?string $explanation = null,
        /** What the student picked, resolved; null if they skipped the step. */
        public ?int $selectedStructureId = null,
        /** Set on the first missed step only. See the class docblock. */
        public ?int $revealedStructureId = null,
    ) {}

    public function isCorrect(): bool
    {
        return $this->outcome->isCorrect();
    }
}
