<?php

declare(strict_types=1);

namespace App\Services\Progress;

use App\Enums\RecommendedActivity;
use App\Enums\TopicType;
use App\Models\Attempt;
use App\Models\LearningMastery;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Organ;
use App\Models\Question;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Picks the next activity, and the sentence explaining it
 * (docs/architecture.md §10, PRD §15).
 *
 * The rule, in order:
 *
 * 1. **Published topics only.** A draft organ is not a recommendation.
 * 2. **With unattempted content available.** Recommending a quiz the student
 *    has already answered every question of is worse than recommending
 *    nothing: it is a dead end that looks like advice.
 * 3. **Lowest mastery first.** Including topics with no mastery row at all,
 *    which score zero — a system never opened is the weakest thing there is.
 * 4. **Tie-broken toward the current organ**, so a student who is looking at
 *    the heart is offered something about the heart rather than being sent
 *    across the body mid-session.
 *
 * The reason is **templated from the breakdown**, never generated. The LLM
 * writes no part of this (§10, and this handover's guardrails). The factor
 * sentences live on App\Enums\MasteryFactor so that adding a term to the
 * formula does not compile until somebody has written the sentence for it.
 *
 * Takes the User and the current organ as arguments; reads no request state
 * (invariant 1).
 */
final class RecommendationService
{
    public function __construct(private readonly MasteryService $mastery) {}

    /**
     * @param  string|null  $currentOrganSlug  what the student is looking at, if anything
     */
    public function recommend(User $student, CarbonImmutable $asOf, ?string $currentOrganSlug = null): ?Recommendation
    {
        $organs = Organ::query()->published()->orderBy('name')->get();

        if ($organs->isEmpty()) {
            return null;
        }

        $scores = $this->organScores($student);
        $lessons = $this->unfinishedLessons($student);
        $quizzable = $this->organsWithUnattemptedQuestions($student);
        $currentOrganId = $this->currentOrganId($organs, $currentOrganSlug);

        $best = null;
        $bestKey = null;

        foreach ($organs as $organ) {
            $organId = (int) $organ->getKey();

            $activity = match (true) {
                // A lesson before a quiz when both are open: PRD §8 puts the
                // explanation before the assessment, and a student sitting at
                // low mastery needs teaching, not more questions.
                isset($lessons[$organId]) => RecommendedActivity::Lesson,
                in_array($organId, $quizzable, strict: true) => RecommendedActivity::Quiz,
                default => null,
            };

            if ($activity === null) {
                continue;
            }

            $score = $scores[$organId] ?? 0.0;

            // Sorted as a tuple: weakest first, then the current organ ahead of
            // everything level with it, then by name for a stable answer. A
            // recommendation that changes on refresh is a recommendation
            // nobody trusts.
            $key = [$score, $organId === $currentOrganId ? 0 : 1, $organ->name];

            if ($bestKey === null || $key < $bestKey) {
                $bestKey = $key;
                $best = [$organ, $activity, $score];
            }
        }

        if ($best === null) {
            return null;
        }

        [$organ, $activity, $score] = $best;
        $organId = (int) $organ->getKey();

        $lesson = $lessons[$organId] ?? null;

        // One read, two uses: the sentence and the badge next to it describe
        // the same weakness and must never disagree.
        $row = $this->masteryRow($student, $organId);
        $breakdown = $row === null || $row->attempts === 0
            ? null
            : $this->mastery->diagnose($row, $asOf);

        return new Recommendation(
            organ: $organ,
            activity: $activity,
            slug: $activity === RecommendedActivity::Lesson && $lesson instanceof Lesson
                ? $lesson->slug
                : $organ->slug,
            title: $activity === RecommendedActivity::Lesson && $lesson instanceof Lesson
                ? $lesson->title
                : "{$organ->name} quiz",
            reason: $breakdown === null
                ? $this->openingReason($organ->name)
                : $breakdown->weakestFactor()->reason(),
            weakestFactor: $breakdown?->weakestFactor(),
            score: $score,
        );
    }

    /**
     * The sentence for a topic with no evidence yet.
     *
     * There is no weakest factor to name — every factor is at its prior — so
     * the template says the one true thing instead of picking arbitrarily
     * between four sentences that would all be guesses.
     */
    private function openingReason(string $organName): string
    {
        return "You have not answered anything on the {$organName} yet — this is the "
            .'quickest way to find out where you stand.';
    }

    private function masteryRow(User $student, int $organId): ?LearningMastery
    {
        return LearningMastery::query()
            ->forUser($student)
            ->ofType(TopicType::Organ)
            ->where('topic_id', $organId)
            ->first();
    }

    /**
     * organ id → mastery score, for the organs that have one.
     *
     * @return array<int, float>
     */
    private function organScores(User $student): array
    {
        $scores = [];

        foreach (LearningMastery::query()->forUser($student)->ofType(TopicType::Organ)->get() as $row) {
            $scores[$row->topic_id] = $row->score();
        }

        return $scores;
    }

    /**
     * organ id → one published lesson the student has not completed.
     *
     * `lesson_progress` is read and never written (batch D ownership). An
     * in-progress lesson counts as unfinished, which is the point: picking up
     * where they stopped beats starting something new.
     *
     * @return array<int, Lesson>
     */
    private function unfinishedLessons(User $student): array
    {
        $completed = LessonProgress::query()
            ->where('user_id', $student->getKey())
            ->completed()
            ->pluck('lesson_id')
            ->map(intval(...))
            ->all();

        $unfinished = [];

        $lessons = Lesson::query()
            ->published()
            ->when($completed !== [], static fn ($query) => $query->whereNotIn('id', $completed))
            ->orderBy('difficulty')
            ->orderBy('id')
            ->get();

        foreach ($lessons as $lesson) {
            // First wins: the query is ordered easiest-first, so an organ's
            // recommendation is its gentlest remaining lesson.
            $unfinished[$lesson->organ_id] ??= $lesson;
        }

        return $unfinished;
    }

    /**
     * Organs with at least one published question the student has not answered.
     *
     * @return list<int>
     */
    private function organsWithUnattemptedQuestions(User $student): array
    {
        $answered = Attempt::query()
            ->forUser($student)
            ->whereNotNull('question_id')
            ->pluck('question_id')
            ->map(intval(...))
            ->unique()
            ->values()
            ->all();

        return Question::query()
            ->published()
            ->when($answered !== [], static fn ($query) => $query->whereNotIn('id', $answered))
            ->pluck('organ_id')
            ->map(intval(...))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Organ>  $organs
     */
    private function currentOrganId(Collection $organs, ?string $slug): ?int
    {
        if ($slug === null) {
            return null;
        }

        $organ = $organs->first(static fn (Organ $organ): bool => $organ->slug === $slug);

        return $organ instanceof Organ ? (int) $organ->getKey() : null;
    }
}
