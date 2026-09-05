<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One student's run of one simulation.
 *
 * State only. `events` is the run — an ordered list of the action ids applied,
 * each with the state and outcomes it produced — and `state` and `result` are
 * caches of replaying it (see the migration). Nothing here recomputes them;
 * SimulationService does, through the engine, on every step.
 *
 * `user_id` is not fillable. Ownership is assigned from the User passed into
 * the service and would be forgeable if a request array could reach it
 * (docs/engineering.md §10) — the same guard App\Models\Attempt uses.
 *
 * @property int $id
 * @property int $user_id
 * @property int $simulation_id
 * @property int|null $conversation_id
 * @property array<string, float|int> $state
 * @property list<array<string, mixed>> $events
 * @property array<string, mixed>|null $result
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read User $user
 * @property-read Simulation $simulation
 * @property-read Conversation|null $conversation
 */
class SimulationSession extends Model
{
    /** @use HasFactory<\Database\Factories\SimulationSessionFactory> */
    use HasFactory;

    /**
     * `user_id` and `simulation_id` are deliberately absent — see the class
     * docblock. Both are associated by the service.
     *
     * @var list<string>
     */
    protected $fillable = [
        'conversation_id',
        'state',
        'events',
        'result',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => 'array',
            'events' => 'array',
            'result' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Simulation, $this>
     */
    public function simulation(): BelongsTo
    {
        return $this->belongsTo(Simulation::class);
    }

    /**
     * The tutor thread carrying this run's generated explanations, if any.
     *
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * The ordered action ids this run consists of.
     *
     * This is the only part of `events` a replay reads. Everything else in a
     * log entry is a record of what that step produced, kept for the event log
     * the UI renders and for an audit — never fed back into the engine.
     *
     * @return list<string>
     */
    public function actionSequence(): array
    {
        $ids = [];

        foreach ($this->events as $event) {
            $actionId = $event['action_id'] ?? null;

            if (is_string($actionId) && $actionId !== '') {
                $ids[] = $actionId;
            }
        }

        return $ids;
    }
}
