<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Services\Progress\GamificationService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Recompute XP, level and badges for one student (PRD §16).
 *
 * Queued on `default`, as docs/architecture.md §11 allocates it, and dispatched
 * *after* `RecalculateMastery` rather than beside it — a badge like
 * "Respiratory Specialist" is a threshold on a mastery score, so evaluating it
 * against the pre-attempt score would award it one answer late.
 *
 * Safe to run twice. `GamificationService` recomputes the XP ledger from
 * scratch instead of incrementing, and badge inserts are protected by
 * UNIQUE(user_id, achievement_id), so a retry is a no-op rather than a double
 * award.
 *
 * Takes an id rather than the model: `SerializesModels` would fail the job on
 * a deleted account, where the right behaviour is to stop quietly.
 */
final class AwardAchievements implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(private readonly int $userId)
    {
        $this->onQueue('default');
    }

    public function handle(GamificationService $gamification): void
    {
        $student = User::query()->find($this->userId);

        if (! $student instanceof User) {
            return;
        }

        $gamification->sync($student, CarbonImmutable::now());
    }
}
