<?php

declare(strict_types=1);

use App\Enums\MasteryFactor;
use App\Services\Progress\MasteryBreakdown;
use App\Services\Progress\MasteryCalculator;
use App\Services\Progress\MasteryInput;
use Carbon\CarbonImmutable;

/*
| The mastery formula (docs/architecture.md §10), table-driven.
|
| docs/handovers/10-progress-mastery.md calls this the highest-value test in
| the feature, and the reason is structural: MasteryCalculator is pure — no
| database, no clock, no auth — so every case below is arithmetic with a stated
| expected value, and a change to a constant fails a named row rather than a
| distant integration test.
|
| Every expected score is written out longhand in its comment. A test that
| recomputed the formula to check the formula would pass for any formula.
*/

/** A fixed moment. `asOf` is an input, never the clock. */
function asOf(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-03-01 12:00:00');
}

function input(
    int $attempts = 0,
    int $correct = 0,
    int $hinted = 0,
    int $covered = 0,
    int $total = 0,
    ?string $lastActivity = null,
): MasteryInput {
    return new MasteryInput(
        attempts: $attempts,
        correctAttempts: $correct,
        hintedAttempts: $hinted,
        coveredStructures: $covered,
        totalStructures: $total,
        lastActivityAt: $lastActivity === null ? null : CarbonImmutable::parse($lastActivity),
        asOf: asOf(),
    );
}

beforeEach(function (): void {
    $this->calculator = new MasteryCalculator;
});

it('scores the formula', function (MasteryInput $input, float $expected): void {
    expect($this->calculator->calculate($input)->score)->toBe($expected);
})->with([

    // Boundary condition 1. hint_penalty divides by attempts, so the formula is
    // undefined here and zero is the chosen answer: no evidence, no mastery.
    // It is also what makes acceptance criterion 1 observable — the first
    // answer moves the number off zero.
    'zero attempts' => [
        fn (): MasteryInput => input(total: 10),
        0.0,
    ],

    // accuracy (10+1)/(10+2) = 0.9166…, everything else neutral.
    // 100 × 0.9166… × 1 × 1 × 1 = 91.67
    'all correct, fresh, fully covered' => [
        fn (): MasteryInput => input(
            attempts: 10, correct: 10, covered: 10, total: 10, lastActivity: '2026-03-01 12:00:00',
        ),
        91.67,
    ],

    // Laplace smoothing floors a perfect failure at (0+1)/(10+2) = 0.0833…
    // rather than zero, so a bad round is recoverable rather than absorbing.
    // 100 × 0.0833… = 8.33
    'all wrong, fresh, fully covered' => [
        fn (): MasteryInput => input(
            attempts: 10, correct: 0, covered: 10, total: 10, lastActivity: '2026-03-01 12:00:00',
        ),
        8.33,
    ],

    // Smoothing also caps a single lucky answer: (1+1)/(1+2) = 0.6667, not 1.0.
    // 100 × 0.6667 = 66.67
    'one correct answer is not mastery' => [
        fn (): MasteryInput => input(
            attempts: 1, correct: 1, covered: 1, total: 1, lastActivity: '2026-03-01 12:00:00',
        ),
        66.67,
    ],

    // hint_penalty = 1 − 0.15 × (10/10) = 0.85 — the full penalty, and
    // deliberately gentle: a hinted right answer is still a right answer.
    // 100 × 0.9166… × 0.85 = 77.92
    'heavy hint use, everything else perfect' => [
        fn (): MasteryInput => input(
            attempts: 10, correct: 10, hinted: 10, covered: 10, total: 10,
            lastActivity: '2026-03-01 12:00:00',
        ),
        77.92,
    ],

    // Half the answers hinted: 1 − 0.15 × 0.5 = 0.925.
    // 100 × 0.9166… × 0.925 = 84.79
    'half the answers hinted' => [
        fn (): MasteryInput => input(
            attempts: 10, correct: 10, hinted: 5, covered: 10, total: 10,
            lastActivity: '2026-03-01 12:00:00',
        ),
        84.79,
    ],

    // One half-life, 14 days: recency 0.5. 100 × 0.9166… × 0.5 = 45.83
    'stale by exactly one half-life' => [
        fn (): MasteryInput => input(
            attempts: 10, correct: 10, covered: 10, total: 10, lastActivity: '2026-02-15 12:00:00',
        ),
        45.83,
    ],

    // Two half-lives: 0.25. 100 × 0.9166… × 0.25 = 22.92
    'stale by two half-lives' => [
        fn (): MasteryInput => input(
            attempts: 10, correct: 10, covered: 10, total: 10, lastActivity: '2026-02-01 12:00:00',
        ),
        22.92,
    ],

    // coverage 2/10 = 0.2, term 0.5 + 0.5 × 0.2 = 0.6.
    // 100 × 0.9166… × 0.6 = 55.00
    'partial coverage halves a perfect record' => [
        fn (): MasteryInput => input(
            attempts: 10, correct: 10, covered: 2, total: 10, lastActivity: '2026-03-01 12:00:00',
        ),
        55.0,
    ],

    // The coverage floor: one structure of nine is halved, never zeroed.
    // 100 × 0.9166… × (0.5 + 0.5 × 1/9) = 50.93
    'a single structure of nine is halved, not zeroed' => [
        fn (): MasteryInput => input(
            attempts: 10, correct: 10, covered: 1, total: 9, lastActivity: '2026-03-01 12:00:00',
        ),
        50.93,
    ],

    // Boundary condition 2: nothing to cover means fully covered. An organ
    // quizzed only by multiple choice must not be capped at half marks forever.
    'a topic with no structures is fully covered' => [
        fn (): MasteryInput => input(
            attempts: 10, correct: 10, total: 0, lastActivity: '2026-03-01 12:00:00',
        ),
        91.67,
    ],

    // Everything wrong at once: 30% correct, all hinted, a quarter covered,
    // three half-lives stale.
    // accuracy (3+1)/(10+2) = 0.3333; recency 2^(-42/14) = 0.125;
    // hint 0.85; coverage term 0.5 + 0.5 × 0.25 = 0.625
    // 100 × 0.3333 × 0.125 × 0.85 × 0.625 = 2.21
    'every factor working against the student' => [
        fn (): MasteryInput => input(
            attempts: 10, correct: 3, hinted: 10, covered: 1, total: 4,
            lastActivity: '2026-01-18 12:00:00',
        ),
        2.21,
    ],
]);

it('reports the factors that produced the score', function (): void {
    $breakdown = $this->calculator->calculate(input(
        attempts: 10, correct: 10, hinted: 5, covered: 2, total: 10,
        lastActivity: '2026-02-15 12:00:00',
    ));

    expect($breakdown->accuracy)->toBe(11 / 12)
        ->and($breakdown->recency)->toBe(0.5)
        ->and($breakdown->hintPenalty)->toBe(0.925)
        ->and($breakdown->coverage)->toBe(0.2);
});

it('reports neutral factors for a topic with no attempts', function (): void {
    $breakdown = $this->calculator->calculate(input(total: 10));

    // The score is zero, but the factors say *why* — never practised, rather
    // than practised and failed.
    expect($breakdown->score)->toBe(0.0)
        ->and($breakdown->accuracy)->toBe(0.5)
        ->and($breakdown->recency)->toBe(1.0)
        ->and($breakdown->hintPenalty)->toBe(1.0);
});

it('clamps inputs that cannot be true', function (): void {
    // More correct answers than attempts, more hints than attempts, and more
    // structures covered than the topic contains. None of these can arise from
    // MasteryService, and all three would otherwise produce a score above 100.
    $breakdown = $this->calculator->calculate(input(
        attempts: 4, correct: 40, hinted: 40, covered: 40, total: 4,
        lastActivity: '2026-03-01 12:00:00',
    ));

    expect($breakdown->accuracy)->toBe(5 / 6)
        ->and($breakdown->hintPenalty)->toBe(0.85)
        ->and($breakdown->coverage)->toBe(1.0)
        ->and($breakdown->score)->toBeLessThanOrEqual(100.0);
});

it('does not amplify a score for a timestamp in the future', function (): void {
    // Clock skew between the web node and the queue worker. Decay clamps at
    // "no decay" rather than growing past 1.
    $breakdown = $this->calculator->calculate(input(
        attempts: 10, correct: 10, covered: 10, total: 10, lastActivity: '2026-04-01 12:00:00',
    ));

    expect($breakdown->recency)->toBe(1.0)
        ->and($breakdown->score)->toBe(91.67);
});

it('decays but never reaches zero', function (): void {
    // Knowledge fades; it does not expire. Half a year of neglect leaves a
    // perfect record barely visible rather than gone, which is what keeps a
    // long-abandoned topic distinguishable from one never attempted — exactly
    // the distinction the recommendation needs.
    $stale = $this->calculator->calculate(input(
        attempts: 10, correct: 10, covered: 10, total: 10, lastActivity: '2025-09-01 12:00:00',
    ));

    expect($stale->score)->toBeGreaterThan(0.0)
        ->and($stale->score)->toBeLessThan(1.0);

    // Two years on, the decay factor is still positive even though the score
    // itself rounds away at the two decimals the column stores.
    $ancient = $this->calculator->calculate(input(
        attempts: 10, correct: 10, covered: 10, total: 10, lastActivity: '2024-01-01 12:00:00',
    ));

    expect($ancient->recency)->toBeGreaterThan(0.0);
});

/*
| Rolling up. The property that matters is that it is unweighted.
*/

it('rolls children up as an unweighted mean', function (): void {
    $strong = $this->calculator->calculate(input(
        attempts: 10, correct: 10, covered: 1, total: 1, lastActivity: '2026-03-01 12:00:00',
    ));

    $weak = $this->calculator->calculate(input(
        attempts: 10, correct: 0, covered: 1, total: 1, lastActivity: '2026-03-01 12:00:00',
    ));

    // (0.9166… + 0.0833…) / 2 = 0.5, coverage 1 → 100 × 0.5 = 50.00
    expect($this->calculator->rollUp([$strong, $weak], 1.0)->score)->toBe(50.0);
});

it('does not let one heavily drilled structure carry an organ', function (): void {
    // Two hundred perfect answers on one structure, four wrong ones on each of
    // three others. A count-weighted mean would read as near-mastery of the
    // organ; the unweighted one does not (docs/architecture.md §10).
    $drilled = $this->calculator->calculate(input(
        attempts: 200, correct: 200, covered: 1, total: 1, lastActivity: '2026-03-01 12:00:00',
    ));

    $neglected = array_fill(0, 3, $this->calculator->calculate(input(
        attempts: 4, correct: 0, covered: 1, total: 1, lastActivity: '2026-03-01 12:00:00',
    )));

    $organ = $this->calculator->rollUp([$drilled, ...$neglected], 1.0);

    // What the same evidence would score if the mean were weighted by attempt
    // count: 200 of 212 right, which is the number this rollup exists to avoid.
    $countWeighted = $this->calculator->calculate(input(
        attempts: 212, correct: 200, covered: 1, total: 1, lastActivity: '2026-03-01 12:00:00',
    ));

    expect($drilled->score)->toBeGreaterThan(99.0)
        ->and($countWeighted->score)->toBeGreaterThan(90.0)
        ->and($organ->score)->toBeLessThan(40.0);
});

it('applies the parent coverage term rather than a child one', function (): void {
    // Structure-level children always carry coverage 1, so averaging their
    // coverage in would average in a constant. "How much of this organ have you
    // met" is a question only the parent can answer.
    $child = $this->calculator->calculate(input(
        attempts: 10, correct: 10, covered: 1, total: 1, lastActivity: '2026-03-01 12:00:00',
    ));

    // 100 × 0.9166… × (0.5 + 0.5 × 0.2) = 55.00
    expect($this->calculator->rollUp([$child], 0.2)->score)->toBe(55.0);
});

it('scores a topic with no attempted children as zero', function (): void {
    $rolled = $this->calculator->rollUp([], 0.0);

    expect($rolled->score)->toBe(0.0)
        ->and($rolled->accuracy)->toBe(0.5);
});

/*
| The breakdown's one behaviour: naming the weakest factor, which is what the
| recommendation templates its sentence from.
*/

it('names the weakest factor', function (MasteryBreakdown $breakdown, MasteryFactor $expected): void {
    expect($breakdown->weakestFactor())->toBe($expected);
})->with([
    'poor accuracy' => [
        fn (): MasteryBreakdown => new MasteryBreakdown(0.3, 1.0, 1.0, 1.0, 30.0),
        MasteryFactor::Accuracy,
    ],
    'gone stale' => [
        fn (): MasteryBreakdown => new MasteryBreakdown(0.9, 0.2, 1.0, 1.0, 18.0),
        MasteryFactor::Recency,
    ],
    'leaning on hints' => [
        fn (): MasteryBreakdown => new MasteryBreakdown(0.95, 0.99, 0.85, 1.0, 79.9),
        MasteryFactor::HintReliance,
    ],
    'barely explored' => [
        // Coverage is compared as the term that enters the formula — 0.5 here,
        // not the raw 0.0 — so it does not automatically win.
        fn (): MasteryBreakdown => new MasteryBreakdown(0.9, 0.9, 1.0, 0.0, 40.5),
        MasteryFactor::Coverage,
    ],
    'low accuracy still beats zero coverage' => [
        fn (): MasteryBreakdown => new MasteryBreakdown(0.4, 1.0, 1.0, 0.0, 20.0),
        MasteryFactor::Accuracy,
    ],
]);

it('gives every factor a templated sentence', function (): void {
    // The reason is templated, never generated (docs/architecture.md §10).
    // Adding a term to the formula must not compile until somebody has written
    // the sentence explaining it to a student.
    foreach (MasteryFactor::cases() as $factor) {
        expect($factor->reason())->not->toBe('');
    }
});
