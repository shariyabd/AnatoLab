<?php

declare(strict_types=1);

namespace App\Http\Resources\AI;

use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A tutor thread, with its transcript when one has been loaded.
 *
 * `user_id` never ships: the only conversations a student can reach are their
 * own, so echoing the owner back tells them nothing and tells anyone else
 * something.
 *
 * @property-read Conversation $resource
 */
final class ConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(),
            'title' => $this->resource->title,
            'contextType' => $this->resource->context_type->value,
            'contextId' => $this->resource->context_id,
            'updatedAt' => $this->resource->updated_at?->toIso8601String(),
            // whenLoaded, so the list endpoint does not lazy load a transcript
            // per row — Model::shouldBeStrict() would throw, which is the point.
            'messages' => ConversationMessageResource::collection(
                $this->whenLoaded('messages')
            ),
        ];
    }
}
