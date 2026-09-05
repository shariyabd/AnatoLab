<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One assessable question.
 *
 * State only (docs/engineering.md §3). It holds the answer key — the
 * `correct_structure_id` column and the `is_correct` flag on its options — and
 * decides nothing about it. Whether an answer is right is AssessmentService's
 * call, so that grading is reachable from a queued job and cannot be reached
 * from a serialised model (invariant 4).
 *
 * There is deliberately no `lesson()` relation. `lesson_id` is nullable and
 * carries no foreign key (see the migration): `lessons` belongs to Handover 06
 * and lands on a different branch, so a relation here would be a hard
 * dependency on a class this lane cannot guarantee exists. Handover 06 adds
 * the relation from its own side when the two merge.
 *
 * @property int $id
 * @property int|null $lesson_id
 * @property int $organ_id
 * @property QuestionType $type
 * @property string $question
 * @property int $difficulty
 * @property string|null $explanation
 * @property int|null $correct_structure_id
 * @property array<string, mixed>|null $metadata
 * @property QuestionStatus $status
 * @property bool $generated_by_ai
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Organ $organ
 * @property-read AnatomicalStructure|null $correctStructure
 * @property-read \Illuminate\Database\Eloquent\Collection<int, QuestionOption> $options
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Attempt> $attempts
 */
class Question extends Model
{
    /** @use HasFactory<\Database\Factories\QuestionFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'lesson_id',
        'organ_id',
        'type',
        'question',
        'difficulty',
        'explanation',
        'correct_structure_id',
        'metadata',
        'status',
        'generated_by_ai',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => QuestionType::class,
            'status' => QuestionStatus::class,
            'metadata' => 'array',
            'difficulty' => 'integer',
            'generated_by_ai' => 'boolean',
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
     * The answer to a spatial question. Null for every other type.
     *
     * @return BelongsTo<AnatomicalStructure, $this>
     */
    public function correctStructure(): BelongsTo
    {
        return $this->belongsTo(AnatomicalStructure::class, 'correct_structure_id');
    }

    /**
     * Ordered by id so a round is reproducible and a test can name a position.
     *
     * @return HasMany<QuestionOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)->orderBy('id');
    }

    /**
     * @return HasMany<Attempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(Attempt::class);
    }

    /**
     * The only questions a student may be served.
     *
     * A scope rather than a `where` repeated at each call site: a question in
     * `review` is written but not trusted, and one lane forgetting the filter
     * is how an unreviewed AI-generated question reaches a quiz
     * (docs/handovers/07-assessment-engine.md, constraints).
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', QuestionStatus::Published);
    }
}
