<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One graded answer, whatever produced it (PRD §22 `attempts`).
 *
 * Spatial-quiz answers, MCQ answers and mission-step answers are the same
 * shape and share this table so that one mastery calculation consumes all of
 * them (docs/architecture.md §6 deltas, §9). That is why both `question_id`
 * and `mission_attempt_id` are nullable: a quiz answer has the first, a
 * mission step has the second.
 *
 * `mission_attempt_id` carries NO foreign key and is unused by this lane.
 * `mission_attempts` is owned by Handover 09 and does not exist yet; the
 * column is here so that lane populates a column rather than altering a table
 * two other lanes have already built against
 * (docs/handovers/07-assessment-engine.md, DB changes).
 *
 * `is_correct` is decided server-side, in AssessmentService, from the
 * question's own answer key. Nothing the client sends contributes to it
 * (invariant 4, docs/architecture.md §5.4 rule 2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();

            // Cascades: an attempt at a question that no longer exists cannot
            // be explained to the student or re-scored, so it is not history
            // worth keeping.
            $table->foreignId('question_id')
                ->nullable()
                ->constrained()
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // Unconstrained on purpose — see the class docblock.
            $table->unsignedBigInteger('mission_attempt_id')->nullable();

            // nullOnDelete on both: the verdict stays true even after the row
            // the student picked is gone, and mastery is computed from
            // `is_correct`, not from what was selected.
            $table->foreignId('selected_structure_id')
                ->nullable()
                ->constrained('anatomical_structures')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->foreignId('selected_option_id')
                ->nullable()
                ->constrained('question_options')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->text('answer_text')->nullable();
            $table->boolean('is_correct')->default(false);

            // Nullable because a mission step scored in bulk by Handover 09 may
            // have no per-step timing. The quiz always sends it.
            $table->unsignedInteger('time_spent_ms')->nullable();

            $table->boolean('hint_used')->default(false);
            $table->timestamps();

            // Handover 10's mastery window: this student's recent attempts.
            $table->index(['user_id', 'created_at']);

            // Per-question accuracy, and the "already answered this round"
            // lookup.
            $table->index(['user_id', 'question_id']);

            $table->index('mission_attempt_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attempts');
    }
};
