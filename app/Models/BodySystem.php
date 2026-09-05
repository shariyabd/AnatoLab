<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An organ system — cardiovascular, respiratory, nervous.
 *
 * State only (docs/engineering.md §3). It exists so mastery can be reported
 * per system rather than per organ (docs/architecture.md §6, §10).
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Organ> $organs
 */
class BodySystem extends Model
{
    /** @use HasFactory<\Database\Factories\BodySystemFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'name',
        'description',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return HasMany<Organ, $this>
     */
    public function organs(): HasMany
    {
        return $this->hasMany(Organ::class);
    }
}
