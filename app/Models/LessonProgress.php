<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LessonProgressStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * How far one student has got through one lesson.
 *
 * Written by the lesson service layer from a User passed in as an argument,
 * and by nothing else. Handover 10 reads these rows for mastery and
 * recommendations and must not modify them
 * (docs/handovers/parallel-execution-plan.md, batch D).
 *
 * @property int $id
 * @property int $user_id
 * @property int $lesson_id
 * @property LessonProgressStatus $status
 * @property int $progress_percent
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Lesson $lesson
 */
class LessonProgress extends Model
{
    /** @use HasFactory<\Database\Factories\LessonProgressFactory> */
    use HasFactory;

    /**
     * Eloquent would pluralise LessonProgress to `lesson_progresses`. The
     * table is named in PRD §22 and docs/architecture.md §6, and renaming it
     * would break the one thing Handover 10 was told to read.
     *
     * @var string
     */
    protected $table = 'lesson_progress';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'lesson_id',
        'status',
        'progress_percent',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LessonProgressStatus::class,
            'progress_percent' => 'integer',
            'completed_at' => 'datetime',
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
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', LessonProgressStatus::Completed);
    }
}
