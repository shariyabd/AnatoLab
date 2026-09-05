<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StructureRelationType;
use App\Observers\StructureRelationObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A directed, typed edge between two structures.
 *
 * A named model rather than a bare pivot because the row carries its own data
 * — the relation verb (docs/engineering.md §2).
 *
 * @property int $id
 * @property int $structure_id
 * @property int $related_structure_id
 * @property StructureRelationType $relation_type
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read AnatomicalStructure $structure
 * @property-read AnatomicalStructure $relatedStructure
 */
#[ObservedBy(StructureRelationObserver::class)]
class StructureRelation extends Model
{
    /** @use HasFactory<\Database\Factories\StructureRelationFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'structure_id',
        'related_structure_id',
        'relation_type',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'relation_type' => StructureRelationType::class,
        ];
    }

    /**
     * @return BelongsTo<AnatomicalStructure, $this>
     */
    public function structure(): BelongsTo
    {
        return $this->belongsTo(AnatomicalStructure::class, 'structure_id');
    }

    /**
     * @return BelongsTo<AnatomicalStructure, $this>
     */
    public function relatedStructure(): BelongsTo
    {
        return $this->belongsTo(AnatomicalStructure::class, 'related_structure_id');
    }
}
