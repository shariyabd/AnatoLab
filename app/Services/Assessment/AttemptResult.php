<?php

declare(strict_types=1);

namespace App\Services\Assessment;

/**
 * The verdict on one attempt, after it has been persisted.
 *
 * This is the *one* payload in the application that legitimately carries
 * correctness, and it does so only in response to an answer the student has
 * already committed to and which is already recorded as an attempt
 * (docs/architecture.md §9). Probing for the answer therefore costs a wrong
 * attempt in the student's own mastery record — which is the property that
 * makes revealing it here safe, and which is why the reveal cannot be moved
 * into the question payload "just for the animation".
 *
 * `correctStructureId` is what the viewer flashes green on a miss; it is null
 * unless the question was spatial. `correctOptionId` is its MCQ equivalent.
 */
final readonly class AttemptResult
{
    public function __construct(
        public int $attemptId,
        public bool $isCorrect,
        public ?int $correctStructureId = null,
        public ?int $correctOptionId = null,
        public ?string $explanation = null,
        /**
         * Points of mastery this attempt moved the student's score by.
         *
         * Null until Handover 10 implements `RecalculateMastery` — mastery is
         * recomputed in a queued job after the response has already been sent
         * (docs/architecture.md §10), so a real number is not available at this
         * point in the request even once that lane lands. Null means "not
         * known yet", and the UI shows nothing rather than a zero it would
         * have to explain.
         */
        public ?float $masteryDelta = null,
    ) {}
}
