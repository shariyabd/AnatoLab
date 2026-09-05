<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One graded answer — spatial, MCQ, short answer, or a mission step.
 *
 * State only. `is_correct` arrives already decided by AssessmentService; a
 * model that graded itself would be a model a controller could be tempted to
 * grade from (docs/engineering.md §3, invariant 4).
 *
 * `user_id` is absent from `$fillable` on purpose, exactly as on
 * App\Models\Conversation: ownership is assigned by the service from the
 * authenticated User it was handed, so a crafted payload cannot file an
 * attempt under someone else's account (docs/engineering.md §10).
 *
 * `is_correct` is likewise not fillable. It is the verdict, and the only code
 * allowed to set it is the grader.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $question_id
 * @property int|null $mission_attempt_id
 * @property int|null $selected_structure_id
 * @property int|null $selected_option_id
 * @property string|null $answer_text
 * @property bool $is_correct
 * @property int|null $time_spent_ms
 * @property bool $hint_used
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read User $user
 * @property-read Question|null $question
 * @property-read AnatomicalStructure|null $selectedStructure
 * @property-read QuestionOption|null $selectedOption
 */
class Attempt extends Model
{
    /** @use HasFactory<\Database\Factories\AttemptFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'question_id',
        'mission_attempt_id',
        'selected_structure_id',
        'selected_option_id',
        'answer_text',
        'time_spent_ms',
        'hint_used',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
            'hint_used' => 'boolean',
            'time_spent_ms' => 'integer',
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
     * @return BelongsTo<Question, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /**
     * @return BelongsTo<AnatomicalStructure, $this>
     */
    public function selectedStructure(): BelongsTo
    {
        return $this->belongsTo(AnatomicalStructure::class, 'selected_structure_id');
    }

    /**
     * @return BelongsTo<QuestionOption, $this>
     */
    public function selectedOption(): BelongsTo
    {
        return $this->belongsTo(QuestionOption::class, 'selected_option_id');
    }

    /**
     * Attempts belonging to one student.
     *
     * Takes the User rather than reading auth(): the same scope has to work
     * from Handover 10's queued mastery job, where there is no request
     * (invariant 1).
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->getKey());
    }
}
