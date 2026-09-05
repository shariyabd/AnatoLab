<?php

declare(strict_types=1);

namespace App\Services\Progress;

/**
 * Everything the badge criteria are evaluated against, gathered once.
 *
 * A student may hold a dozen badges and the catalogue will grow; without this,
 * each criterion would issue its own query and awarding would be N queries per
 * attempt, on a queue, per student. Gathered once, the whole evaluation is a
 * handful of array lookups (App\Services\Progress\GamificationService).
 *
 * Nothing in here is client-assertable. Mastery comes from `learning_mastery`,
 * lessons from `lesson_progress`, and the explored-organ set from
 * `organ_viewed` events — which the browser does report, but which award
 * nothing on their own beyond "you opened this organ", exactly the claim a
 * browser is entitled to make (App\Enums\LearningEventType).
 */
final readonly class AchievementLedger
{
    /**
     * @param  array<string, float>  $masteryByTopic  "topic_type:topic_id" → score
     * @param  list<int>  $exploredOrganIds
     */
    public function __construct(
        public array $masteryByTopic,
        public int $structuresIdentified,
        public int $lessonsCompleted,
        public array $exploredOrganIds,
        public int $streakDays,
    ) {}
}
