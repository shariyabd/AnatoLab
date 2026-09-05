<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * One badge in the catalogue (PRD §16).
 *
 * State only. Whether a student has earned it is decided by
 * App\Services\Progress\GamificationService against `criteria`; a model that
 * evaluated its own rule would be a model the dashboard could evaluate from,
 * and the evaluation needs mastery, attempts and events that this row knows
 * nothing about (docs/engineering.md §3).
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $description
 * @property string $icon
 * @property array<string, mixed> $criteria
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, User> $earners
 */
class Achievement extends Model
{
    /** @use HasFactory<\Database\Factories\AchievementFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'name',
        'description',
        'icon',
        'criteria',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'criteria' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function earners(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_achievements')
            ->withPivot('earned_at')
            ->withTimestamps();
    }
}
