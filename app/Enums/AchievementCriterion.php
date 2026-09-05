<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The closed set of rules `achievements.criteria` may express (PRD §16).
 *
 * Badges are content — a row in `achievements` — but the rules they are
 * evaluated against are code, and this enum is the boundary between the two.
 * A seeded criterion naming a `type` outside this list evaluates to "not
 * earned" rather than throwing: an admin typo in Handover 13's editor must not
 * take a student's dashboard down.
 *
 * Each case documents the payload it reads. Every one of them is a count or a
 * threshold over data the student cannot assert about themselves — mastery,
 * correct attempts, completed lessons, `organ_viewed` events — so a badge
 * cannot be manufactured from the browser (see App\Enums\LearningEventType).
 */
enum AchievementCriterion: string
{
    /**
     * `{"type":"mastery_at_least","topic_type":"system","slug":"respiratory","score":70}`
     *
     * Mastery of one named topic reaches a score. `topic_type` is a
     * TopicType value; `slug` addresses the organ or body system by slug,
     * never by id, so the criterion survives a reseed.
     */
    case MasteryAtLeast = 'mastery_at_least';

    /**
     * `{"type":"organ_explored","slug":"heart"}`
     *
     * The student has opened one named organ in the viewer at least once.
     */
    case OrganExplored = 'organ_explored';

    /**
     * `{"type":"structures_identified","count":10}`
     *
     * Distinct structures the student has correctly identified — counted over
     * structures, not attempts, so drilling one structure ten times earns
     * nothing. The same principle as the unweighted rollup
     * (docs/architecture.md §10).
     */
    case StructuresIdentified = 'structures_identified';

    /** `{"type":"lessons_completed","count":3}` */
    case LessonsCompleted = 'lessons_completed';

    /** `{"type":"streak_days","days":3}` */
    case StreakDays = 'streak_days';

    public static function tryFromCriteria(mixed $criteria): ?self
    {
        if (! is_array($criteria)) {
            return null;
        }

        $type = $criteria['type'] ?? null;

        return is_string($type) ? self::tryFrom($type) : null;
    }
}
