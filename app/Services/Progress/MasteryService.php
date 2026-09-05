<?php

declare(strict_types=1);

namespace App\Services\Progress;

use App\Enums\TopicType;
use App\Models\AnatomicalStructure;
use App\Models\Attempt;
use App\Models\LearningMastery;
use App\Models\Organ;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns `attempts` into `learning_mastery` rows (docs/architecture.md §10).
 *
 * Everything arithmetic lives in `MasteryCalculator`, which is pure; this
 * class is the part that reads, groups and writes. Splitting them that way is
 * what lets the formula be tested from a table and this be tested from
 * fixtures — and it is why the calculator has no idea `attempts` exists.
 *
 * **Reads `attempts` and never writes them.** The same goes for
 * `lesson_progress`, read by ProgressService. Both tables belong to other
 * lanes (docs/handovers/parallel-execution-plan.md, batch D).
 *
 * Takes the `User` as an argument and never touches `auth()` (invariant 1) —
 * unavoidable here, since the only caller is a queued job.
 *
 * ### Attributing an attempt to a topic
 *
 * One rule, applied to every attempt, with no branch anywhere for where the
 * attempt came from. Handover 09 writes mission steps into this same table and
 * this code does not know that (`do not special-case missions`):
 *
 * 1. If the attempt has a question that names a `correct_structure_id`, the
 *    topic is **that** structure — the thing being tested. Not the structure
 *    the student picked, which on a wrong answer is a different structure
 *    entirely and would credit them with meeting one they were mistaken about.
 * 2. Otherwise, if the attempt names a `selected_structure_id`, that is the
 *    structure the answer was about. This is the branch a mission step takes,
 *    and it is expressed without mentioning missions.
 * 3. Otherwise the attempt belongs to its question's organ and to no structure
 *    — a multiple-choice question about the organ as a whole.
 *
 * An attempt matching none of the three (no question and no structure — a
 * skipped mission step) is still recorded in `attempts`, but there is no topic
 * to attribute it to, so it moves no score.
 *
 * ### Why the whole user, every time
 *
 * `recalculate()` rebuilds every topic for one student rather than only the
 * organ they just answered in. Recency decays with the calendar, so a
 * per-organ recalculation would leave the rest of the dashboard reporting
 * scores computed against an older `now` — visibly inconsistent on the one
 * screen that shows them side by side. The cost is one indexed read of that
 * student's attempts (`attempts.user_id, created_at`), in a queued job, and it
 * makes the whole table self-healing: a lost job is corrected by the next one
 * rather than leaving a permanent gap.
 */
final class MasteryService
{
    public function __construct(private readonly MasteryCalculator $calculator) {}

    /**
     * Rebuild every mastery row for one student.
     *
     * Idempotent: running it twice with the same `$asOf` produces the same
     * rows. That is what makes the queued job safe to retry.
     */
    public function recalculate(User $student, CarbonImmutable $asOf): void
    {
        [$structures, $organGeneral, $organAttempts] = $this->tally($student);

        $organs = $this->organsInPlay(array_keys($organAttempts));

        $rows = [];

        // Structure level. Coverage is 1 by definition — a structure topic
        // contains exactly one structure, and it has been attempted or it
        // would not have a bucket.
        $structureBreakdowns = [];

        foreach ($structures as $structureId => $aggregate) {
            $breakdown = $this->calculator->calculate(
                $aggregate->toInput($asOf, coveredStructures: 1, totalStructures: 1)
            );

            $structureBreakdowns[$structureId] = $breakdown;

            $rows[] = $this->row(
                $student,
                TopicType::Structure,
                $structureId,
                $breakdown,
                $aggregate,
                coveredStructures: 1,
                totalStructures: 1,
            );
        }

        // Organ level: the unweighted mean of the structures beneath it, plus
        // the organ's own structure-less attempts as one further component so
        // a multiple-choice-only organ still scores.
        $organBreakdowns = [];

        foreach ($organAttempts as $organId => $aggregate) {
            $organ = $organs->get($organId);

            if (! $organ instanceof Organ) {
                // The organ was deleted after the attempt was filed. Its
                // attempts cascade away with the questions, so this is a
                // race, not a state: skip rather than write a row keyed to
                // nothing.
                continue;
            }

            $children = [];

            foreach ($aggregate->coveredIds() as $structureId) {
                if (isset($structureBreakdowns[$structureId])) {
                    $children[] = $structureBreakdowns[$structureId];
                }
            }

            if (isset($organGeneral[$organId])) {
                // totalStructures 0 — the general component carries no coverage
                // of its own, and the organ's coverage is applied by rollUp
                // below. See MasteryCalculator's boundary condition 2.
                $children[] = $this->calculator->calculate(
                    $organGeneral[$organId]->toInput($asOf, coveredStructures: 0, totalStructures: 0)
                );
            }

            $total = $this->publishedStructureCount($organ);
            $covered = $aggregate->coveredCount();

            $breakdown = $this->calculator->rollUp(
                $children,
                $total > 0 ? $covered / $total : 1.0,
            );

            $organBreakdowns[$organId] = $breakdown;

            $rows[] = $this->row(
                $student,
                TopicType::Organ,
                $organId,
                $breakdown,
                $aggregate,
                coveredStructures: $covered,
                totalStructures: $total,
            );
        }

        // System level: the unweighted mean of its organs, over the whole
        // system's structure count — including organs never opened, which is
        // what makes "51% on the nervous system" mean what PRD §15 shows.
        foreach ($this->groupBySystem($organs) as $systemId => $systemOrgans) {
            $children = [];
            $aggregate = new TopicAggregate;
            $total = 0;

            foreach ($systemOrgans as $organ) {
                $organId = (int) $organ->getKey();
                $total += $this->publishedStructureCount($organ);

                if (isset($organBreakdowns[$organId])) {
                    $children[] = $organBreakdowns[$organId];
                }

                if (isset($organAttempts[$organId])) {
                    $aggregate->absorb($organAttempts[$organId]);
                }
            }

            if ($children === []) {
                continue;
            }

            $covered = $aggregate->coveredCount();

            $rows[] = $this->row(
                $student,
                TopicType::System,
                $systemId,
                $this->calculator->rollUp($children, $total > 0 ? $covered / $total : 1.0),
                $aggregate,
                coveredStructures: $covered,
                totalStructures: $total,
            );
        }

        $this->persist($student, $rows);
    }

    /**
     * Re-derive a breakdown from a stored row, to explain it.
     *
     * Used only to choose which sentence a recommendation shows
     * (`MasteryBreakdown::weakestFactor()`). It applies the formula to the
     * row's own stored counts, so for a structure row it reproduces the stored
     * score exactly, and for a rolled-up organ or system row it answers a
     * slightly different question — "what do this topic's attempts, taken
     * together, look like" rather than "what is the unweighted mean of its
     * children". That is the right question for a diagnosis ("you have
     * answered 20 and got 9 right") and the wrong one for a score, which is
     * why the stored `mastery_score` is never recomputed from it.
     */
    public function diagnose(LearningMastery $row, CarbonImmutable $asOf): MasteryBreakdown
    {
        $lastActivity = $row->last_activity_at;

        return $this->calculator->calculate(new MasteryInput(
            attempts: $row->attempts,
            correctAttempts: $row->correct_attempts,
            hintedAttempts: $row->hinted_attempts,
            coveredStructures: $row->covered_structures,
            totalStructures: $row->total_structures,
            lastActivityAt: $lastActivity === null ? null : CarbonImmutable::instance($lastActivity),
            asOf: $asOf,
        ));
    }

    /**
     * Walk this student's attempts once, filling three sets of buckets.
     *
     * @return array{
     *     array<int, TopicAggregate>,
     *     array<int, TopicAggregate>,
     *     array<int, TopicAggregate>
     * } structure topics, organ-general components, whole-organ aggregates
     */
    private function tally(User $student): array
    {
        /** @var Collection<int, Attempt> $attempts */
        $attempts = Attempt::query()
            ->forUser($student)
            // Eager loaded because the organ and the answer key are read for
            // every row; lazily, this would be one query per attempt and
            // Model::shouldBeStrict would (correctly) throw.
            ->with('question')
            ->orderBy('id')
            ->get();

        $structureOrgans = $this->organsForStructures($attempts);

        /** @var array<int, TopicAggregate> $structures */
        $structures = [];
        /** @var array<int, TopicAggregate> $organGeneral */
        $organGeneral = [];
        /** @var array<int, TopicAggregate> $organAttempts */
        $organAttempts = [];

        foreach ($attempts as $attempt) {
            // Attribution, in the order the class docblock sets out.
            $structureId = $attempt->question?->correct_structure_id;

            if ($structureId === null) {
                $structureId = $attempt->selected_structure_id;
            }

            $questionOrganId = $attempt->question?->organ_id;

            $organId = $structureId === null
                ? $questionOrganId
                : ($structureOrgans[$structureId] ?? $questionOrganId);

            if ($organId === null) {
                continue;
            }

            $at = $attempt->created_at === null
                ? null
                : CarbonImmutable::instance($attempt->created_at);

            if ($structureId !== null) {
                $structures[$structureId] ??= new TopicAggregate;
                $structures[$structureId]->record($attempt->is_correct, $attempt->hint_used, $at, $structureId);
            } else {
                $organGeneral[$organId] ??= new TopicAggregate;
                $organGeneral[$organId]->record($attempt->is_correct, $attempt->hint_used, $at, null);
            }

            $organAttempts[$organId] ??= new TopicAggregate;
            $organAttempts[$organId]->record($attempt->is_correct, $attempt->hint_used, $at, $structureId);
        }

        return [$structures, $organGeneral, $organAttempts];
    }

    /**
     * structure id → organ id, for every structure these attempts name.
     *
     * Needed for rule 2 of the attribution: an attempt with a structure but no
     * question knows nothing about which organ it belongs to.
     *
     * @param  Collection<int, Attempt>  $attempts
     * @return array<int, int>
     */
    private function organsForStructures(Collection $attempts): array
    {
        $ids = [];

        foreach ($attempts as $attempt) {
            $id = $attempt->question?->correct_structure_id;

            if ($id === null) {
                $id = $attempt->selected_structure_id;
            }

            if ($id !== null) {
                $ids[$id] = true;
            }
        }

        if ($ids === []) {
            return [];
        }

        /** @var array<int, int> $organs */
        $organs = AnatomicalStructure::query()
            ->whereKey(array_keys($ids))
            ->pluck('organ_id', 'id')
            ->map(intval(...))
            ->all();

        return $organs;
    }

    /**
     * Every organ in every body system these attempts touched.
     *
     * Deliberately wider than the organs actually attempted: a system's
     * coverage denominator is the structures in the whole system, so an organ
     * the student has never opened still has to be counted, or a student who
     * has mastered one organ of five would read as having mastered the system.
     *
     * @param  list<int>  $organIds
     * @return Collection<int, Organ>
     */
    private function organsInPlay(array $organIds): Collection
    {
        if ($organIds === []) {
            /** @var Collection<int, Organ> $empty */
            $empty = collect();

            return $empty;
        }

        $systemIds = Organ::query()
            ->whereKey($organIds)
            ->pluck('body_system_id')
            ->unique()
            ->values()
            ->all();

        return Organ::query()
            ->whereIn('body_system_id', $systemIds)
            ->withCount('publishedStructures')
            ->get()
            ->keyBy(static fn (Organ $organ): int => (int) $organ->getKey());
    }

    /**
     * The count `withCount('publishedStructures')` attached.
     *
     * Read through getAttributeValue() rather than as a property: the column
     * exists only on a query that asked for it, and naming it as a property
     * would claim it is always there.
     */
    private function publishedStructureCount(Organ $organ): int
    {
        return (int) $organ->getAttributeValue('published_structures_count');
    }

    /**
     * @param  Collection<int, Organ>  $organs
     * @return array<int, list<Organ>>
     */
    private function groupBySystem(Collection $organs): array
    {
        $grouped = [];

        foreach ($organs as $organ) {
            $grouped[$organ->body_system_id][] = $organ;
        }

        return $grouped;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(
        User $student,
        TopicType $type,
        int $topicId,
        MasteryBreakdown $breakdown,
        TopicAggregate $aggregate,
        int $coveredStructures,
        int $totalStructures,
    ): array {
        return [
            'user_id' => (int) $student->getKey(),
            'topic_type' => $type->value,
            'topic_id' => $topicId,
            'mastery_score' => $breakdown->score,
            'attempts' => $aggregate->attempts,
            'correct_attempts' => $aggregate->correctAttempts,
            'hinted_attempts' => $aggregate->hintedAttempts,
            'covered_structures' => $coveredStructures,
            'total_structures' => $totalStructures,
            'last_activity_at' => $aggregate->lastActivityAt,
        ];
    }

    /**
     * Write the computed rows and drop anything no longer computed.
     *
     * In one transaction, because the dashboard reads all three levels
     * together and a half-applied rebuild would show an organ at 70% inside a
     * system at 0%.
     *
     * The delete matters: an attempt can stop being attributable — its
     * question is retired, its structure is unpublished — and a row nobody
     * recomputes is a score that never moves again.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function persist(User $student, array $rows): void
    {
        DB::transaction(function () use ($student, $rows): void {
            $keep = [];

            foreach ($rows as $row) {
                $record = LearningMastery::query()->updateOrCreate(
                    [
                        'user_id' => $row['user_id'],
                        'topic_type' => $row['topic_type'],
                        'topic_id' => $row['topic_id'],
                    ],
                    $row,
                );

                $keep[] = (int) $record->getKey();
            }

            LearningMastery::query()
                ->forUser($student)
                ->when($keep !== [], static fn ($query) => $query->whereKeyNot($keep))
                ->delete();
        });
    }
}
