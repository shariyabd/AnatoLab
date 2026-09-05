<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use App\Enums\MissionStatus;
use App\Enums\MissionStepOutcome;
use App\Enums\MissionType;
use App\Enums\OrganStatus;
use App\Models\AnatomicalStructure;
use App\Models\Mission;
use App\Models\MissionAttempt;
use App\Models\Organ;
use App\Models\User;
use App\Services\Anatomy\AnatomyService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Reads a mission, and scores a run at one.
 *
 * The rule this class exists to enforce: **the target sequence never leaves the
 * server.** The client is sent prompts and hints; it sends back what was
 * picked; every comparison happens here, against `missions.configuration`
 * (invariant 4, docs/architecture.md §5.4 rule 2, §9). Nothing in
 * `resources/js/` has ever seen a target, which is why there is no client-side
 * validation of order to be tempted by.
 *
 * Takes the User as an argument and reads nothing from the request, the
 * session, or the authenticated-user helper (invariant 1). Spelled without
 * naming those helpers on purpose — /boundary-audit greps app/Services for
 * them, and a prose mention would be a permanent false positive.
 *
 * It does not write `attempts` itself. Every graded step goes through
 * `AssessmentService::recordMissionStep()`, so a mission step and a quiz answer
 * become the same row written by the same code and feed one mastery
 * calculation (docs/architecture.md §9, §10;
 * docs/handovers/parallel-execution-plan.md D5).
 *
 * Nothing here is cached. A mission is one indexed row and its configuration is
 * an answer key; the organ inside it comes through `AnatomyService`, which is
 * where the payload that actually costs something is cached
 * (docs/architecture.md §11).
 */
final class MissionService
{
    public function __construct(
        private readonly AnatomyService $anatomy,
        private readonly AssessmentService $assessment,
    ) {}

    /**
     * Every mission a student can actually run, for the mission picker.
     *
     * Filtered by the same conditions as `findPublishedBySlug`, so a card can
     * never offer a mission that 404s when opened — the point
     * `AssessmentService::listQuizzes()` makes about counting rows that the
     * detail read would then reject.
     *
     * @return Collection<int, MissionDefinition>
     */
    public function listPublished(): Collection
    {
        $missions = Mission::query()
            ->published()
            // whereRelation rather than a whereHas closure calling the
            // scopePublished it mirrors: a closure's builder is untyped at the
            // relation boundary (LessonService makes the same point).
            ->whereRelation('organ', 'status', OrganStatus::Published)
            // publishedStructures twice, on purpose: the count draws the card,
            // and the rows themselves settle whether every step of the mission
            // resolves to something clickable. Both come from one eager load
            // rather than a query per mission (docs/engineering.md §3).
            ->with(['organ' => static function (Relation $organ): void {
                $organ->with(['bodySystem', 'publishedStructures'])
                    ->withCount('publishedStructures');
            }])
            ->orderBy('difficulty')
            ->orderBy('title')
            ->get();

        /** @var Collection<int, MissionDefinition> $definitions */
        $definitions = $missions
            ->map(fn (Mission $mission): ?MissionDefinition => $this->define($mission, $mission->organ))
            ->filter()
            ->values();

        return $definitions;
    }

    /**
     * The runnable mission for a slug, or null.
     *
     * Null for an unknown slug, a draft mission, a mission on a draft organ,
     * a mission with no parseable steps, and a mission whose steps point at
     * structures that are not published — all five. The controller 404s on all
     * of them without distinguishing them, for the reason `AnatomyService`
     * gives: a draft's existence is not something a URL guess should confirm.
     *
     * The organ arrives through `AnatomyService` rather than through the
     * mission's own relation so this endpoint reads the same cached
     * `organ:{slug}` payload the explore and quiz pages already paid for
     * (docs/architecture.md §11).
     */
    public function findPublishedBySlug(string $slug): ?MissionDefinition
    {
        $mission = Mission::query()
            ->published()
            ->where('slug', $slug)
            ->with('organ')
            ->first();

        if (! $mission instanceof Mission) {
            return null;
        }

        $organ = $this->anatomy->findPublishedOrganBySlug($mission->organ->slug);

        if ($organ === null) {
            return null;
        }

        return $this->define($mission, $organ);
    }

    /**
     * Score one run, persist it, and return the per-step feedback.
     *
     * The order of operations matters. The `mission_attempts` row is created
     * first because every `attempts` row needs its id; the steps are written
     * next, through Handover 07's service; and the whole lot is one
     * transaction, because a run recorded as an envelope with no steps would
     * count towards mastery as zero work done and could not be explained
     * afterwards.
     *
     * The response names the right structure for the first missed step only.
     * `MissionStepResult` sets out why that limit exists and why it is the
     * same trade docs/architecture.md §9 already makes for a quiz.
     */
    public function score(User $student, MissionDefinition $definition, MissionAttemptData $data): MissionResult
    {
        $configuration = $definition->configuration;
        $outcomes = $this->grade($definition, $data);

        $score = 0;
        $correct = 0;

        foreach ($outcomes as $outcome) {
            $score += $configuration->pointsFor($outcome->outcome);
            $correct += $outcome->outcome->isCorrect() ? 1 : 0;
        }

        $completed = $correct === $definition->stepCount();
        $reveal = $this->revealFor($definition, $outcomes);

        $attempt = DB::transaction(function () use ($student, $definition, $data, $outcomes, $score, $completed): MissionAttempt {
            $attempt = MissionAttempt::query()->make([
                'mission_id' => $definition->mission->getKey(),
                'duration_ms' => $data->durationMs,
                'result' => $this->resultRecord($definition, $outcomes),
            ]);

            // None of the three is fillable: ownership comes from the passed-in
            // User and the verdict from the grader above, and all three would
            // be forgeable if a request array could reach them
            // (App\Models\MissionAttempt).
            $attempt->user_id = (int) $student->getKey();
            $attempt->score = $score;
            $attempt->completed = $completed;
            $attempt->save();

            foreach ($outcomes as $outcome) {
                $this->assessment->recordMissionStep($student, new MissionStepAttempt(
                    missionAttemptId: (int) $attempt->getKey(),
                    isCorrect: $outcome->outcome->isCorrect(),
                    selectedStructureId: $outcome->picked === null
                        ? null
                        : (int) $outcome->picked->getKey(),
                    hintUsed: $outcome->hintUsed,
                    timeSpentMs: $outcome->timeSpentMs,
                ));
            }

            return $attempt;
        });

        $steps = [];

        foreach ($outcomes as $outcome) {
            $steps[] = new MissionStepResult(
                index: $outcome->index,
                outcome: $outcome->outcome,
                points: $configuration->pointsFor($outcome->outcome),
                prompt: $outcome->step->prompt,
                // The explanation names the target in prose, so it travels with
                // the graded result and never with the mission itself — the same
                // rule `questions.explanation` follows (QuestionResource).
                explanation: $outcome->step->explanation,
                selectedStructureId: $outcome->picked === null ? null : (int) $outcome->picked->getKey(),
                revealedStructureId: $reveal !== null && $reveal['index'] === $outcome->index
                    ? $reveal['structureId']
                    : null,
            );
        }

        return new MissionResult(
            missionAttemptId: (int) $attempt->getKey(),
            score: $score,
            maxScore: $configuration->maxScore(),
            completed: $completed,
            steps: $steps,
            feedback: $this->feedbackFor($definition, $correct, $steps),
        );
    }

    /**
     * A student's own runs at one mission, most recent first.
     *
     * Scoped by the passed-in User and by nothing else, so there is no request
     * field that could reach another student's history
     * (docs/engineering.md §10).
     *
     * @return Collection<int, MissionAttempt>
     */
    public function historyFor(User $student, Mission $mission, int $limit = 5): Collection
    {
        /** @var Collection<int, MissionAttempt> $attempts */
        $attempts = MissionAttempt::query()
            ->forUser($student)
            ->where('mission_id', $mission->getKey())
            ->latest('id')
            ->limit($limit)
            ->get();

        return $attempts;
    }

    /**
     * Assemble a runnable mission, or null if any step has no reachable target.
     *
     * Every target is resolved against this organ's *published* structures.
     * Slugs are unique per organ rather than globally — `apex` means one thing
     * in the heart and another in the lungs — so a global lookup would be a
     * mission that can be completed by clicking the right name on the wrong
     * organ (App\Services\Anatomy\AnatomyService).
     */
    private function define(Mission $mission, Organ $organ): ?MissionDefinition
    {
        $configuration = MissionConfiguration::fromMission($mission);

        if ($configuration->stepCount() === 0) {
            return null;
        }

        /** @var Collection<string, AnatomicalStructure> $available */
        $available = $organ->publishedStructures->keyBy('slug');

        /** @var Collection<string, AnatomicalStructure> $targets */
        $targets = collect();

        foreach ($configuration->steps as $step) {
            $target = $available->get($step->targetSlug);

            // A step with no published structure behind it has no marker to
            // click, so the mission is unwinnable. Refusing to serve it is the
            // same call `AssessmentService::answerableQuestions()` makes about
            // a spatial question whose answer is unpublished.
            if (! $target instanceof AnatomicalStructure) {
                return null;
            }

            $targets->put($step->targetSlug, $target);
        }

        $mission->setRelation('organ', $organ);

        return new MissionDefinition($mission, $configuration, $targets);
    }

    /**
     * Grade every configured step. This is the answer key comparison, and the
     * only one.
     *
     * Two branches, one per `App\Enums\MissionType`:
     *
     * - **trace_pathway** — step *n* is graded against step *n*'s target.
     *   Clicking the right three structures in the wrong order scores the steps
     *   that happened to line up and misses the rest, which is what makes an
     *   out-of-order run report *where* it went wrong rather than just that it
     *   did (acceptance criterion 2).
     * - **identify** — a pick is graded against every target not yet claimed,
     *   left to right. Order carries no meaning in a find-them-all mission, so
     *   penalising it would be scoring the student's reading direction.
     *
     * A step with no configured counterpart in the submission, or one whose
     * pick does not resolve, is a miss with no pick. That covers a run
     * abandoned halfway and a payload naming a structure on another organ
     * alike — neither is an answer, and neither should be stored as one
     * (`AssessmentService::resolveStructure()` makes the same argument).
     *
     * @return list<MissionStepOutcomeRecord>
     */
    private function grade(MissionDefinition $definition, MissionAttemptData $data): array
    {
        $ordered = $definition->mission->type->isOrdered();
        $claimed = [];
        $outcomes = [];

        foreach ($definition->configuration->steps as $index => $step) {
            $submission = $data->step($index);
            $picked = $this->resolvePick($definition, $submission?->selectedStructureId);

            $isCorrect = false;

            if ($picked instanceof AnatomicalStructure) {
                if ($ordered) {
                    $target = $definition->target($index);
                    $isCorrect = $target !== null && $target->getKey() === $picked->getKey();
                } else {
                    $match = $this->claimUnclaimed($definition, $picked, $claimed);

                    if ($match !== null) {
                        $claimed[$match] = true;
                        $isCorrect = true;
                    }
                }
            }

            $hintUsed = $submission !== null && $submission->hintUsed;

            $outcomes[] = new MissionStepOutcomeRecord(
                index: $index,
                step: $step,
                outcome: match (true) {
                    $isCorrect && $hintUsed => MissionStepOutcome::CorrectAfterHint,
                    $isCorrect => MissionStepOutcome::Correct,
                    default => MissionStepOutcome::Wrong,
                },
                picked: $picked,
                hintUsed: $hintUsed,
                timeSpentMs: $submission?->timeSpentMs,
            );
        }

        return $outcomes;
    }

    /**
     * The index of an unclaimed target this pick satisfies, or null.
     *
     * @param  array<int, true>  $claimed
     */
    private function claimUnclaimed(
        MissionDefinition $definition,
        AnatomicalStructure $picked,
        array $claimed,
    ): ?int {
        foreach ($definition->configuration->steps as $index => $_) {
            if (isset($claimed[$index])) {
                continue;
            }

            $target = $definition->target($index);

            if ($target !== null && $target->getKey() === $picked->getKey()) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Only a published structure on this mission's own organ can be a pick.
     *
     * Resolved from the organ payload already in memory rather than with a
     * query per step: the same collection the client was sent is the exact set
     * of things it could legitimately have clicked.
     */
    private function resolvePick(MissionDefinition $definition, ?int $structureId): ?AnatomicalStructure
    {
        if ($structureId === null) {
            return null;
        }

        return $definition->mission->organ->publishedStructures
            ->first(static fn (AnatomicalStructure $structure): bool => (int) $structure->getKey() === $structureId);
    }

    /**
     * Which step, if any, gets its target named in the response.
     *
     * At most one per run — see `MissionStepResult` for why that limit is the
     * whole design rather than a detail.
     *
     * For an ordered mission it is the target of the first missed step: the
     * pathway breaks there, and every step after it is downstream of a mistake
     * the student has not had explained yet. For an unordered one the step
     * index carries no meaning, so it is the first target nothing claimed —
     * one of the structures genuinely still missing.
     *
     * @param  list<MissionStepOutcomeRecord>  $outcomes
     * @return array{index: int, structureId: int}|null
     */
    private function revealFor(MissionDefinition $definition, array $outcomes): ?array
    {
        $missed = null;

        foreach ($outcomes as $outcome) {
            if (! $outcome->outcome->isCorrect()) {
                $missed = $outcome->index;
                break;
            }
        }

        if ($missed === null) {
            return null;
        }

        if ($definition->mission->type === MissionType::TracePathway) {
            $target = $definition->target($missed);

            return $target === null ? null : ['index' => $missed, 'structureId' => (int) $target->getKey()];
        }

        $found = [];

        foreach ($outcomes as $outcome) {
            if ($outcome->outcome->isCorrect() && $outcome->picked !== null) {
                $found[(int) $outcome->picked->getKey()] = true;
            }
        }

        foreach ($definition->configuration->steps as $index => $_) {
            $target = $definition->target($index);

            if ($target !== null && ! isset($found[(int) $target->getKey()])) {
                return ['index' => $missed, 'structureId' => (int) $target->getKey()];
            }
        }

        return null;
    }

    /**
     * One templated sentence about the run.
     *
     * Templated from the outcomes, never generated — the same call
     * docs/architecture.md §10 makes about `RecommendationService`'s reason
     * line. A sentence that summarises a score has to be right every time, and
     * it is cheaper to write it than to validate it.
     *
     * @param  list<MissionStepResult>  $steps
     */
    private function feedbackFor(MissionDefinition $definition, int $correct, array $steps): string
    {
        $total = count($steps);
        $ordered = $definition->mission->type->isOrdered();

        if ($correct === $total) {
            return $ordered
                ? "Perfect trace — all {$total} steps in the right order."
                : "All {$total} found, without a wrong turn.";
        }

        $firstMiss = null;

        foreach ($steps as $step) {
            if (! $step->isCorrect()) {
                $firstMiss = $step->index + 1;
                break;
            }
        }

        $opening = $ordered
            ? "{$correct} of {$total} steps traced correctly."
            : "{$correct} of {$total} found.";

        if ($firstMiss === null) {
            return $opening;
        }

        return $ordered
            ? "{$opening} The pathway breaks at step {$firstMiss}."
            : "{$opening} Step {$firstMiss} is still open.";
    }

    /**
     * The server's own record of the run, stored in `mission_attempts.result`.
     *
     * Deliberately richer than the response: it keeps the target for every
     * step, not just the one that was revealed. That is what lets Handover 13
     * see why students keep failing a mission, and Handover 10 explain a score,
     * without re-deriving either from `attempts`. It is never serialised to a
     * student — the Resource builds the response from `MissionResult`.
     *
     * @param  list<MissionStepOutcomeRecord>  $outcomes
     * @return array<string, mixed>
     */
    private function resultRecord(MissionDefinition $definition, array $outcomes): array
    {
        $steps = [];

        foreach ($outcomes as $outcome) {
            $steps[] = [
                'index' => $outcome->index,
                'target' => $outcome->step->targetSlug,
                'selected_structure_id' => $outcome->picked === null ? null : (int) $outcome->picked->getKey(),
                'outcome' => $outcome->outcome->value,
                'hint_used' => $outcome->hintUsed,
                'points' => $definition->configuration->pointsFor($outcome->outcome),
                'time_spent_ms' => $outcome->timeSpentMs,
            ];
        }

        return [
            'type' => $definition->mission->type->value,
            'steps' => $steps,
        ];
    }

    /*
    |---------------------------------------------------------------------------
    | Administration (Handover 13)
    |---------------------------------------------------------------------------
    |
    | listPublished() and findPublishedBySlug() are the student's view and
    | return a MissionDefinition, which exists partly to keep the target
    | sequence away from the client. The admin editor needs the opposite: the
    | Mission row itself, `configuration` and all, because editing a sequence
    | requires seeing it.
    |
    | That exposure is legitimate exactly once — behind the `admin` middleware
    | and MissionPolicy::viewTargetSequence(), through a Resource that names the
    | ability. Nothing else in the codebase returns a raw configuration
    | (docs/handovers/13-admin-content.md, invariant 4).
    */

    /**
     * Every mission, drafts included.
     *
     * @return LengthAwarePaginator<int, Mission>
     */
    public function paginateAll(int $perPage = 25): LengthAwarePaginator
    {
        return Mission::query()
            ->with('organ')
            ->orderBy('organ_id')
            ->orderBy('title')
            ->paginate($perPage);
    }

    /**
     * One mission by slug regardless of status, as the model rather than a
     * MissionDefinition — the admin edits the stored configuration, not the
     * student-safe projection of it.
     */
    public function findAnyBySlug(string $slug): ?Mission
    {
        return Mission::query()
            ->where('slug', $slug)
            ->with('organ')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Mission
    {
        $mission = new Mission($attributes);
        $mission->save();

        return $mission;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Mission $mission, array $attributes): Mission
    {
        $mission->fill($attributes);
        $mission->save();

        return $mission;
    }

    /**
     * Publish or unpublish a mission.
     *
     * Refuses to publish a mission whose configuration this service could not
     * score. define() is what turns a stored configuration into something
     * runnable, and it returns null when the configuration names a structure
     * the organ does not publish — publishing anyway would put a mission in
     * front of a student that dead-ends on its first step.
     */
    public function setStatus(Mission $mission, MissionStatus $status): Mission
    {
        if ($status === MissionStatus::Published && $this->define($mission, $mission->organ) === null) {
            throw new InvalidArgumentException(
                'This mission cannot be published: its configuration does not resolve against the organ\'s published structures.',
            );
        }

        $mission->status = $status;
        $mission->save();

        return $mission;
    }

    public function delete(Mission $mission): void
    {
        $mission->delete();
    }
}
