<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\SimulationStatus;
use App\Models\Organ;
use App\Models\Simulation;
use Illuminate\Database\Seeder;

/**
 * The demo simulations (docs/handovers/12-simulations.md).
 *
 * Content, not code. Four things about this data are load-bearing:
 *
 * 1. **The heart valve run is PRD §2.3 step 7.** "What happens if the mitral
 *    valve does not close" is the simulation a judge is shown, so it is the one
 *    that has to be seeded, complete, and curated end to end.
 *
 * 2. **Structures are named by slug, never by id.** Ids are per-database and
 *    every one of these configurations has to survive `migrate:fresh`.
 *    SimulationService resolves them against the organ's published structures
 *    at render time, and SimulationSeederTest asserts every slug here resolves —
 *    an unresolvable one is dropped silently by design, and that test is what
 *    stops the drop being how it is found.
 *
 * 3. **Every reachable outcome has curated prose.** A simulation whose states
 *    are all authored never reaches an AI provider, which makes the demo
 *    independent of a network and of an API key
 *    (App\Services\Simulation\SimulationExplainer). The tutor is the fallback
 *    for a state nobody wrote for, not the default path.
 *
 * 4. **Every run is reversible.** Each simulation ships the action that undoes
 *    its damage, because clamping is only visible when a student can drive a
 *    variable back to its ceiling — and because "what happens if it stops" is
 *    half the lesson of "what happens if".
 *
 * Idempotent by slug: `db:seed` twice produces the same database, not doubled
 * rows (docs/engineering.md §6).
 */
final class SimulationSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->simulations() as $definition) {
            $organ = Organ::query()->where('slug', $definition['organ'])->first();

            // The anatomy seeder owns the organs. If one is missing the demo
            // dataset is already broken elsewhere, and inventing an organ here
            // would hide it.
            if (! $organ instanceof Organ) {
                continue;
            }

            Simulation::query()->updateOrCreate(
                ['slug' => $definition['slug']],
                [
                    'organ_id' => $organ->getKey(),
                    'title' => $definition['title'],
                    'description' => $definition['description'],
                    'premise' => $definition['premise'],
                    'configuration' => $definition['configuration'],
                    'status' => SimulationStatus::Published,
                ],
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function simulations(): array
    {
        return [
            [
                'organ' => 'heart',
                'slug' => 'mitral-valve-closure',
                'title' => 'What happens if the mitral valve does not close?',
                'description' => 'Follow one leaking valve through the left side of the heart '
                    .'and watch what it costs the rest of the body.',
                'premise' => 'A healthy heart, with one thing changed at a time. '
                    .'The numbers are simplified teaching values, not clinical measurements.',
                'configuration' => $this->mitralValve(),
            ],
            [
                'organ' => 'lungs',
                'slug' => 'airway-obstruction',
                'title' => 'What happens if an airway is blocked?',
                'description' => 'Narrow one main bronchus and see which parts of the lungs '
                    .'stop pulling their weight.',
                'premise' => 'A healthy pair of lungs, with one airway narrowed. '
                    .'The numbers are simplified teaching values, not clinical measurements.',
                'configuration' => $this->airwayObstruction(),
            ],
        ];
    }

    /**
     * The architecture document's own worked example (§12), completed.
     *
     * Thresholds are declared mildest first, which is the convention the engine
     * relies on: the last one to fire is the one that names the state, so a run
     * is called by the most severe thing currently true about it.
     *
     * @return array<string, mixed>
     */
    private function mitralValve(): array
    {
        return [
            'baseline_label' => 'Beating normally',

            'initial_state' => [
                'valve_closure' => 1.0,
                'output' => 1.0,
                'oxygenation' => 0.98,
            ],

            'variables' => [
                'valve_closure' => [
                    'label' => 'Mitral valve closure',
                    'min' => 0.0,
                    'max' => 1.0,
                    'precision' => 2,
                    'unit' => 'of normal',
                ],
                'output' => [
                    'label' => 'Cardiac output',
                    'min' => 0.0,
                    'max' => 1.0,
                    'precision' => 2,
                    'unit' => 'of normal',
                ],
                'oxygenation' => [
                    'label' => 'Blood oxygen saturation',
                    'min' => 0.0,
                    // The ceiling is the resting value, not 1.0. Arterial blood
                    // leaving healthy lungs is about 98% saturated and cannot go
                    // higher by breathing harder, so `restore_mitral` clamps
                    // back to exactly where the run started instead of
                    // overshooting it.
                    'max' => 0.98,
                    'precision' => 2,
                    'unit' => 'saturation',
                ],
            ],

            'actions' => [
                [
                    'id' => 'impair_mitral',
                    'label' => 'The mitral valve does not close fully',
                    'description' => 'The two flaps between the left atrium and the left ventricle '
                        .'no longer meet, so the seal leaks when the ventricle squeezes.',
                    'effects' => ['valve_closure' => -0.4, 'output' => -0.25],
                    'visual' => [
                        'highlight' => 'mitral-valve',
                        'tint' => '#d1584f',
                        'pulse_rate' => 1.35,
                    ],
                ],
                [
                    'id' => 'raise_demand',
                    'label' => 'The body starts exercising',
                    'description' => 'Muscles ask for more blood than they do at rest.',
                    'effects' => ['output' => -0.15, 'oxygenation' => -0.04],
                    'visual' => ['focus' => 'left-ventricle', 'pulse_rate' => 1.9],
                ],
                [
                    'id' => 'cut_away',
                    'label' => 'Cut the heart open to look inside',
                    'description' => 'Slice through the model so the chambers behind the wall are visible.',
                    'effects' => [],
                    'visual' => ['cross_section' => ['enabled' => true, 'axis' => 'z', 'offset' => 0.0]],
                ],
                [
                    'id' => 'restore_mitral',
                    'label' => 'The valve seals properly again',
                    'description' => 'The flaps meet, the leak stops, and the heart is back where it started.',
                    'effects' => ['valve_closure' => 0.4, 'output' => 0.4, 'oxygenation' => 0.04],
                    'visual' => [
                        'highlight' => null,
                        'tint' => null,
                        'pulse_rate' => 1.0,
                        'cross_section' => ['enabled' => false],
                    ],
                ],
            ],

            'thresholds' => [
                [
                    'when' => 'valve_closure < 0.9',
                    'outcome' => 'mitral_regurgitation',
                    'label' => 'Blood leaks backwards into the left atrium',
                    'explain_key' => 'sim.heart.regurgitation',
                ],
                [
                    'when' => 'output < 0.8',
                    'outcome' => 'reduced_systemic_flow',
                    'label' => 'Less blood reaches the body with each beat',
                    'explain_key' => 'sim.heart.reduced_flow',
                ],
                [
                    'when' => 'oxygenation < 0.95',
                    'outcome' => 'reduced_oxygen_delivery',
                    'label' => 'Tissues are receiving less oxygen than they are asking for',
                    'explain_key' => 'sim.heart.reduced_oxygen',
                    'terminal' => true,
                ],
            ],

            'explanations' => [
                'baseline' => 'The left ventricle fills, the mitral valve snaps shut, and every '
                    .'drop of blood the ventricle squeezes leaves through the aorta. '
                    .'Change something on the left and watch where the blood goes instead.',

                'sim.heart.regurgitation' => 'When the ventricle contracts, blood takes the '
                    .'easiest route out. A valve that does not seal is an easier route than the '
                    .'aorta, so some of the blood travels backwards into the left atrium instead '
                    .'of forwards into the body. That backwards flow is called regurgitation, and '
                    .'it is why the same squeeze now moves less blood where it is needed.',

                'sim.heart.reduced_flow' => 'Cardiac output is how much blood leaves the heart '
                    .'each minute. The ventricle is working just as hard as before, but part of '
                    .'each squeeze is spent pushing blood the wrong way, so less arrives in the '
                    .'aorta. The heart usually answers by beating faster — which is why the '
                    .'marker is pulsing more quickly than it was.',

                'sim.heart.reduced_oxygen' => 'Oxygen reaches a muscle in the blood that carries '
                    .'it, so delivery depends on flow as much as on the lungs. With less blood '
                    .'arriving each minute and the body asking for more than it does at rest, the '
                    .'tissues take more oxygen out of every drop and the saturation measured on '
                    .'the way back falls.',
            ],
        ];
    }

    /**
     * PRD §14's "airflow is obstructed" example.
     *
     * A second organ on purpose: it proves the engine is a property of the
     * configuration and not of the heart, and it gives the picker something to
     * be a picker of.
     *
     * @return array<string, mixed>
     */
    private function airwayObstruction(): array
    {
        return [
            'baseline_label' => 'Breathing normally',

            'initial_state' => [
                'airway_diameter' => 1.0,
                'right_lung_ventilation' => 1.0,
                'gas_exchange' => 0.97,
            ],

            'variables' => [
                'airway_diameter' => [
                    'label' => 'Right main bronchus width',
                    'min' => 0.0,
                    'max' => 1.0,
                    'precision' => 2,
                    'unit' => 'of normal',
                ],
                'right_lung_ventilation' => [
                    'label' => 'Air reaching the right lung',
                    'min' => 0.0,
                    'max' => 1.0,
                    'precision' => 2,
                    'unit' => 'of normal',
                ],
                'gas_exchange' => [
                    'label' => 'Oxygen crossing into the blood',
                    'min' => 0.0,
                    // Same reasoning as the heart's oxygenation ceiling: the
                    // resting value is the maximum, so clearing the airway
                    // returns the run to its exact starting state.
                    'max' => 0.97,
                    'precision' => 2,
                    'unit' => 'of normal',
                ],
            ],

            'actions' => [
                [
                    'id' => 'narrow_bronchus',
                    'label' => 'The right main bronchus narrows',
                    'description' => 'The airway walls swell and the passage through them shrinks.',
                    'effects' => [
                        'airway_diameter' => -0.5,
                        'right_lung_ventilation' => -0.45,
                        'gas_exchange' => -0.06,
                    ],
                    'visual' => [
                        'highlight' => 'right-main-bronchus',
                        'tint' => '#c9772f',
                        'pulse_rate' => 1.4,
                    ],
                ],
                [
                    'id' => 'inspect_carina',
                    'label' => 'Look at where the airway splits',
                    'description' => 'Fly the camera to the carina, where the trachea divides in two.',
                    'effects' => [],
                    'visual' => ['focus' => 'carina'],
                ],
                [
                    'id' => 'clear_bronchus',
                    'label' => 'The swelling settles and the airway opens',
                    'description' => 'The passage widens again and air reaches the whole lung.',
                    'effects' => [
                        'airway_diameter' => 0.5,
                        'right_lung_ventilation' => 0.45,
                        'gas_exchange' => 0.06,
                    ],
                    'visual' => ['highlight' => null, 'tint' => null, 'pulse_rate' => 1.0],
                ],
            ],

            'thresholds' => [
                [
                    'when' => 'right_lung_ventilation < 0.8',
                    'outcome' => 'underventilated_lung',
                    'label' => 'The right lung is receiving less air than the left',
                    'explain_key' => 'sim.lungs.underventilated',
                ],
                [
                    'when' => 'gas_exchange < 0.93',
                    'outcome' => 'reduced_gas_exchange',
                    'label' => 'Less oxygen is crossing into the blood',
                    'explain_key' => 'sim.lungs.reduced_exchange',
                    'terminal' => true,
                ],
            ],

            'explanations' => [
                'baseline' => 'Air travels down the trachea, splits at the carina, and fills both '
                    .'lungs evenly. Narrow one side and watch the two lungs stop matching.',

                'sim.lungs.underventilated' => 'Air takes the widest path available. Narrowing the '
                    .'right main bronchus does not stop breathing — it sends a larger share of '
                    .'every breath down the left side instead, so the right lung inflates less '
                    .'with each one. That is why the highlighted airway is the one to watch '
                    .'rather than the lung behind it.',

                'sim.lungs.reduced_exchange' => 'Oxygen crosses into the blood at the alveoli, and '
                    .'only where fresh air and blood flow meet. Blood still flows past the '
                    .'under-inflated right lung, but the air there is stale, so that blood returns '
                    .'to the heart carrying less oxygen than the blood from the left. Mixed '
                    .'together, the total crossing into circulation falls.',
            ],
        ];
    }
}
