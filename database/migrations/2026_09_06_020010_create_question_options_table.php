<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The choices on a multiple-choice question (PRD §22 `question_options`).
 *
 * `is_correct` is the answer key. It is stripped by
 * App\Http\Resources\Assessment\QuestionOptionResource and never reaches the
 * browser in any payload (invariant 4); the client sends back an option id and
 * the server decides (docs/architecture.md §5.4 rule 2).
 *
 * `label` is what the student reads. `value` is a stable, human-authored key
 * ('a', 'b', …) that survives re-seeding, so AssessmentSeeder is idempotent on
 * (question_id, value) without depending on auto-increment ids.
 *
 * There is no `position` column: rows are served in id order, and the seeder
 * varies which position holds the correct option. A quiz whose answer is
 * always third teaches position, not anatomy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('label', 500);
            $table->string('value', 32);
            $table->boolean('is_correct')->default(false);
            $table->timestamps();

            $table->unique(['question_id', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_options');
    }
};
