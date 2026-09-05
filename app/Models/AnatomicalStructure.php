<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\AnatomicalStructureObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One labelled structure within an organ.
 *
 * `anchor_position` is a three-float array in the FIT_SIZE = 3.8 pivot space
 * every model is normalised into. It is cast to `array` and never to anything
 * cleverer: the viewer receives it as a tuple and hands it straight to
 * Three.js (docs/architecture.md §5.4).
 *
 * `model_object_name` is always null today. It is here so that replacing the
 * single-mesh assets with per-structure geometry needs no schema change
 * (docs/project-context.md §5.5).
 *
 * @property int $id
 * @property int $organ_id
 * @property string $slug
 * @property string|null $ta_term
 * @property string $name
 * @property string|null $scientific_name
 * @property string|null $description
 * @property string|null $function
 * @property string|null $location
 * @property int $difficulty
 * @property array{0: float, 1: float, 2: float} $anchor_position
 * @property string|null $model_object_name
 * @property string|null $marker_color
 * @property array<string, mixed>|null $metadata
 * @property bool $is_published
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Organ $organ
 * @property-read \Illuminate\Database\Eloquent\Collection<int, AnatomicalStructure> $relatedStructures
 * @property-read \Illuminate\Database\Eloquent\Collection<int, AnatomicalStructure> $publishedRelatedStructures
 * @property-read \Illuminate\Database\Eloquent\Collection<int, StructureRelation> $structureRelations
 */
#[ObservedBy(AnatomicalStructureObserver::class)]
class AnatomicalStructure extends Model
{
    /** @use HasFactory<\Database\Factories\AnatomicalStructureFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'organ_id',
        'slug',
        'ta_term',
        'name',
        'scientific_name',
        'description',
        'function',
        'location',
        'difficulty',
        'anchor_position',
        'model_object_name',
        'marker_color',
        'metadata',
        'is_published',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'anchor_position' => 'array',
            'metadata' => 'array',
            'difficulty' => 'integer',
            'is_published' => 'boolean',
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
     * The typed rows, for anything that needs the relation verb itself.
     *
     * Named `structureRelations` rather than `relations` because Eloquent
     * already owns a `$relations` property — a relationship method of that
     * name resolves, then collides with the loaded-relations array.
     *
     * @return HasMany<StructureRelation, $this>
     */
    public function structureRelations(): HasMany
    {
        return $this->hasMany(StructureRelation::class, 'structure_id');
    }

    /**
     * The related structures themselves, with the verb on the pivot.
     *
     * @return BelongsToMany<AnatomicalStructure, $this, \Illuminate\Database\Eloquent\Relations\Pivot>
     */
    public function relatedStructures(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'structure_relations',
            'structure_id',
            'related_structure_id',
        )->withPivot('relation_type')->withTimestamps();
    }

    /**
     * The related structures a student is allowed to be shown.
     *
     * A relation rather than a closure passed to with(), so that every caller
     * eager-loads the same filtered set and no lane can surface unverified
     * content by loading `relatedStructures` instead.
     *
     * @return BelongsToMany<AnatomicalStructure, $this, \Illuminate\Database\Eloquent\Relations\Pivot>
     */
    public function publishedRelatedStructures(): BelongsToMany
    {
        return $this->relatedStructures()->where('is_published', true);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }
}
