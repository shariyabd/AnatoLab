<?php

declare(strict_types=1);

use App\Enums\MissionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-step spatial challenges (PRD §13, docs/architecture.md §6, §9).
 *
 * Timestamp band: batch D's three table owners were allocated one band each so
 * they interleave deterministically on merge
 * (docs/handovers/parallel-execution-plan.md §3 C3). Handover 09 takes
 * `2026_09_07_0100*`, leaving `0200*` to Handover 10 and `0300*` to Handover
 * 12. The *date* is a day after batch C's migrations rather than the same day,
 * because this table's `organ_id` foreign key needs `organs` to already exist
 * and a same-day `010000` would sort before Handover 03's `100000`.
 *
 * `configuration` is the whole mission: its ordered steps, each naming its
 * target structure, its prompt and its hint, plus the scoring table
 * (docs/architecture.md §9). A JSON column rather than a `mission_steps` table
 * for the reason `lessons.content` gives: nothing queries, filters or joins a
 * step — a step is only ever read as part of the mission that owns it — and
 * the payload differs per mission type, which is the case a normalised table
 * serves worst.
 *
 * **`configuration` is an answer key.** It holds the target sequence, so it
 * never reaches the browser: App\Http\Resources\Missions\MissionStepResource
 * emits the prompt and the hint and nothing else, and
 * Tests\Feature\Missions\TargetSequenceAbsenceTest proves it on every payload
 * that carries a mission (invariant 4, docs/architecture.md §5.4 rule 2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('missions', function (Blueprint $table): void {
            $table->id();

            // Restrict rather than cascade, matching `lessons.organ_id`:
            // deleting an organ that still has missions written against it is
            // a content mistake, and silently deleting the missions hides it.
            $table->foreignId('organ_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();

            $table->string('slug')->unique();
            $table->string('title');
            $table->text('description')->nullable();

            // App\Enums\MissionType — the validation branch, not a label.
            $table->string('type', 32);

            // 1 (introductory) to 5 (specialist), matching `questions.difficulty`
            // rather than `lessons.difficulty`: a mission is graded work whose
            // difficulty is compared against questions in the same mastery
            // window (docs/architecture.md §10), not a study path matched
            // against `users.difficulty_preference`.
            $table->unsignedTinyInteger('difficulty')->default(1);

            $table->json('configuration');
            $table->string('status')->default(MissionStatus::Draft->value);
            $table->timestamps();

            // The mission library lists published missions, and one organ's
            // missions are linked from its explore and quiz pages. Both are on
            // a page load.
            $table->index(['status', 'organ_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('missions');
    }
};
