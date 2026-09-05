<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SimulationStatus;
use App\Models\Organ;
use App\Models\Simulation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Simulation>
 */
final class SimulationFactory extends Factory
{
    protected $model = Simulation::class;

    /**
     * The default configuration is the architecture document's own example
     * (§12), extended only with the bounds and curated prose the engine needs.
     *
     * Deliberately the documented one rather than a random one: most tests in
     * this lane assert an exact number after an exact sequence, and a fixture
     * whose effects vary per run is a fixture no determinism test can use.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = Str::title(fake()->unique()->words(3, true));

        return [
            'organ_id' => Organ::factory(),
            'slug' => Str::slug($title).'-'.fake()->unique()->numerify('###'),
            'title' => $title,
            'description' => fake()->sentence(),
            'premise' => 'A simplified educational model, not a medical tool.',
            'configuration' => self::defaultConfiguration(),
            // Draft by default so a test that means to expose a simulation has
            // to say so, matching OrganFactory and LessonFactory.
            'status' => SimulationStatus::Draft,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SimulationStatus::Published,
        ]);
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    public function withConfiguration(array $configuration): static
    {
        return $this->state(fn (array $attributes): array => [
            'configuration' => $configuration,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultConfiguration(): array
    {
        return [
            'initial_state' => [
                'valve_closure' => 1.0,
                'output' => 1.0,
                'oxygenation' => 0.98,
            ],
            'variables' => [
                'valve_closure' => ['label' => 'Mitral valve closure', 'precision' => 2],
                'output' => ['label' => 'Cardiac output', 'precision' => 2],
                'oxygenation' => ['label' => 'Systemic oxygenation', 'precision' => 2],
            ],
            'actions' => [
                [
                    'id' => 'impair_mitral',
                    'label' => 'Mitral valve does not close fully',
                    'effects' => ['valve_closure' => -0.4, 'output' => -0.25],
                    'visual' => [
                        'highlight' => 'mitral-valve',
                        'tint' => '#d1584f',
                        'pulse_rate' => 1.35,
                    ],
                ],
                [
                    'id' => 'restore_mitral',
                    'label' => 'The valve closes fully again',
                    'effects' => ['valve_closure' => 0.4, 'output' => 0.25],
                    'visual' => ['highlight' => null, 'tint' => null, 'pulse_rate' => 1.0],
                ],
            ],
            'thresholds' => [
                [
                    'when' => 'output < 0.8',
                    'outcome' => 'reduced_systemic_flow',
                    'label' => 'Less blood reaches the body with each beat',
                    'explain_key' => 'sim.heart.reduced_flow',
                ],
            ],
            'explanations' => [
                'baseline' => 'Everything is working normally. Change something to see what happens.',
                'sim.heart.reduced_flow' => 'Some of the blood pushed out of the left ventricle '
                    .'goes backwards through the leaking valve instead of forwards into the aorta, '
                    .'so less of it reaches the body on each beat.',
            ],
        ];
    }
}
