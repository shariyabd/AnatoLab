<?php

declare(strict_types=1);

namespace App\Http\Resources\AI;

use App\Contracts\RetrievedChunk;
use App\Services\AI\TutorReply;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The tutor's answer, as the browser sees it.
 *
 * Keys are camelCase, matching every other JSON this application sends —
 * `HandleInertiaRequests` shares `educationLevel`, and Handover 03's Resources
 * mirror the camelCase contract in `resources/js/anatomy/types.ts`.
 * docs/architecture.md §8.2 sketches the envelope as
 * `{ answer, sources[], follow_up_questions[], conversation_id }`; the fields
 * are those, in this codebase's casing.
 *
 * `sources` is an empty array until Handover 11 implements retrieval, and
 * `sourceNote` is the tutor saying so out loud.
 *
 * @property-read TutorReply $resource
 */
final class TutorReplyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'answer' => $this->resource->answer,
            'followUpQuestions' => $this->resource->followUpQuestions,
            'sources' => array_map(
                static fn (RetrievedChunk $chunk): array => [
                    'id' => $chunk->id,
                    'title' => $chunk->sourceTitle,
                    // The passage itself, so the panel can show what was cited
                    // rather than only that something was. No score: a
                    // similarity number means nothing to a 14-year-old and
                    // invites arguing with the retrieval.
                    'excerpt' => $chunk->content,
                ],
                $this->resource->sources,
            ),
            'sourceNote' => $this->resource->sourceNote,
            'conversationId' => $this->resource->conversationId,
        ];
    }
}
