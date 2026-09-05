<?php

declare(strict_types=1);

use App\Enums\LessonStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured lessons around one organ (PRD §8, docs/architecture.md §6).
 *
 * Timestamp band: Handover 06 was allocated `..._0100_*` so batch C's three
 * table owners interleave deterministically on merge
 * (docs/handovers/parallel-execution-plan.md §3 C3). The *date* is a day after
 * the base migrations rather than the same day, because `0100` on 2026-09-05
 * would sort before Handover 03's `100000` and this table's `organ_id` foreign
 * key needs `organs` to already exist.
 *
 * `content` is the whole lesson body: an ordered list of steps, each naming
 * its type (App\Enums\LessonStepType) and carrying its own payload. A JSON
 * column rather than a `lesson_steps` table because nothing queries, filters,
 * or joins a step — a step is only ever read as part of the lesson that owns
 * it, and the shape differs per type, which is exactly the case a normalised
 * table serves badly.
 *
 * There is deliberately no `body_system_id` here even though the API filters
 * by system. The system is reached through `organ.body_system_id`; duplicating
 * it would create a second place for it to be wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lessons', function (Blueprint $table): void {
            $table->id();

            // Restrict rather than cascade: deleting an organ that still has
            // lessons written against it is a content mistake, and silently
            // deleting the lessons hides it (matching `organs.body_system_id`).
            $table->foreignId('organ_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();

            $table->string('slug')->unique();
            $table->string('title');
            $table->text('description')->nullable();

            // The single sentence PRD §8 opens a lesson with, and the line the
            // AI tutor is given as "Learning objective" (PRD §39). Not nullable:
            // a lesson without one has nothing to render in its first step.
            $table->text('objective');

            // Reuses App\Enums\DifficultyPreference's vocabulary rather than a
            // near-identical LessonDifficulty. F10 recommends the next activity
            // by matching a lesson against `users.difficulty_preference`
            // (docs/architecture.md §10); two vocabularies would need a mapping
            // table between three values and three identical values.
            $table->string('difficulty', 16);

            $table->unsignedSmallInteger('estimated_minutes');
            $table->json('content');
            $table->string('status')->default(LessonStatus::Draft->value);
            $table->timestamps();

            // The lesson library lists published lessons for one organ, and
            // filters by difficulty within that. Both are on every page load.
            $table->index(['status', 'organ_id']);
            $table->index(['status', 'difficulty']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lessons');
    }
};
