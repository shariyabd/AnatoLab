<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ModelFormat;
use App\Enums\OrganStatus;
use App\Observers\OrganObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An organ, its model, and everything labelled on it.
 *
 * State only. Payload assembly and caching belong to the anatomy service layer,
 * not to this class (docs/engineering.md §3). Spelled without the class's
 * namespace on purpose: /boundary-audit greps models for that namespace to
 * catch a model that actually depends on a service, and a prose mention would
 * be a permanent false positive in every future audit.
 *
 * The observer is attached by attribute rather than in AppServiceProvider so
 * this lane registers cache invalidation without editing a file Handover 01
 * owns (docs/feature-plan.md §7.3).
 *
 * @property int $id
 * @property int $body_system_id
 * @property string $slug
 * @property string $name
 * @property string|null $scientific_name
 * @property string|null $description
 * @property string $model_path
 * @property ModelFormat $model_format
 * @property string|null $thumbnail_path
 * @property string $accent_color
 * @property OrganStatus $status
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read BodySystem $bodySystem
 * @property-read \Illuminate\Database\Eloquent\Collection<int, AnatomicalStructure> $structures
 * @property-read \Illuminate\Database\Eloquent\Collection<int, AnatomicalStructure> $publishedStructures
 */
#[ObservedBy(OrganObserver::class)]
class Organ extends Model
{
    /** @use HasFactory<\Database\Factories\OrganFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'body_system_id',
        'slug',
        'name',
        'scientific_name',
        'description',
        'model_path',
        'model_format',
        'thumbnail_path',
        'accent_color',
        'status',
    ];

    /**
     * The viewer, the Explore page, and every deep link address an organ by
     * slug; the integer id is never in a URL.
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
            'model_format' => ModelFormat::class,
            'status' => OrganStatus::class,
        ];
    }

    /**
     * @return BelongsTo<BodySystem, $this>
     */
    public function bodySystem(): BelongsTo
    {
        return $this->belongsTo(BodySystem::class);
    }

    /**
     * @return HasMany<AnatomicalStructure, $this>
     */
    public function structures(): HasMany
    {
        return $this->hasMany(AnatomicalStructure::class);
    }

    /**
     * The slice the viewer is allowed to see.
     *
     * A separate relation rather than a filter applied at the call site, so
     * every consumer eager-loads the same thing and no lane accidentally ships
     * unverified content by loading `structures` instead.
     *
     * @return HasMany<AnatomicalStructure, $this>
     */
    public function publishedStructures(): HasMany
    {
        return $this->structures()->where('is_published', true)->orderBy('difficulty')->orderBy('name');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', OrganStatus::Published);
    }
}
