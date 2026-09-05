<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which badges a student has earned, and when (docs/architecture.md §6).
 *
 * UNIQUE(user_id, achievement_id) is the whole safety story for
 * `AwardAchievements`: the job re-evaluates every criterion on every run, so
 * without the constraint a retry would file the same badge twice. With it, the
 * award is an insert that either happens once or not at all.
 *
 * `earned_at` is stored rather than read off `created_at` because the two can
 * legitimately differ — a badge earned from a batched client event is earned
 * at the moment of the event, not at the moment the queue drained it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_achievements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();

            // Cascade: a badge that no longer exists cannot be displayed or
            // explained, so the row that says it was earned is not history
            // worth keeping.
            $table->foreignId('achievement_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();

            $table->timestamp('earned_at');

            $table->timestamps();

            $table->unique(['user_id', 'achievement_id']);

            // The dashboard's "most recent badges" strip.
            $table->index(['user_id', 'earned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_achievements');
    }
};
