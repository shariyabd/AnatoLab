<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One student's run at one mission — the envelope around its graded steps.
 *
 * State only. `score`, `completed` and `result` arrive already decided by the
 * mission service layer; a model that scored itself would be a model a
 * controller could be tempted to score from (matching Attempt, invariant 4).
 *
 * `user_id` is absent from `$fillable` on purpose, exactly as on Attempt and
 * Conversation: ownership is assigned by the service from the authenticated
 * User it was handed, so a crafted payload cannot file a run under someone
 * else's account (docs/engineering.md §10). `score` and `completed` are
 * likewise not fillable — they are the verdict.
 *
 * @property int $id
 * @property int $user_id
 * @property int $mission_id
 * @property int $score
 * @property bool $completed
 * @property int|null $duration_ms
 * @property array<string, mixed> $result
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read User $user
 * @property-read Mission $mission
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Attempt> $stepAttempts
 */
class MissionAttempt extends Model
{
    /** @use HasFactory<\Database\Factories\MissionAttemptFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'mission_id',
        'duration_ms',
        'result',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'completed' => 'boolean',
            'duration_ms' => 'integer',
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
     * @return BelongsTo<Mission, $this>
     */
    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class);
    }

    /**
     * The graded steps of this run, in Handover 07's `attempts` table.
     *
     * Declared from this side only. The matching `missionAttempt()` belongsTo
     * on Attempt is not added, because `attempts` belongs to Handover 07 and
     * this lane does not alter it
     * (docs/handovers/parallel-execution-plan.md D5). A relation defined here
     * needs nothing from there but the column, which is already present and
     * indexed.
     *
     * Named `stepAttempts` rather than `attempts` so that `$mission->attempts`
     * (runs) and `$run->stepAttempts` (graded steps) cannot be misread for one
     * another — they are two different things one word away from each other.
     *
     * @return HasMany<Attempt, $this>
     */
    public function stepAttempts(): HasMany
    {
        return $this->hasMany(Attempt::class, 'mission_attempt_id');
    }

    /**
     * Runs belonging to one student.
     *
     * Takes the User rather than reading the authenticated one: the same scope
     * has to work from Handover 10's queued mastery job, where there is no
     * request (invariant 1, matching Attempt::scopeForUser).
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->getKey());
    }
}
