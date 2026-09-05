<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One student's run at one mission (PRD §22, docs/architecture.md §6).
 *
 * This is the *envelope*. The graded work itself lives in `attempts`, one row
 * per step, each carrying this row's id in `mission_attempt_id` — which is why
 * Handover 07 shipped that column unconstrained and unused
 * (docs/handovers/07-assessment-engine.md, and the docblock on the `attempts`
 * migration). One mastery calculation therefore consumes quiz answers and
 * mission steps through the same pipeline, which is the entire reason the two
 * share a table (docs/architecture.md §9, §10).
 *
 * The foreign key that would express that link points the *other* way — from
 * `attempts` to here — and Handover 07 deliberately did not add it: `attempts`
 * was built one batch before this table existed. It stays unconstrained rather
 * than being added here, because altering Handover 07's schema is exactly what
 * this lane was told not to do (docs/handovers/parallel-execution-plan.md D5).
 * `MissionService` is the only writer of either side.
 *
 * `result` is the server's own record of the run: per step, the target that
 * was expected, what was picked, the outcome and the points. It is not the
 * payload the client receives — that is assembled by
 * App\Http\Resources\Missions\MissionResultResource — but it is what lets
 * Handover 10 explain a score and Handover 13 review a mission that students
 * keep failing, without re-deriving either from `attempts`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mission_attempts', function (Blueprint $table): void {
            $table->id();

            // Cascade on both: a run is only meaningful as this student's run
            // at this mission, and neither survives the other's deletion in a
            // form anyone could interpret.
            $table->foreignId('user_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('mission_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();

            // Points earned, from the mission's own scoring table. Unsigned:
            // App\Services\Assessment\MissionConfiguration floors every award
            // at zero, so a mission cannot be authored to take points away.
            $table->unsignedInteger('score')->default(0);

            // True only when every step was answered correctly. "Reached the
            // end" is not the same claim, and mastery and achievements both
            // want the stronger one (App\Services\Assessment\MissionResult).
            $table->boolean('completed')->default(false);

            // Wall-clock for the whole run, as reported by the client and
            // capped in the FormRequest. Nullable because a run abandoned and
            // submitted by a page unload may have no usable figure.
            $table->unsignedInteger('duration_ms')->nullable();

            $table->json('result');
            $table->timestamps();

            // "This student's mission history", which is the dashboard read and
            // Handover 10's mastery window.
            $table->index(['user_id', 'created_at']);

            // "How has this student done at this mission", for the personal
            // best a repeat run is measured against.
            $table->index(['user_id', 'mission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mission_attempts');
    }
};
