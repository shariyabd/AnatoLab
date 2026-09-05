<?php

declare(strict_types=1);

use App\Enums\QuestionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One assessable question (PRD §22 `questions`, docs/architecture.md §6).
 *
 * Both quiz types live in this one table because both feed one attempt
 * pipeline, which is what lets one mastery score consume them
 * (docs/architecture.md §9). `type` selects the grading branch, not a template.
 *
 * Two columns need their nullability justified, and one needs its *missing*
 * foreign key justified:
 *
 * - `lesson_id` has NO foreign key constraint. `lessons` is owned by Handover
 *   06 and is being built on a parallel branch, so the two migrations meet for
 *   the first time at merge and their order is not knowable from either side.
 *   A real constraint would make `migrate:fresh` fail on whichever branch
 *   landed first (docs/handovers/parallel-execution-plan.md §3 C1, the D1
 *   default: nullable, indexed, unconstrained). It is also legitimately null —
 *   an organ-wide quiz question belongs to no lesson.
 *
 * - `correct_structure_id` is null for every type except `spatial`. It is the
 *   answer key for a spatial question and never leaves the server: the API
 *   Resource strips it, and correctness is decided in AssessmentService
 *   (docs/architecture.md §5.4 rule 2, invariant 4).
 *
 * - `explanation` is null while a question is being drafted. It is shown only
 *   *after* an attempt is recorded, so it is an answer key too as far as the
 *   question payload is concerned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table): void {
            $table->id();

            // Unconstrained on purpose — see the class docblock.
            $table->unsignedBigInteger('lesson_id')->nullable();

            $table->foreignId('organ_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();

            $table->string('type', 32);
            $table->text('question');
            $table->unsignedTinyInteger('difficulty')->default(1);
            $table->text('explanation')->nullable();

            // nullOnDelete rather than cascade: deleting a structure should not
            // silently delete the questions that referenced it, because the
            // attempts hanging off them are mastery input.
            $table->foreignId('correct_structure_id')
                ->nullable()
                ->constrained('anatomical_structures')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            // Holds the short-answer rubric and the optional hint. Never
            // serialised wholesale — the rubric names the accepted answer.
            $table->json('metadata')->nullable();

            $table->string('status', 16)->default(QuestionStatus::Draft->value);

            // PRD §19: an AI-written question is reviewable as such. Handover
            // 13's review queue filters on it.
            $table->boolean('generated_by_ai')->default(false);

            $table->timestamps();

            // The quiz read: this organ's published questions, every request.
            $table->index(['organ_id', 'status']);

            // Handover 06 builds lesson quizzes off this, and Handover 13's
            // review queue lists by status alone.
            $table->index('lesson_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
