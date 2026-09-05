<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The badge catalogue (PRD §16, docs/architecture.md §6).
 *
 * Content, not code: a badge is a row, so adding "Blood Flow Master" is a
 * seeder line rather than a class. `criteria` is the JSON rule
 * `GamificationService` evaluates; `App\Enums\AchievementCriterion` is the
 * closed set of shapes it understands, so an unrecognised rule is inert rather
 * than a crash on a student's dashboard.
 *
 * `slug` is the natural key. The seeder is idempotent against it, and the
 * criteria of an existing badge can be corrected without orphaning the
 * `user_achievements` rows already earned under it.
 *
 * There is no `points` column. XP is derived from what the student did, not
 * granted per badge row — see App\Services\Progress\GamificationService, which
 * recomputes the whole ledger so a retried job cannot award twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievements', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name');
            $table->string('description');

            // An icon name the frontend Icon component resolves, matching the
            // convention config/navigation.php already uses. Never a path: the
            // asset set is the frontend's business.
            $table->string('icon', 32);

            $table->json('criteria');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achievements');
    }
};
