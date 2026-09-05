<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MessageRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One turn in a tutor thread.
 *
 * @property int $id
 * @property int $conversation_id
 * @property MessageRole $role
 * @property string $content
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property-read Conversation $conversation
 */
class ConversationMessage extends Model
{
    /** @use HasFactory<\Database\Factories\ConversationMessageFactory> */
    use HasFactory;

    /**
     * Messages are append-only, so there is nothing for an `updated_at` to
     * record. Eloquent still maintains `created_at`.
     */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'conversation_id',
        'role',
        'content',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => MessageRole::class,
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
