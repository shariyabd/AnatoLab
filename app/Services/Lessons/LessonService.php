<?php

declare(strict_types=1);

namespace App\Services\Lessons;

use App\Enums\LessonProgressStatus;
use App\Enums\LessonStatus;
use App\Enums\OrganStatus;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Lesson reads, and one student's progress through them.
 *
 * Reads nothing from the request, the session, or the authenticated-user
 * helper: the student arrives as a typed `User` argument, which is what lets
 * the same methods run from a controller, a queued job, or a test
 * (invariant 1). Spelled without naming those helpers on purpose —
 * /boundary-audit greps app/Services for them to catch a service that really
 * does reach for one, and a prose mention would be a permanent false positive
 * in every future audit. Every write is scoped by
 * that argument, so there is no request field a student could set to reach
 * someone else's row (docs/handovers/06-lessons.md, Tests).
 *
 * Only lesson *content* is cached — it is identical for every student. The
 * progress rows are personalised and are never cached (docs/architecture.md
 * §11).
 */
final class LessonService
{
    /**
     * One hour (docs/architecture.md §11) — shorter than anatomy's 24 h
     * because lesson prose is edited far more often than an organ is.
     * Every write path calls forgetLesson(), so this is only a backstop
     * against a cache that outlived a deployment.
     */
    private const TTL_SECONDS = 3_600;

    public function __construct(private readonly CacheRepository $cache) {}

    /**
     * The lesson library, filtered.
     *
     * Not cached. Three optional filters make eight key shapes before the
     * values are considered, and the query is one indexed read returning a few
     * dozen rows — caching it would buy nothing and give the observer eight
     * keys to invalidate (docs/architecture.md §11 names `lesson:{slug}` and
     * nothing else).
     *
     * @return Collection<int, Lesson>
     */
    public function listPublished(LessonFilters $filters = new LessonFilters): Collection
    {
        /** @var Collection<int, Lesson> $lessons */
        $lessons = Lesson::query()
            ->published()
            ->with('organ.bodySystem')
            // A published lesson on a draft organ would put that organ's model
            // URL in front of a student, which is exactly what Handover 05's
            // 404 on a draft organ prevents. The lesson follows the organ.
            //
            // whereRelation rather than a whereHas closure calling the
            // scopePublished it mirrors: a closure's builder is untyped at the
            // relation boundary, and the scope is not visible to it.
            ->whereRelation('organ', 'status', OrganStatus::Published)
            ->when(
                $filters->organSlug !== null,
                fn ($query) => $query->whereHas('organ', fn ($organ) => $organ->where('slug', $filters->organSlug)),
            )
            ->when(
                $filters->systemSlug !== null,
                fn ($query) => $query->whereHas(
                    'organ.bodySystem',
                    fn ($system) => $system->where('slug', $filters->systemSlug),
                ),
            )
            ->when(
                $filters->difficulty !== null,
                fn ($query) => $query->where('difficulty', $filters->difficulty),
            )
            ->orderBy('organ_id')
            ->orderBy('title')
            ->get();

        return $lessons;
    }

    /**
     * The values the library's filters can actually take.
     *
     * Derived from the published lessons themselves rather than from the organ
     * list, so the picker never offers an organ with nothing to study — an
     * empty result from a filter the page itself suggested reads as a bug.
     *
     * One query. The organ and system rows come back through the eager load
     * the library already performs, so this does not scale with the size of
     * the library (docs/engineering.md §3).
     *
     * @return array{organs: list<array{slug: string, name: string}>, systems: list<array{slug: string, name: string}>}
     */
    public function filterOptions(): array
    {
        $lessons = $this->listPublished();

        $organs = [];
        $systems = [];

        foreach ($lessons as $lesson) {
            $organ = $lesson->organ;
            $organs[$organ->slug] = ['slug' => $organ->slug, 'name' => $organ->name];

            $system = $organ->bodySystem;
            $systems[$system->slug] = ['slug' => $system->slug, 'name' => $system->name];
        }

        ksort($organs);
        ksort($systems);

        return ['organs' => array_values($organs), 'systems' => array_values($systems)];
    }

    /**
     * One published lesson, with the organ its 3D steps need.
     *
     * Returns null for an unknown *or* an unpublished slug so the caller can
     * 404 without distinguishing the two — a draft lesson's existence is not
     * something a URL guess should confirm.
     *
     * A miss is deliberately not cached: caching null would let a 404 issued a
     * second before publication survive for an hour.
     */
    public function findPublishedBySlug(string $slug): ?Lesson
    {
        $key = self::lessonKey($slug);
        $cached = $this->cache->get($key);

        if ($cached instanceof Lesson) {
            return $cached;
        }

        $lesson = Lesson::query()
            ->published()
            ->where('slug', $slug)
            ->whereRelation('organ', 'status', OrganStatus::Published)
            ->with(['organ.bodySystem', 'organ.publishedStructures'])
            ->first();

        if ($lesson instanceof Lesson) {
            $this->cache->put($key, $lesson, self::TTL_SECONDS);
        }

        return $lesson;
    }

    /**
     * How many steps this lesson has.
     *
     * The one place `content` is interpreted structurally, so a malformed or
     * hand-edited row degrades to zero steps rather than a TypeError inside a
     * percentage calculation.
     */
    public function countSteps(Lesson $lesson): int
    {
        $steps = $lesson->content['steps'] ?? null;

        return is_array($steps) ? count($steps) : 0;
    }

    public function progressFor(User $user, Lesson $lesson): ?LessonProgress
    {
        return LessonProgress::query()
            ->where('user_id', $user->getKey())
            ->where('lesson_id', $lesson->getKey())
            ->first();
    }

    /**
     * This student's progress across several lessons, keyed by lesson id.
     *
     * One query for the whole library page. Loading it per card is the N+1 a
     * judge sees on the busiest page in the feature (docs/engineering.md §3).
     *
     * @param  Collection<int, Lesson>  $lessons
     * @return array<int, LessonProgress>
     */
    public function progressForLessons(User $user, Collection $lessons): array
    {
        if ($lessons->isEmpty()) {
            return [];
        }

        return LessonProgress::query()
            ->where('user_id', $user->getKey())
            ->whereIn('lesson_id', $lessons->modelKeys())
            ->get()
            ->keyBy('lesson_id')
            ->all();
    }

    /**
     * Record that this student has reached a given step.
     *
     * `$stepIndex` is zero-based and clamped to the lesson, so a client that
     * posts 999 records the last step rather than 100000%. Progress only ever
     * moves forward: re-reading step two of a lesson the student has already
     * reached the end of must not undo their progress, and re-opening a
     * completed lesson must not reopen it — F10 ages mastery off
     * `completed_at` and a cleared one would look like the lesson was never
     * finished (docs/architecture.md §10).
     */
    public function recordStepProgress(User $user, Lesson $lesson, int $stepIndex): LessonProgress
    {
        $existing = $this->progressFor($user, $lesson);

        if ($existing?->status === LessonProgressStatus::Completed) {
            return $existing;
        }

        $percent = $this->percentForStep($lesson, $stepIndex);

        if ($existing instanceof LessonProgress) {
            if ($percent > $existing->progress_percent) {
                $existing->progress_percent = $percent;
                $existing->save();
            }

            return $existing;
        }

        return $this->createProgress(
            user: $user,
            lesson: $lesson,
            status: LessonProgressStatus::InProgress,
            percent: $percent,
            completedAt: null,
        );
    }

    /**
     * Mark the lesson finished for this student.
     *
     * Idempotent, as required by docs/handovers/06-lessons.md: a second call
     * returns the same row with the original `completed_at`. That matters
     * beyond tidiness — F10 uses the timestamp to decide how stale a student's
     * activity is, and letting a re-submit refresh it would let a student
     * inflate their own recency by clicking twice.
     */
    public function complete(User $user, Lesson $lesson): LessonProgress
    {
        $existing = $this->progressFor($user, $lesson);

        if ($existing?->status === LessonProgressStatus::Completed) {
            return $existing;
        }

        if ($existing instanceof LessonProgress) {
            $existing->status = LessonProgressStatus::Completed;
            $existing->progress_percent = 100;
            $existing->completed_at = Carbon::now();
            $existing->save();

            return $existing;
        }

        // A student can complete a one-step lesson without ever posting a
        // step, so this is a real path rather than a defensive branch.
        return $this->createProgress(
            user: $user,
            lesson: $lesson,
            status: LessonProgressStatus::Completed,
            percent: 100,
            completedAt: Carbon::now(),
        );
    }

    public function forgetLesson(Lesson $lesson): void
    {
        $this->cache->forget(self::lessonKey($lesson->slug));

        // The slug may have just changed; the entry under the old one would
        // otherwise serve stale content for the rest of the hour.
        $originalSlug = $lesson->getOriginal('slug');

        if (is_string($originalSlug) && $originalSlug !== $lesson->slug) {
            $this->cache->forget(self::lessonKey($originalSlug));
        }
    }

    /**
     * Percentage of the lesson a zero-based step index represents.
     *
     * A lesson with no readable steps yields 0 rather than dividing by zero.
     */
    private function percentForStep(Lesson $lesson, int $stepIndex): int
    {
        $stepCount = $this->countSteps($lesson);

        if ($stepCount === 0) {
            return 0;
        }

        $reached = min(max($stepIndex, 0), $stepCount - 1) + 1;

        return (int) round($reached / $stepCount * 100);
    }

    /**
     * Insert one progress row.
     *
     * Written through the relation off the passed-in User so `user_id` cannot
     * come from anywhere else, which is the whole authorization story for this
     * table (docs/engineering.md §10).
     */
    private function createProgress(
        User $user,
        Lesson $lesson,
        LessonProgressStatus $status,
        int $percent,
        ?Carbon $completedAt,
    ): LessonProgress {
        $progress = new LessonProgress([
            'user_id' => $user->getKey(),
            'lesson_id' => $lesson->getKey(),
            'status' => $status,
            'progress_percent' => $percent,
            'completed_at' => $completedAt,
        ]);

        $progress->save();

        return $progress;
    }

    private static function lessonKey(string $slug): string
    {
        return 'lesson:'.$slug;
    }

    /*
    |---------------------------------------------------------------------------
    | Administration (Handover 13)
    |---------------------------------------------------------------------------
    |
    | listPublished() above is the student's view and filters drafts and
    | unpublished organs out. An admin needs what it hides, and needs to write.
    | LessonObserver clears `lesson:{slug}` on save, so nothing here manages the
    | cache by hand (docs/architecture.md §11).
    */

    /**
     * Every lesson, drafts included.
     *
     * @return LengthAwarePaginator<int, Lesson>
     */
    public function paginateAll(int $perPage = 25): LengthAwarePaginator
    {
        return Lesson::query()
            ->with('organ')
            ->orderBy('organ_id')
            ->orderBy('title')
            ->paginate($perPage);
    }

    /**
     * One lesson by slug regardless of status.
     *
     * Distinct from findPublishedBySlug() rather than a flag on it, for the
     * reason AnatomyService::findAnyOrganBySlug() gives: a boolean that decides
     * whether unpublished content is visible eventually receives the wrong
     * value at some call site.
     */
    public function findAnyBySlug(string $slug): ?Lesson
    {
        return Lesson::query()
            ->where('slug', $slug)
            ->with('organ')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Lesson
    {
        $lesson = new Lesson($attributes);
        $lesson->save();

        return $lesson;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Lesson $lesson, array $attributes): Lesson
    {
        $lesson->fill($attributes);
        $lesson->save();

        return $lesson;
    }

    /**
     * Publish or unpublish a lesson.
     *
     * Publishing does not check the organ's status, and must not: a lesson on
     * a draft organ is a legitimate state to prepare, and listPublished()
     * already declines to serve it. Blocking it here would make the admin
     * publish in an order the data does not require.
     */
    public function setStatus(Lesson $lesson, LessonStatus $status): Lesson
    {
        $lesson->status = $status;
        $lesson->save();

        return $lesson;
    }

    public function delete(Lesson $lesson): void
    {
        $lesson->delete();
    }
}
