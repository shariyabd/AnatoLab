<?php

declare(strict_types=1);

namespace App\Services\Assessment;

/**
 * What the student did on one step, on its way from a FormRequest into the
 * mission scorer.
 *
 * Note what is absent, exactly as on `AttemptData`: nothing about whether it
 * was right. The client states which structure it picked, whether it read the
 * hint, and how long it took. The outcome is derived from the mission's own
 * target sequence, server-side, always (docs/architecture.md §5.4 rule 2).
 *
 * `selectedStructureId` is nullable because a step can be given up on: the UI
 * lets a student move past a step they cannot place, and a skipped step is a
 * miss with no pick rather than an absent step. Position in the submitted list
 * is the step it answers.
 */
final readonly class MissionStepSubmission
{
    public function __construct(
        public ?int $selectedStructureId = null,
        public bool $hintUsed = false,
        public ?int $timeSpentMs = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $step
     */
    public static function fromValidated(array $step): self
    {
        $structureId = $step['selectedStructureId'] ?? null;
        $timeSpentMs = $step['timeSpentMs'] ?? null;

        return new self(
            selectedStructureId: is_numeric($structureId) ? (int) $structureId : null,
            hintUsed: (bool) ($step['hintUsed'] ?? false),
            timeSpentMs: is_numeric($timeSpentMs) ? (int) $timeSpentMs : null,
        );
    }
}
