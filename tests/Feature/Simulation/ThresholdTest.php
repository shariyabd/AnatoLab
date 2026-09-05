<?php

declare(strict_types=1);

use App\Services\Simulation\SimulationConfiguration;
use App\Services\Simulation\SimulationEngine;

/*
| Thresholds fire at the right boundaries, and the state clamps at its limits
| (docs/handovers/12-simulations.md, tests).
|
| The boundary cases matter more than the middle ones. `output < 0.8` must not
| fire at exactly 0.8, and it must fire at 0.79 — an off-by-one on a comparison
| operator is invisible until a run lands on the number itself, and a
| simulation is a machine for landing on numbers.
*/

/**
 * @param  array<string, mixed>  $overrides
 */
function thresholdConfig(array $overrides = []): SimulationConfiguration
{
    return SimulationConfiguration::fromArray(array_replace([
        'initial_state' => ['output' => 1.0],
        'variables' => ['output' => ['min' => 0.0, 'max' => 1.0, 'precision' => 2]],
        'actions' => [
            ['id' => 'drop', 'label' => 'Drop', 'effects' => ['output' => -0.2]],
            ['id' => 'nudge', 'label' => 'Nudge', 'effects' => ['output' => -0.01]],
            ['id' => 'raise', 'label' => 'Raise', 'effects' => ['output' => 0.5]],
        ],
        'thresholds' => [
            ['when' => 'output < 0.8', 'outcome' => 'low_flow'],
        ],
    ], $overrides));
}

/**
 * @param  list<string>  $sequence
 */
function finalStep(SimulationConfiguration $configuration, array $sequence)
{
    $steps = app(SimulationEngine::class)->replay('t', $configuration, $sequence);

    return $steps[count($steps) - 1];
}

it('does not fire on the boundary itself', function (): void {
    // 1.0 - 0.2 = 0.8, and `< 0.8` is false at 0.8.
    $step = finalStep(thresholdConfig(), ['drop']);

    expect($step->state->variables['output'])->toBe(0.8)
        ->and($step->outcomes())->toBe([])
        ->and($step->state->stateKey)->toBe(SimulationConfiguration::BASELINE_KEY);
});

it('fires one step past the boundary', function (): void {
    $step = finalStep(thresholdConfig(), ['drop', 'nudge']);

    expect($step->state->variables['output'])->toBe(0.79)
        ->and($step->outcomes())->toBe(['low_flow']);
});

it('evaluates each comparison operator against the clamped state', function (
    string $expression,
    array $sequence,
    bool $expected,
): void {
    $step = finalStep(thresholdConfig([
        'thresholds' => [['when' => $expression, 'outcome' => 'hit']],
    ]), $sequence);

    expect($step->outcomes() === ['hit'])->toBe($expected);
})->with([
    'less than, below' => ['output < 0.8', ['drop', 'nudge'], true],
    'less than, on' => ['output < 0.8', ['drop'], false],
    'at most, on' => ['output <= 0.8', ['drop'], true],
    'greater than, above' => ['output > 0.8', [], true],
    'greater than, on' => ['output > 0.8', ['drop'], false],
    'at least, on' => ['output >= 0.8', ['drop'], true],
    'equal, on' => ['output == 0.8', ['drop'], true],
    'equal, off' => ['output == 0.8', [], false],
    'not equal, off' => ['output != 0.8', [], true],
    'not equal, on' => ['output != 0.8', ['drop'], false],
]);

it('clamps at the floor however many times the action is applied', function (): void {
    $step = finalStep(thresholdConfig(), array_fill(0, 12, 'drop'));

    expect($step->state->variables['output'])->toBe(0.0);
});

it('clamps at the ceiling rather than overshooting it', function (): void {
    $step = finalStep(thresholdConfig(), ['drop', 'raise']);

    expect($step->state->variables['output'])->toBe(1.0);
});

it('returns to the exact starting state when an action is undone', function (): void {
    $configuration = thresholdConfig([
        'actions' => [
            ['id' => 'drop', 'label' => 'Drop', 'effects' => ['output' => -0.2]],
            ['id' => 'undo', 'label' => 'Undo', 'effects' => ['output' => 0.2]],
        ],
    ]);

    $step = finalStep($configuration, ['drop', 'drop', 'undo', 'undo']);

    expect($step->state->variables)->toBe($configuration->initialState);
});

it('names the state after the most severe threshold that fired', function (): void {
    $configuration = thresholdConfig([
        'thresholds' => [
            ['when' => 'output < 0.9', 'outcome' => 'mild', 'label' => 'Mild'],
            ['when' => 'output < 0.5', 'outcome' => 'severe', 'label' => 'Severe', 'terminal' => true],
        ],
    ]);

    $mild = finalStep($configuration, ['drop']);
    $severe = finalStep($configuration, ['drop', 'drop', 'drop']);

    expect($mild->outcomes())->toBe(['mild'])
        ->and($mild->state->stateKey)->toBe('mild')
        ->and($mild->state->isTerminal)->toBeFalse()
        // Both are true at 0.4, and both are reported — the state is named by
        // the last, which is the worst.
        ->and($severe->outcomes())->toBe(['mild', 'severe'])
        ->and($severe->state->stateKey)->toBe('severe')
        ->and($severe->state->label)->toBe('Severe')
        ->and($severe->state->isTerminal)->toBeTrue();
});

it('rounds to the declared precision so a long run cannot drift', function (): void {
    $configuration = thresholdConfig([
        'initial_state' => ['output' => 1.0],
        'variables' => ['output' => ['min' => 0.0, 'max' => 1.0, 'precision' => 3]],
        'actions' => [['id' => 'drop', 'label' => 'Drop', 'effects' => ['output' => -0.1]]],
        'thresholds' => [],
    ]);

    // 1 - 0.1 seven times is 0.29999999999999993 in binary floating point.
    // Rounding each step is what makes this an equality rather than a hope.
    $step = finalStep($configuration, array_fill(0, 7, 'drop'));

    expect($step->state->variables['output'])->toBe(0.3);
});

it('rejects a condition outside the grammar rather than never firing', function (): void {
    thresholdConfig([
        'thresholds' => [['when' => 'output < 0.8 && oxygen > 0.9', 'outcome' => 'compound']],
    ]);
})->throws(InvalidArgumentException::class, 'unsupported condition');

it('rejects a threshold naming a variable the run does not have', function (): void {
    thresholdConfig([
        'thresholds' => [['when' => 'oxygen < 0.9', 'outcome' => 'typo']],
    ]);
})->throws(InvalidArgumentException::class, 'not in `initial_state`');

it('rejects an action affecting a variable the run does not have', function (): void {
    thresholdConfig([
        'actions' => [['id' => 'typo', 'label' => 'Typo', 'effects' => ['ouput' => -0.1]]],
    ]);
})->throws(InvalidArgumentException::class, 'not in `initial_state`');
