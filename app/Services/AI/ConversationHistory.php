<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Reads a student's tutor threads back.
 *
 * Separate from AITutorService because answering a question and listing past
 * questions are two reasons to exist, and because this one touches no provider,
 * no prompt, and no validator (docs/engineering.md §2).
 *
 * Every method takes the User and scopes to them. There is no "find by id"
 * without an owner — that signature is how `Conversation::find($id)` ends up in
 * a controller and someone reads someone else's thread.
 */
final class ConversationHistory
{
    /**
     * Most recently active threads first.
     *
     * @return Collection<int, Conversation>
     */
    public function forUser(User $user, int $limit = 20): Collection
    {
        /** @var Collection<int, Conversation> $conversations */
        $conversations = Conversation::query()
            ->where('user_id', $user->getKey())
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        return $conversations;
    }

    /**
     * One thread with its transcript, or null if it is not this user's.
     *
     * Null rather than an exception, so the controller answers 404 for both
     * "does not exist" and "not yours" — distinguishing them would confirm that
     * someone else's conversation id is real.
     */
    public function transcriptFor(User $user, int $conversationId): ?Conversation
    {
        return Conversation::query()
            ->where('user_id', $user->getKey())
            ->whereKey($conversationId)
            // Eager loaded: Model::shouldBeStrict() turns a lazy load in the
            // Resource into an exception rather than an N+1 nobody notices.
            ->with('messages')
            ->first();
    }
}
