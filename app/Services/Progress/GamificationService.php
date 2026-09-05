<?php

declare(strict_types=1);

namespace App\Services\Progress;

use App\Enums\AchievementCriterion;
use App\Enums\LearningEventType;
use App\Enums\TopicType;
use App\Models\Achievement;
use App\Models\Attempt;
use App\Models\BodySystem;
use App\Models\LearningEvent;
use App\Models\LearningMastery;
use App\Models\LessonProgress;
use App\Models\Organ;
use App\Models\User;
use App\Models\UserAchievement;
use Carbon\CarbonImmutable;

/**
 * XP, levels, badges and streaks (PRD §16).
 *
 * Reinforcement, not competition: there is no leaderboard here and no method
 * that compares one student to another, which PRD §16 asks for explicitly.
 * Everything is a statement about one student's own history.
 *
 * **Everything is recomputed, nothing is incremented.** XP is derived from the
 * ledger — correct answers, completed lessons, badges held — every time the
 * job runs, so a retried job, a double dispatch, or a job that ran twice
 * because a queue worker died mid-ack cannot inflate a score. An `xp += 10`
 * anywhere in this class would make all three of those a bug that is invisible
 * until a student notices they are level 40.
 *
 * Badges are awarded through UNIQUE(user_id, achievement_id) rather than by
 * checking first: the criteria are re-evaluated on every run and the insert
 * either lands once or not at all.
 *
 * Takes the `User` as an argument and reads no request state (invariant 1).
 */
final class GamificationService
{
    /** A correct answer, unaided. */
    public const XP_PER_CORRECT = 10;

    /** A correct answer with the hint open. Still progress, worth less. */
    public const XP_PER_CORRECT_WITH_HINT = 5;

    public const XP_PER_LESSON = 50;

    public const XP_PER_ACHIEVEMENT = 25;

    /**
     * Level `n` begins at `100 × (n − 1)²` XP, so each level costs more than
     * the last: level 2 at 100, level 5 at 1,600, level 10 at 8,100. A linear
     * curve would have a diligent student at level 90 by the end of a term,
     * which says nothing.
     */
    public const XP_PER_LEVEL_BASE = 100;

    /**
     * How far back a streak is counted.
     *
     * A bound on the read, not a cap on the achievement: the log is unbounded
     * and a streak is displayed as a small number of days. Nothing in PRD §16
     * distinguishes a 90-day streak from a 60-day one.
     */
    public const STREAK_WINDOW_DAYS = 90;

    /**
     * Award anything newly earned, then recompute XP and level.
     *
     * In that order: a badge is worth XP, so awarding after the recompute
     * would leave the student a level behind until the next attempt.
     */
    public function sync(User $student, CarbonImmutable $asOf): GamificationSummary
    {
        $newlyEarned = $this->awardAchievements($student, $asOf);

        $xp = $this->calculateXp($student);
        $level = $this->levelFor($xp);

        // Assigned rather than mass-assigned: neither column is fillable on
        // App\Models\User, and both are derived here from data the student
        // cannot assert about themselves.
        $student->xp = $xp;
        $student->level = $level;
        $student->save();

        return $this->summarise($student, $asOf, $newlyEarned);
    }

    /**
     * Read the current state without changing it — the dashboard's path.
     *
     * @param  list<Achievement>  $newlyEarned
     */
    public function summarise(User $student, CarbonImmutable $asOf, array $newlyEarned = []): GamificationSummary
    {
        $xp = $student->xp;
        $level = $this->levelFor($xp);

        /** @var list<Achievement> $earned */
        $earned = UserAchievement::query()
            ->forUser($student)
            ->with('achievement')
            ->orderByDesc('earned_at')
            ->get()
            ->map(static fn (UserAchievement $row): Achievement => $row->achievement)
            ->all();

        return new GamificationSummary(
            xp: $xp,
            level: $level,
            xpIntoLevel: $xp - $this->xpAtLevel($level),
            xpForLevel: $this->xpAtLevel($level + 1) - $this->xpAtLevel($level),
            streakDays: $this->streakDays($student, $asOf),
            earned: $earned,
            newlyEarned: $newlyEarned,
        );
    }

    /**
     * Consecutive days with at least one recorded event, ending today.
     *
     * "Ending today or yesterday": a student who worked yesterday and has not
     * opened the app yet today still has their streak. Breaking it at midnight
     * would punish a timezone rather than a lapse.
     */
    public function streakDays(User $student, CarbonImmutable $asOf): int
    {
        $days = LearningEvent::query()
            ->forUser($student)
            ->where('occurred_at', '>=', $asOf->subDays(self::STREAK_WINDOW_DAYS))
            ->orderByDesc('occurred_at')
            ->pluck('occurred_at')
            ->map(static fn (mixed $at): string => CarbonImmutable::parse((string) $at)->toDateString())
            ->unique()
            ->values()
            ->all();

        if ($days === []) {
            return 0;
        }

        $today = $asOf->toDateString();
        $yesterday = $asOf->subDay()->toDateString();

        if ($days[0] !== $today && $days[0] !== $yesterday) {
            return 0;
        }

        $streak = 1;
        $cursor = CarbonImmutable::parse($days[0]);

        foreach (array_slice($days, 1) as $day) {
            if ($day !== $cursor->subDay()->toDateString()) {
                break;
            }

            $streak++;
            $cursor = $cursor->subDay();
        }

        return $streak;
    }

    /**
     * The whole XP ledger, recomputed from scratch. See the class docblock.
     */
    public function calculateXp(User $student): int
    {
        $unaided = Attempt::query()
            ->forUser($student)
            ->where('is_correct', true)
            ->where('hint_used', false)
            ->count();

        $hinted = Attempt::query()
            ->forUser($student)
            ->where('is_correct', true)
            ->where('hint_used', true)
            ->count();

        // `lesson_progress` is read, never written (batch D ownership).
        $lessons = LessonProgress::query()
            ->where('user_id', $student->getKey())
            ->completed()
            ->count();

        $badges = UserAchievement::query()->forUser($student)->count();

        return $unaided * self::XP_PER_CORRECT
            + $hinted * self::XP_PER_CORRECT_WITH_HINT
            + $lessons * self::XP_PER_LESSON
            + $badges * self::XP_PER_ACHIEVEMENT;
    }

    public function levelFor(int $xp): int
    {
        return (int) floor(sqrt(max(0, $xp) / self::XP_PER_LEVEL_BASE)) + 1;
    }

    /** Total XP at which `$level` begins. */
    public function xpAtLevel(int $level): int
    {
        return self::XP_PER_LEVEL_BASE * (max(1, $level) - 1) ** 2;
    }

    /**
     * Evaluate every criterion and insert what is newly met.
     *
     * @return list<Achievement>
     */
    private function awardAchievements(User $student, CarbonImmutable $asOf): array
    {
        $catalogue = Achievement::query()->orderBy('id')->get();

        if ($catalogue->isEmpty()) {
            return [];
        }

        $held = UserAchievement::query()
            ->forUser($student)
            ->pluck('achievement_id')
            ->all();

        $ledger = new AchievementLedger(
            masteryByTopic: $this->masteryIndex($student),
            structuresIdentified: LearningMastery::query()
                ->forUser($student)
                ->ofType(TopicType::Structure)
                ->where('correct_attempts', '>', 0)
                ->count(),
            lessonsCompleted: LessonProgress::query()
                ->where('user_id', $student->getKey())
                ->completed()
                ->count(),
            exploredOrganIds: $this->exploredOrganIds($student),
            streakDays: $this->streakDays($student, $asOf),
        );

        $awarded = [];

        foreach ($catalogue as $achievement) {
            if (in_array((int) $achievement->getKey(), array_map(intval(...), $held), strict: true)) {
                continue;
            }

            if (! $this->isEarned($achievement, $ledger)) {
                continue;
            }

            UserAchievement::query()->create([
                'user_id' => (int) $student->getKey(),
                'achievement_id' => (int) $achievement->getKey(),
                'earned_at' => $asOf,
            ]);

            $awarded[] = $achievement;
        }

        return $awarded;
    }

    /**
     * An unrecognised criterion is never earned, and never throws.
     *
     * Handover 13 will let an admin edit `criteria` as JSON. A typo there must
     * cost a badge nobody can win, not a 500 on every student's dashboard.
     */
    private function isEarned(Achievement $achievement, AchievementLedger $ledger): bool
    {
        $criteria = $achievement->criteria;
        $criterion = AchievementCriterion::tryFromCriteria($criteria);

        if ($criterion === null) {
            return false;
        }

        return match ($criterion) {
            AchievementCriterion::MasteryAtLeast => $this->masteryMet($criteria, $ledger),
            AchievementCriterion::OrganExplored => $this->organExplored($criteria, $ledger),
            AchievementCriterion::StructuresIdentified => $ledger->structuresIdentified
                >= $this->intFrom($criteria, 'count'),
            AchievementCriterion::LessonsCompleted => $ledger->lessonsCompleted
                >= $this->intFrom($criteria, 'count'),
            AchievementCriterion::StreakDays => $ledger->streakDays >= $this->intFrom($criteria, 'days'),
        };
    }

    /**
     * @param  array<string, mixed>  $criteria
     */
    private function masteryMet(array $criteria, AchievementLedger $ledger): bool
    {
        $type = is_string($criteria['topic_type'] ?? null)
            ? TopicType::tryFrom($criteria['topic_type'])
            : null;

        $slug = is_string($criteria['slug'] ?? null) ? $criteria['slug'] : null;

        // Structure slugs are unique per organ, not globally
        // (database/seeders/AnatomySeeder.php), so they cannot address a topic
        // on their own. A structure-scoped badge would need an organ too, and
        // no seeded badge asks for one.
        if ($slug === null || ! in_array($type, [TopicType::Organ, TopicType::System], strict: true)) {
            return false;
        }

        $topicId = $type === TopicType::Organ
            ? Organ::query()->where('slug', $slug)->value('id')
            : BodySystem::query()->where('slug', $slug)->value('id');

        if ($topicId === null) {
            return false;
        }

        $score = $ledger->masteryByTopic["{$type->value}:{$topicId}"] ?? 0.0;

        return $score >= $this->floatFrom($criteria, 'score');
    }

    /**
     * @param  array<string, mixed>  $criteria
     */
    private function organExplored(array $criteria, AchievementLedger $ledger): bool
    {
        $slug = is_string($criteria['slug'] ?? null) ? $criteria['slug'] : null;

        if ($slug === null) {
            return false;
        }

        $organId = Organ::query()->where('slug', $slug)->value('id');

        return $organId !== null && in_array((int) $organId, $ledger->exploredOrganIds, strict: true);
    }

    /**
     * "topic_type:topic_id" → score, so a criterion is one array lookup.
     *
     * @return array<string, float>
     */
    private function masteryIndex(User $student): array
    {
        $index = [];

        foreach (LearningMastery::query()->forUser($student)->get() as $row) {
            $index["{$row->topic_type->value}:{$row->topic_id}"] = $row->score();
        }

        return $index;
    }

    /**
     * @return list<int>
     */
    private function exploredOrganIds(User $student): array
    {
        return LearningEvent::query()
            ->forUser($student)
            ->ofType(LearningEventType::OrganViewed)
            ->where('context_type', 'organ')
            ->whereNotNull('context_id')
            ->pluck('context_id')
            ->map(intval(...))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $criteria
     */
    private function intFrom(array $criteria, string $key): int
    {
        $value = $criteria[$key] ?? null;

        return is_numeric($value) ? (int) $value : PHP_INT_MAX;
    }

    /**
     * @param  array<string, mixed>  $criteria
     */
    private function floatFrom(array $criteria, string $key): float
    {
        $value = $criteria[$key] ?? null;

        // Unreachably high rather than zero when the key is missing or
        // malformed: a broken criterion must fail closed, or an admin typo
        // would hand the badge to everybody.
        return is_numeric($value) ? (float) $value : INF;
    }
}
