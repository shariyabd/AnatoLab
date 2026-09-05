<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Lesson;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One lesson in the admin listing and editor.
 *
 * Emits `content` whole. A lesson's steps are curriculum, not an answer key —
 * the questions a lesson links to hold the correctness data, and those go
 * through AdminQuestionResource with its policy check.
 *
 * @mixin Lesson
 */
final class AdminLessonResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'description' => $this->description,
            'objective' => $this->objective,
            'difficulty' => $this->difficulty->value,
            'estimatedMinutes' => $this->estimated_minutes,
            'status' => $this->status->value,
            'statusLabel' => $this->status->label(),
            'content' => $this->content,
            'stepCount' => count($this->content['steps'] ?? []),
            'organ' => $this->whenLoaded('organ', fn (): array => [
                'slug' => $this->organ->slug,
                'name' => $this->organ->name,
                'status' => $this->organ->status->value,
            ]),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
