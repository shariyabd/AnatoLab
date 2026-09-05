<?php

declare(strict_types=1);

namespace App\Services\Progress;

use Carbon\CarbonImmutable;

/**
 * A running tally of one topic's attempts, while the recalculation walks them.
 *
 * Mutable, and the only mutable object in this namespace. Folding attempts
 * into an immutable value would allocate one object per attempt per level;
 * this is the accumulator, and `toInput()` is the point at which it becomes
 * the immutable thing the pure calculator accepts.
 *
 * `coveredStructures` is a set rather than a count because the same structure
 * can be attempted many times and coverage counts structures, not attempts
 * (docs/architecture.md §10).
 */
final class TopicAggregate
{
    public int $attempts = 0;

    public int $correctAttempts = 0;

    public int $hintedAttempts = 0;

    public ?CarbonImmutable $lastActivityAt = null;

    /** @var array<int, true> structure id → seen */
    private array $coveredStructures = [];

    public function record(bool $isCorrect, bool $hintUsed, ?CarbonImmutable $at, ?int $structureId): void
    {
        $this->attempts++;

        if ($isCorrect) {
            $this->correctAttempts++;
        }

        if ($hintUsed) {
            $this->hintedAttempts++;
        }

        if ($at !== null && ($this->lastActivityAt === null || $at->greaterThan($this->lastActivityAt))) {
            $this->lastActivityAt = $at;
        }

        if ($structureId !== null) {
            $this->coveredStructures[$structureId] = true;
        }
    }

    public function coveredCount(): int
    {
        return count($this->coveredStructures);
    }

    /**
     * @return list<int>
     */
    public function coveredIds(): array
    {
        return array_keys($this->coveredStructures);
    }

    public function absorb(self $other): void
    {
        $this->attempts += $other->attempts;
        $this->correctAttempts += $other->correctAttempts;
        $this->hintedAttempts += $other->hintedAttempts;
        $this->coveredStructures += $other->coveredStructures;

        if ($other->lastActivityAt !== null
            && ($this->lastActivityAt === null || $other->lastActivityAt->greaterThan($this->lastActivityAt))) {
            $this->lastActivityAt = $other->lastActivityAt;
        }
    }

    public function toInput(CarbonImmutable $asOf, int $coveredStructures, int $totalStructures): MasteryInput
    {
        return new MasteryInput(
            attempts: $this->attempts,
            correctAttempts: $this->correctAttempts,
            hintedAttempts: $this->hintedAttempts,
            coveredStructures: $coveredStructures,
            totalStructures: $totalStructures,
            lastActivityAt: $this->lastActivityAt,
            asOf: $asOf,
        );
    }
}
