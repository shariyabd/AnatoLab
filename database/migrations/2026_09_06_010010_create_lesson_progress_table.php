<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per student per lesson they have opened (docs/architecture.md §6).
 *
 * Written only by LessonService, from a User passed in as an argument — never
 * from a request parameter (invariant 1, docs/engineering.md §10). Handover 10
 * reads these rows to compute mastery and to recommend the next activity; it
 * does not write them.
 *
 * The table name is singular-ish on purpose: `lesson_progress` is the name in
 * PRD §22 and docs/architecture.md §6, and "progress" is a mass noun. Eloquent
 * cannot infer it from `LessonProgress`, so the model sets `$table`.
 *
 * UNIQUE(user_id, lesson_id) is the idempotency guarantee behind
 * `POST /lessons/{lesson}/complete`: completing twice updates one row rather
 * than racing two inserts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();

            $table->string('status', 16);

            // 0–100, integer. A percentage of steps seen is never fractional:
            // it is (furthest step reached / step count), rounded once, on the
            // server. DECIMAL would promise a precision the input does not have.
            $table->unsignedTinyInteger('progress_percent')->default(0);

            // Null until the lesson is completed, and never cleared afterwards.
            // This is the timestamp F10 ages mastery against, so re-opening a
            // finished lesson must not move it (docs/architecture.md §10).
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'lesson_id']);

            // The dashboard's "recently studied" list, and F10's per-user read.
            $table->index(['user_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_progress');
    }
};
