<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TopicType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One student's mastery of one topic — a structure, an organ, or a system.
 *
 * State only, and derived state at that: every column here is written by
 * `RecalculateMastery` from `attempts`, and nothing else may write it
 * (docs/engineering.md §3, docs/architecture.md §10). The model does not know
 * the formula — the formula lives in a pure calculator that takes no model at
 * all, which is what makes it table-driven testable.
 *
 * `topic_id` is a loose reference resolved against the table `topic_type`
 * names. There is no relationship method for it, deliberately: a polymorphic
 * relation across three tables would invite `->topic` in a loop and an N+1 on
 * the dashboard's hot read, and every consumer here already knows which level
 * it asked for.
 *
 * @property int $id
 * @property int $user_id
 * @property TopicType $topic_type
 * @property int $topic_id
 * @property string $mastery_score
 * @property int $attempts
 * @property int $correct_attempts
 * @property int $hinted_attempts
 * @property int $covered_structures
 * @property int $total_structures
 * @property Carbon|null $last_activity_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
class LearningMastery extends Model
{
    /** @use HasFactory<\Database\Factories\LearningMasteryFactory> */
    use HasFactory;

    /**
     * Eloquent would pluralise LearningMastery to `learning_masteries`. The
     * table is named in PRD §22 and docs/architecture.md §6, and "mastery" is
     * a mass noun — the same call App\Models\LessonProgress makes.
     *
     * @var string
     */
    protected $table = 'learning_mastery';

    /**
     * `mastery_score` is fillable because the recalculation writes the whole
     * row in one upsert. It is safe to mass-assign for the reason it is *not*
     * safe on Attempt: no request payload ever reaches this model. The only
     * caller is a queued job that computed the value itself.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'topic_type',
        'topic_id',
        'mastery_score',
        'attempts',
        'correct_attempts',
        'hinted_attempts',
        'covered_structures',
        'total_structures',
        'last_activity_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'topic_type' => TopicType::class,
            // decimal:2 keeps the value a string with exactly the precision the
            // column stores, so what is compared, ordered and displayed is one
            // number (docs/architecture.md §6, model rules).
            'mastery_score' => 'decimal:2',
            'attempts' => 'integer',
            'correct_attempts' => 'integer',
            'hinted_attempts' => 'integer',
            'covered_structures' => 'integer',
            'total_structures' => 'integer',
            'last_activity_at' => 'datetime',
        ];
    }

    /** The score as a number, for arithmetic and for the API. */
    public function score(): float
    {
        return (float) $this->mastery_score;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Rows belonging to one student.
     *
     * Takes the User rather than reading auth(): every caller is either a
     * queued job with no request, or a service handed the student by its
     * controller (invariant 1).
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->getKey());
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOfType(Builder $query, TopicType $type): Builder
    {
        return $query->where('topic_type', $type);
    }
}
