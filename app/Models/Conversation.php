<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConversationContextType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One tutor thread.
 *
 * State only (docs/engineering.md §3). Which thread a question belongs to, and
 * whether the asker owns it, are AITutorService's decisions — a model that
 * scopes itself to auth() cannot be used from a queued job.
 *
 * @property int $id
 * @property int $user_id
 * @property ConversationContextType $context_type
 * @property int|null $context_id
 * @property string $title
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read User $user
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ConversationMessage> $messages
 */
class Conversation extends Model
{
    /** @use HasFactory<\Database\Factories\ConversationFactory> */
    use HasFactory;

    /**
     * `user_id` is absent on purpose. Ownership is assigned by the service from
     * the authenticated User, so mass-assigning it would let a crafted payload
     * file a question under someone else's account.
     *
     * @var list<string>
     */
    protected $fillable = [
        'context_type',
        'context_id',
        'title',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context_type' => ConversationContextType::class,
            'context_id' => 'integer',
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
     * The transcript, oldest first — the order a prompt needs it in.
     *
     * @return HasMany<ConversationMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class)->orderBy('id');
    }
}
