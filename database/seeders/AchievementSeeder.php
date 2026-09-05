<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AchievementCriterion;
use App\Enums\TopicType;
use App\Models\Achievement;
use Illuminate\Database\Seeder;

/**
 * The badge catalogue (PRD §16).
 *
 * Content, not code. Every criterion here is a shape
 * `App\Enums\AchievementCriterion` understands, and the slugs referenced —
 * `heart`, `respiratory` — are AnatomySeeder's. A badge naming an organ that
 * does not exist is inert rather than broken: `GamificationService` resolves
 * the slug and awards nothing when it misses, which is what makes this seeder
 * safe to run against a partially seeded database.
 *
 * The set spans all five criterion types deliberately, so every branch of the
 * evaluator has a real row behind it rather than only a factory one. Two of
 * them — Heart Explorer and Structure Hunter — are reachable within the demo
 * journey in PRD §44.
 *
 * Idempotent by slug (docs/engineering.md §6): re-seeding corrects a criterion
 * without orphaning the `user_achievements` rows already earned under it.
 */
final class AchievementSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->badges() as $badge) {
            Achievement::query()->updateOrCreate(
                ['slug' => $badge['slug']],
                $badge,
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function badges(): array
    {
        return [
            [
                'slug' => 'heart-explorer',
                'name' => 'Heart Explorer',
                'description' => 'Opened the heart in the 3D viewer.',
                'icon' => 'heart',
                'criteria' => [
                    'type' => AchievementCriterion::OrganExplored->value,
                    'slug' => 'heart',
                ],
            ],
            [
                'slug' => 'structure-hunter',
                'name' => 'Structure Hunter',
                'description' => 'Correctly identified ten different structures.',
                'icon' => 'target',
                'criteria' => [
                    'type' => AchievementCriterion::StructuresIdentified->value,
                    // Distinct structures, not attempts: drilling one ten times
                    // earns nothing (App\Enums\AchievementCriterion).
                    'count' => 10,
                ],
            ],
            [
                'slug' => 'first-steps',
                'name' => 'First Steps',
                'description' => 'Finished your first lesson.',
                'icon' => 'book',
                'criteria' => [
                    'type' => AchievementCriterion::LessonsCompleted->value,
                    'count' => 1,
                ],
            ],
            [
                'slug' => 'blood-flow-master',
                'name' => 'Blood Flow Master',
                'description' => 'Reached 80% mastery of the heart.',
                'icon' => 'droplet',
                'criteria' => [
                    'type' => AchievementCriterion::MasteryAtLeast->value,
                    'topic_type' => TopicType::Organ->value,
                    'slug' => 'heart',
                    'score' => 80,
                ],
            ],
            [
                'slug' => 'respiratory-specialist',
                'name' => 'Respiratory Specialist',
                'description' => 'Reached 70% mastery of the respiratory system.',
                'icon' => 'lungs',
                'criteria' => [
                    'type' => AchievementCriterion::MasteryAtLeast->value,
                    'topic_type' => TopicType::System->value,
                    'slug' => 'respiratory',
                    'score' => 70,
                ],
            ],
            [
                'slug' => 'three-day-streak',
                'name' => 'Three Day Streak',
                'description' => 'Studied three days in a row.',
                'icon' => 'flame',
                'criteria' => [
                    'type' => AchievementCriterion::StreakDays->value,
                    'days' => 3,
                ],
            ],
        ];
    }
}
