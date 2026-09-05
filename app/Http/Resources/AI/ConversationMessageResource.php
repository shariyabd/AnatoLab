<?php

declare(strict_types=1);

namespace App\Http\Resources\AI;

use App\Models\ConversationMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One turn of a transcript.
 *
 * `metadata` is deliberately not exposed. It records the model, token usage,
 * and whether the validator intervened — operational detail a student has no
 * use for, and `violation` in particular is a hint about how to phrase the next
 * attempt to get past the validator.
 *
 * @property-read ConversationMessage $resource
 */
final class ConversationMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(),
            'role' => $this->resource->role->value,
            'content' => $this->resource->content,
            'createdAt' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
