<?php

declare(strict_types=1);

namespace App\Http\Resources\Lessons;

use App\Models\LessonProgress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One student's progress through one lesson.
 *
 * Mirrors `LessonProgressDto` in resources/js/types/lessons.ts. Carries no
 * user identity: the only student it can ever describe is the one making the
 * request, so echoing a user id back would add a field with no reader and one
 * more thing to get wrong.
 *
 * @mixin LessonProgress
 */
final class LessonProgressResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->status->value,
            'progressPercent' => $this->progress_percent,
            'completedAt' => $this->completed_at?->toIso8601String(),
        ];
    }
}
