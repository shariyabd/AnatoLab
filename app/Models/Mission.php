<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MissionStatus;
use App\Enums\MissionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One multi-step spatial challenge about one organ (PRD §13).
 *
 * State only (docs/engineering.md §3). It holds an answer key — the target
 * sequence inside `configuration` — and decides nothing about it. Whether a
 * step was traced correctly is the mission service layer's call, so that
 * scoring is reachable from a queued job and cannot be reached from a
 * serialised model (invariant 4). The class deliberately does not name that
 * namespace anywhere, not even in prose: /boundary-audit greps app/Models for
 * it to catch a model that really does depend on a service, and a mention here
 * would be a permanent false positive.
 *
 * `configuration` is cast to a plain array rather than to a typed object for
 * the same reason: the parser that turns it into steps and a scoring table
 * lives in the service layer, and a cast pointing at it would be that
 * dependency by another name.
 *
 * @property int $id
 * @property int $organ_id
 * @property string $slug
 * @property string $title
 * @property string|null $description
 * @property MissionType $type
 * @property int $difficulty
 * @property array<string, mixed> $configuration
 * @property MissionStatus $status
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Organ $organ
 * @property-read \Illuminate\Database\Eloquent\Collection<int, MissionAttempt> $attempts
 */
class Mission extends Model
{
    /** @use HasFactory<\Database\Factories\MissionFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'organ_id',
        'slug',
        'title',
        'description',
        'type',
        'difficulty',
        'configuration',
        'status',
    ];

    /**
     * Every mission URL is the slug; the integer id appears only in
     * `mission_attempts.mission_id` (matching Lesson and Organ).
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MissionType::class,
            'difficulty' => 'integer',
            'configuration' => 'array',
            'status' => MissionStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Organ, $this>
     */
    public function organ(): BelongsTo
    {
        return $this->belongsTo(Organ::class);
    }

    /**
     * Every student's runs at this mission.
     *
     * @return HasMany<MissionAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(MissionAttempt::class);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', MissionStatus::Published);
    }
}
