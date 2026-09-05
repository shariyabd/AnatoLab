<?php

declare(strict_types=1);

namespace App\Services\Assessment;

/**
 * One graded mission step, on its way into Handover 07's `attempts` table.
 *
 * The input to `AssessmentService::recordMissionStep()`. It lives in this
 * namespace rather than with the mission scorer because it is that method's
 * argument, and typing it the other way round would make Handover 07's service
 * depend on Handover 09's.
 *
 * `isCorrect` arrives already decided, which is the one place this differs
 * from `AttemptData` — and the difference is not a hole in invariant 4.
 * `AssessmentService` grades a *question* from the question's own answer key
 * because that key is `questions.correct_structure_id`. A mission step's key is
 * the target sequence in `missions.configuration`, which `MissionService`
 * owns; asking `AssessmentService` to parse a mission configuration would put
 * one answer key in two services. The verdict is still derived server-side
 * from authored content, and still from nothing the client sent
 * (docs/architecture.md §5.4 rule 2).
 *
 * `questionId` is absent on purpose. A mission step has no question row — that
 * is why `attempts.question_id` is nullable
 * (docs/handovers/07-assessment-engine.md, DB changes).
 */
final readonly class MissionStepAttempt
{
    public function __construct(
        /** The `mission_attempts` row this step belongs to. */
        public int $missionAttemptId,
        public bool $isCorrect,
        /** Resolved and validated by the caller; null when the step was skipped. */
        public ?int $selectedStructureId = null,
        public bool $hintUsed = false,
        public ?int $timeSpentMs = null,
    ) {}
}
