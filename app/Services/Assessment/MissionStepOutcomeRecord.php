<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use App\Enums\MissionStepOutcome;
use App\Models\AnatomicalStructure;

/**
 * One graded step, still holding everything the grader knew about it.
 *
 * Internal to `MissionService`: it is what `grade()` returns and what
 * persistence, the reveal decision and `MissionResult` are all built from. It
 * exists so those three do not each re-derive the pairing of a configured step
 * with the pick that answered it.
 *
 * It carries the resolved `AnatomicalStructure` and the authored
 * `MissionStep` — the target slug included — so it is **not** a client payload
 * and never becomes one. `MissionStepResult` is the shape a student is allowed
 * to see, and it is built from this by dropping the target
 * (App\Http\Resources\Missions\MissionResultResource).
 */
final readonly class MissionStepOutcomeRecord
{
    public function __construct(
        public int $index,
        public MissionStep $step,
        public MissionStepOutcome $outcome,
        /** What the student picked, resolved against the organ; null if skipped. */
        public ?AnatomicalStructure $picked = null,
        public bool $hintUsed = false,
        public ?int $timeSpentMs = null,
    ) {}
}
