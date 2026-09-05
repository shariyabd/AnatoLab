<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeder registry.
 *
 * OWNED BY HANDOVER 01, but APPEND-ONLY for everyone else: each feature writes
 * its own <Feature>Seeder class and adds exactly one line to the list below
 * (docs/feature-plan.md §7.2). That one line is the smallest possible merge
 * conflict; sharing a seeder body would be the largest.
 *
 * Order matters — a seeder may depend on rows an earlier one created. Add
 * yours after everything it needs and never reorder someone else's line.
 *
 * Every seeder must be idempotent: `db:seed` twice produces the same database,
 * not doubled rows. Use updateOrCreate / firstOrCreate, never plain create()
 * for reference data (docs/engineering.md §6).
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PlatformSeeder::class,

            AnatomySeeder::class,
            LessonSeeder::class,
            AssessmentSeeder::class,
            MissionSeeder::class,
            AchievementSeeder::class,
            // F11 → KnowledgeSeeder
            SimulationSeeder::class,
        ]);
    }
}
