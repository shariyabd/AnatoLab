<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One student's run of one simulation (PRD §22 `simulation_sessions`).
 *
 * Written only by SimulationService, from a User passed in as an argument and
 * never from a request parameter (invariant 1, docs/engineering.md §10).
 *
 * **`events` is the record; `state` and `result` are caches of it.** Every
 * step replays the whole ordered action log through the engine rather than
 * mutating the stored state in place. That is what makes "replaying the same
 * actions reproduces the same state exactly" true by construction rather than
 * by discipline: there is no accumulated float in this table that a later read
 * trusts. If `state` and a replay of `events` ever disagreed, the replay would
 * win — so the two cannot drift.
 *
 * UNIQUE(user_id, simulation_id) is the idempotency guarantee behind
 * `POST /simulations/{simulation}/event`: a student has one run of a
 * simulation, resumable across reloads, and resetting empties it rather than
 * starting a second row.
 *
 * **Delta from PRD §22:** `conversation_id`. When a step has no curated
 * explanation the tutor writes one (docs/architecture.md §12), and without a
 * thread to continue, each step of one run would open a separate conversation
 * in the student's history. Nullable, because a fully curated simulation never
 * calls the tutor at all, and `nullOnDelete` because a deleted thread must not
 * take a run's event log with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('simulation_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('simulation_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();

            // See the class docblock: the tutor thread this run's generated
            // explanations belong to.
            $table->foreignId('conversation_id')
                ->nullable()
                ->constrained()
                ->cascadeOnUpdate()
                ->nullOnDelete();

            // The current variable values. A cache of replaying `events`.
            $table->json('state');

            // The ordered action log. This is the run.
            $table->json('events');

            // The standing outcome after the last step — state key, label,
            // fired outcomes, terminal flag. Null before the first action,
            // because "no action taken yet" is not a result.
            $table->json('result')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'simulation_id']);

            // The dashboard's "recently run" list, and F10's per-user read.
            $table->index(['user_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('simulation_sessions');
    }
};
