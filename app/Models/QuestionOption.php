<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One choice on a multiple-choice question.
 *
 * `is_correct` is an answer key. Nothing here hides it — hiding is the API
 * Resource's job and only the Resource's job (docs/architecture.md §7) — but
 * note that this model must never be returned from a controller directly.
 * QuestionOptionResource is the only place it becomes JSON, and it emits three
 * keys, none of them this one (invariant 4, docs/engineering.md §5).
 *
 * @property int $id
 * @property int $question_id
 * @property string $label
 * @property string $value
 * @property bool $is_correct
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Question $question
 */
class QuestionOption extends Model
{
    /** @use HasFactory<\Database\Factories\QuestionOptionFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'question_id',
        'label',
        'value',
        'is_correct',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Question, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
