<?php

declare(strict_types=1);

namespace App\Services\Progress;

use App\Enums\MasteryFactor;
use App\Enums\RecommendedActivity;
use App\Models\Organ;

/**
 * What to do next, and why (PRD §15, docs/architecture.md §10).
 *
 * `reason` is **templated**, never generated. §10 puts it plainly: the reason
 * is derived from the mastery breakdown and the LLM does not write it. That is
 * not a stylistic preference — a recommendation reason is a claim about a
 * specific student's specific weakness, and a model that hallucinated one
 * would be inventing evidence about their learning.
 *
 * `weakestFactor` travels alongside the sentence so the dashboard can badge it
 * ("coverage") without parsing prose.
 */
final readonly class Recommendation
{
    public function __construct(
        public Organ $organ,
        public RecommendedActivity $activity,
        /** Where the activity lives — a lesson slug, or the organ slug for a quiz. */
        public string $slug,
        public string $title,
        public string $reason,
        public ?MasteryFactor $weakestFactor,
        /** The organ's current mastery, 0–100. */
        public float $score,
    ) {}

    public function href(): string
    {
        return match ($this->activity) {
            RecommendedActivity::Lesson => "/lessons/{$this->slug}",
            RecommendedActivity::Quiz => "/quizzes/{$this->slug}",
        };
    }
}
