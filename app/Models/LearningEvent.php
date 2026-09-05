<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LearningEventType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One thing a student did, at the moment they did it (PRD §29).
 *
 * Append-only, and the model says so: `$timestamps` is off and `created_at` is
 * set on insert by the writer. Nothing updates a row here, so an `updated_at`
 * would be a column that is always equal to `created_at` and a method surface
 * that invites a rewrite of history the metrics are computed from
 * (docs/architecture.md §13).
 *
 * `payload` is free-form per event type and is never rendered as-is. It exists
 * so a later question about *how* a student worked — which layer, which
 * related structure — can be answered without a schema change, not as a place
 * to put anything a page needs to read.
 *
 * @property int $id
 * @property int $user_id
 * @property LearningEventType $event_type
 * @property string|null $context_type
 * @property int|null $context_id
 * @property array<string, mixed>|null $payload
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 * @property-read User $user
 */
class LearningEvent extends Model
{
    /** @use HasFactory<\Database\Factories\LearningEventFactory> */
    use HasFactory;

    /**
     * There is no `updated_at` column — see the class docblock. Eloquent would
     * otherwise try to write one on every insert.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'event_type',
        'context_type',
        'context_id',
        'payload',
        'occurred_at',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_type' => LearningEventType::class,
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
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
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->getKey());
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOfType(Builder $query, LearningEventType $type): Builder
    {
        return $query->where('event_type', $type);
    }
}
