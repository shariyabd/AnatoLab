<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One student's mastery of one topic (PRD §15, docs/architecture.md §6, §10).
 *
 * A "topic" is a structure, an organ, or a body system. All three live in one
 * table keyed by `(topic_type, topic_id)` rather than in three, because every
 * read the dashboard makes wants them together and the rollup writes all three
 * levels in the same transaction.
 *
 * `topic_id` carries NO foreign key, and cannot: it points at
 * `anatomical_structures`, `organs` or `body_systems` depending on the row.
 * The rows are derived data — `RecalculateMastery` rebuilds them from
 * `attempts`, so a dangling id is at worst a stale row that the next
 * recalculation drops, not lost history.
 *
 * Migration timestamp band `2026_09_07_02*` is this handover's allocation for
 * batch D; Handover 09 takes `_01*` and Handover 12 `_03*`, so three lanes
 * writing tables in parallel produce one deterministic `migrate:fresh` order
 * (docs/handovers/parallel-execution-plan.md §3 C3).
 *
 * **Deltas from docs/architecture.md §6**, which lists `attempts`,
 * `correct_attempts` and `last_activity_at` only. Three further counters are
 * stored, and none of them changes the formula — they are its *inputs*:
 *
 * - `hinted_attempts` is the numerator of `hint_penalty`.
 * - `covered_structures` / `total_structures` are the two halves of `coverage`.
 *
 * Without them the recommendation reason would have to re-read every attempt
 * to say *why* a topic is weak, which is the one thing it exists to say
 * (docs/architecture.md §10: the reason is templated from the mastery
 * breakdown). Storing the inputs alongside the score keeps that a row read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_mastery', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();

            $table->string('topic_type', 16);
            $table->unsignedBigInteger('topic_id');

            // 0.00–100.00. DECIMAL rather than a float because this number is
            // compared and ordered ("lowest-mastery topic") and shown as a
            // percentage; binary float ordering that disagrees with the
            // displayed value is a bug nobody can reproduce.
            $table->decimal('mastery_score', 5, 2)->default(0);

            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('correct_attempts')->default(0);
            $table->unsignedInteger('hinted_attempts')->default(0);

            $table->unsignedInteger('covered_structures')->default(0);
            $table->unsignedInteger('total_structures')->default(0);

            // Null only for a row written before any attempt exists, which the
            // recalculation never does. Nullable so recency has an explicit
            // "never" rather than a sentinel date that decays.
            $table->timestamp('last_activity_at')->nullable();

            $table->timestamps();

            // The idempotency guarantee behind the rollup's upsert: one row per
            // student per topic, so recomputing twice updates rather than races.
            $table->unique(['user_id', 'topic_type', 'topic_id']);

            // The dashboard read (per-system mastery) and the recommendation's
            // "weakest first" scan, which is ordered by score within a type.
            $table->index(['user_id', 'topic_type', 'mastery_score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_mastery');
    }
};
