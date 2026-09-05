<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Simulation;
use App\Models\SimulationSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SimulationSession>
 */
final class SimulationSessionFactory extends Factory
{
    protected $model = SimulationSession::class;

    /**
     * An untouched run. `user_id` and `simulation_id` are named here even
     * though neither is fillable: factories build models unguarded, which is
     * how AttemptFactory sets `user_id` and `is_correct` too. The guard exists
     * to stop a *request array* choosing an owner, not a test.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'simulation_id' => Simulation::factory(),
            'conversation_id' => null,
            'state' => ['valve_closure' => 1.0, 'output' => 1.0, 'oxygenation' => 0.98],
            'events' => [],
            'result' => null,
        ];
    }
}
