<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A badge one student has earned.
 *
 * A model rather than a bare pivot because the dashboard reads these rows
 * directly — "your three most recent badges" is a query over
 * `user_achievements`, ordered by `earned_at`, eager-loading the achievement.
 * Going through `User::achievements()` for that would order by the pivot on a
 * belongsToMany and read less clearly than the thing it is.
 *
 * UNIQUE(user_id, achievement_id) in the migration is what makes awarding
 * idempotent; see App\Jobs\AwardAchievements, which re-evaluates every
 * criterion on every run and relies on the constraint rather than on having
 * been run exactly once.
 *
 * @property int $id
 * @property int $user_id
 * @property int $achievement_id
 * @property Carbon $earned_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Achievement $achievement
 */
class UserAchievement extends Model
{
    /** @use HasFactory<\Database\Factories\UserAchievementFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'achievement_id',
        'earned_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'earned_at' => 'datetime',
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
     * @return BelongsTo<Achievement, $this>
     */
    public function achievement(): BelongsTo
    {
        return $this->belongsTo(Achievement::class);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->getKey());
    }
}
