<?php

declare(strict_types=1);

namespace App\Http\Resources\Assessment;

use App\Http\Resources\Anatomy\OrganResource;
use App\Services\Assessment\Quiz;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A quiz: an organ to look at, and the questions asked about it.
 *
 * `organ` is rendered by Handover 03's `OrganResource`, unchanged, so the quiz
 * page hands `useAnatomyViewer` exactly the `OrganDto` the frozen contract
 * describes (resources/js/anatomy/types.ts). Restating the organ shape here
 * would be a second copy of the project's most-consumed contract, drifting
 * silently the first time either side changed (docs/feature-plan.md §7.8).
 *
 * `slug` is the organ's — there is no `quizzes` table, and `{quiz}` in the API
 * path is an organ slug (App\Services\Assessment\Quiz).
 *
 * @property-read Quiz $resource
 */
final class QuizResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $organ = $this->resource->organ;

        return [
            'slug' => $organ->slug,
            'title' => $organ->name.' quiz',
            'questionCount' => $this->resource->questions->count(),
            'organ' => new OrganResource($organ),
            'questions' => QuestionResource::collection($this->resource->questions),
        ];
    }
}
