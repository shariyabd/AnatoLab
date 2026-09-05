<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use App\Models\AnatomicalStructure;
use App\Models\Mission;
use Illuminate\Support\Collection;

/**
 * A mission that can actually be run: the row, its parsed configuration, and
 * the published structures its steps point at.
 *
 * Exists for the same reason `App\Services\Assessment\Quiz` does — the thing a
 * controller hands to a Resource is not a single model, and assembling it in
 * the Resource would mean the Resource querying. Resolving the targets here
 * also settles, once, the question every consumer would otherwise ask
 * separately: *is this mission runnable at all?*
 *
 * `MissionService` builds one only when every step resolves to a published
 * structure on the mission's own organ, so a `MissionDefinition` in hand is a
 * mission with a reachable right answer for every step. The organ payload
 * ships published structures only, so a step pointing at an unpublished one
 * would be a step with no marker to click — a guaranteed miss, and the exact
 * failure `AssessmentService::answerableQuestions()` filters out on the quiz
 * side.
 *
 * The target map is **not** a client payload. It is the answer key
 * (invariant 4); `App\Http\Resources\Missions\MissionResource` reads
 * `configuration->steps` for prompts and hints and never touches this.
 */
final readonly class MissionDefinition
{
    /**
     * @param  Collection<string, AnatomicalStructure>  $targets  keyed by structure slug
     */
    public function __construct(
        public Mission $mission,
        public MissionConfiguration $configuration,
        public Collection $targets,
    ) {}

    /** The structure step `$index` is asking for, or null if there is no such step. */
    public function target(int $index): ?AnatomicalStructure
    {
        $step = $this->configuration->step($index);

        return $step === null ? null : $this->targets->get($step->targetSlug);
    }

    public function stepCount(): int
    {
        return $this->configuration->stepCount();
    }
}
