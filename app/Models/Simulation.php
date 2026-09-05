<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SimulationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One "what happens if…" simulation.
 *
 * State only (docs/engineering.md §3). It holds the `configuration` array and
 * understands none of it: parsing, clamping, threshold evaluation and the
 * visual vocabulary all belong to the simulation service layer, so that a step
 * is computable from a queued job and cannot be computed by a serialised model.
 * Spelled without that layer's namespace on purpose, exactly as App\Models\Organ
 * is: /boundary-audit greps models for it to catch a model that actually
 * depends on a service, and a prose mention would be a permanent false positive
 * in every future audit.
 *
 * That separation is the same one AssessmentService enforces for correctness,
 * and for the same reason. A simulation's whole promise is that the transition
 * is deterministic and reproducible; a model with a `transition()` on it is a
 * second place transitions could happen.
 *
 * @property int $id
 * @property int $organ_id
 * @property string $slug
 * @property string $title
 * @property string|null $description
 * @property string $premise
 * @property array<string, mixed> $configuration
 * @property SimulationStatus $status
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Organ $organ
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SimulationSession> $sessions
 */
class Simulation extends Model
{
    /** @use HasFactory<\Database\Factories\SimulationFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'organ_id',
        'slug',
        'title',
        'description',
        'premise',
        'configuration',
        'status',
    ];

    /**
     * Every deep link addresses a simulation by slug; the integer id is never
     * in a URL, matching Organ and Lesson.
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
            'configuration' => 'array',
            'status' => SimulationStatus::class,
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
     * @return HasMany<SimulationSession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(SimulationSession::class);
    }

    /**
     * The only simulations a student may run.
     *
     * A scope rather than a `where` repeated at each call site: a draft
     * simulation's `configuration` is by definition half-written, and running
     * one would surface a parse failure to a student rather than to its author.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', SimulationStatus::Published);
    }
}
