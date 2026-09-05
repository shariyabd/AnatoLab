<?php

declare(strict_types=1);

namespace App\Services\Progress;

use App\Enums\MasteryFactor;

/**
 * A mastery score, and the four factors that produced it.
 *
 * The factors are not diagnostics — they are the feature. PRD §15's output is
 * a sentence explaining *why* a topic is weak ("you are strong in organ
 * functions but need more practice identifying structures"), and
 * docs/architecture.md §10 requires that sentence to be templated from the
 * mastery breakdown rather than written by the LLM. `weakestFactor()` is what
 * `RecommendationService` templates against.
 *
 * Every factor is in [0, 1] and multiplies into the score, so they are
 * directly comparable: the smallest one is the one costing the student the
 * most.
 */
final readonly class MasteryBreakdown
{
    public function __construct(
        /** Laplace-smoothed correctness, in [0, 1]. 0.5 with no evidence. */
        public float $accuracy,
        /** Exponential decay on last activity, in (0, 1]. 1.0 means today. */
        public float $recency,
        /** 1 − 0.15 × hint rate, so in [0.85, 1]. */
        public float $hintPenalty,
        /** Distinct structures attempted over structures in the topic, in [0, 1]. */
        public float $coverage,
        /** 0.00–100.00, rounded to the precision `learning_mastery` stores. */
        public float $score,
    ) {}

    /**
     * The factor dragging this score down hardest.
     *
     * Coverage is compared as the term that actually enters the formula —
     * `0.5 + 0.5 × coverage` — and not as the raw ratio. Raw coverage of 0
     * would otherwise always win this comparison, when its real cost is a
     * halving, which a 40% accuracy beats.
     */
    public function weakestFactor(): MasteryFactor
    {
        $candidates = [
            MasteryFactor::Accuracy->value => $this->accuracy,
            MasteryFactor::Recency->value => $this->recency,
            MasteryFactor::HintReliance->value => $this->hintPenalty,
            MasteryFactor::Coverage->value => 0.5 + 0.5 * $this->coverage,
        ];

        return MasteryFactor::from((string) array_search(min($candidates), $candidates, strict: true));
    }
}
