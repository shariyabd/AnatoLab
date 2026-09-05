<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only learning log (PRD §29, docs/architecture.md §6, §13).
 *
 * Append-only is enforced by shape, not by convention: there is a `created_at`
 * and no `updated_at`, because nothing ever updates a row here. An event is a
 * statement about a moment; correcting one would mean rewriting history that
 * the learning metrics in PRD §30 are computed from.
 *
 * `context_type` / `context_id` are a loose polymorphic pair with no foreign
 * key. The same reasoning as `learning_mastery.topic_id`: an event may name an
 * organ, a structure, a lesson, a question or a mission, and the log must
 * survive the deletion of the thing it describes — "this student answered a
 * question on 3 March" stays true after the question is retired.
 *
 * `occurred_at` is separate from `created_at` on purpose. Client events batch
 * and flush on an interval and on page unload (docs/architecture.md §13), so
 * the moment the row is written can be a minute or more after the moment the
 * student did the thing. Streaks and session analysis want the second one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();

            $table->string('event_type', 48);

            $table->string('context_type', 32)->nullable();
            $table->unsignedBigInteger('context_id')->nullable();

            $table->json('payload')->nullable();

            // When the student did it, as opposed to when we recorded it.
            $table->timestamp('occurred_at');

            $table->timestamp('created_at')->nullable();

            // The activity feed, the streak scan, and every per-student metric.
            $table->index(['user_id', 'occurred_at']);

            // PRD §30's cross-student metrics: completion rate, hint usage,
            // retrieval success — all "count these events over this window".
            $table->index(['event_type', 'occurred_at']);

            $table->index(['context_type', 'context_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_events');
    }
};
