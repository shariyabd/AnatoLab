<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DifficultyPreference;
use App\Enums\LessonStatus;
use App\Observers\LessonObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One structured lesson about one organ (PRD §8).
 *
 * State only. Reading, caching and progress belong to the lesson service layer
 * (docs/engineering.md §3). The class deliberately does not spell out the
 * service namespace anywhere, not even in prose: /boundary-audit greps
 * app/Models for it to catch a model that really does depend on a service, and
 * a mention here would be a permanent false positive.
 *
 * The observer is attached by attribute rather than in AppServiceProvider so
 * this lane registers its cache invalidation without editing a file Handover
 * 01 owns (docs/feature-plan.md §7.3).
 *
 * @property int $id
 * @property int $organ_id
 * @property string $slug
 * @property string $title
 * @property string|null $description
 * @property string $objective
 * @property DifficultyPreference $difficulty
 * @property int $estimated_minutes
 * @property array<string, mixed> $content
 * @property LessonStatus $status
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Organ $organ
 * @property-read \Illuminate\Database\Eloquent\Collection<int, LessonProgress> $progress
 */
#[ObservedBy(LessonObserver::class)]
class Lesson extends Model
{
    /** @use HasFactory<\Database\Factories\LessonFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'organ_id',
        'slug',
        'title',
        'description',
        'objective',
        'difficulty',
        'estimated_minutes',
        'content',
        'status',
    ];

    /**
     * Every lesson URL and every cache key is the slug; the integer id appears
     * only in `lesson_progress.lesson_id` (matching Organ).
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
            'content' => 'array',
            'difficulty' => DifficultyPreference::class,
            'estimated_minutes' => 'integer',
            'status' => LessonStatus::class,
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
     * Every student's progress through this lesson.
     *
     * Named `progress` rather than `lessonProgress` because the model is
     * already scoped by the class it hangs off. Handover 10 reads this
     * relation; nothing outside this lane writes it.
     *
     * @return HasMany<LessonProgress, $this>
     */
    public function progress(): HasMany
    {
        return $this->hasMany(LessonProgress::class);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', LessonStatus::Published);
    }
}
