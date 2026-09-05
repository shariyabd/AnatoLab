<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Question;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One question as an administrator sees it — answer key included.
 *
 * This is the single sanctioned exception to invariant 4 in the whole
 * codebase. A reviewer cannot approve a question whose correct answer they
 * cannot see (PRD §24), so the key is emitted here and nowhere else.
 *
 * Two things make that safe, and both are required:
 *
 * 1. The route is behind the `admin` middleware.
 * 2. **This Resource asks the policy itself.** Being rendered inside the admin
 *    area is not sufficient — a Resource reached from anywhere else, by a
 *    future route added to the wrong group, still emits nothing. Middleware
 *    guards the area; QuestionPolicy::viewAnswerKey() guards the field
 *    (docs/architecture.md §14, docs/engineering.md §10).
 *
 * When the ability is denied the key is *absent*, not null: an explicitly null
 * `correctOptionId` still tells a reader the field exists and invites a client
 * to depend on it.
 *
 * @mixin Question
 */
final class AdminQuestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = [
            'id' => (string) $this->id,
            'type' => $this->type->value,
            'typeLabel' => $this->type->label(),
            'question' => $this->question,
            'difficulty' => $this->difficulty,
            'status' => $this->status->value,
            'statusLabel' => $this->status->label(),
            'generatedByAi' => $this->generated_by_ai,
            'explanation' => $this->explanation,
            'organ' => $this->whenLoaded('organ', fn (): array => [
                'slug' => $this->organ->slug,
                'name' => $this->organ->name,
            ]),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];

        if (! self::maySeeAnswerKey($request, $this->resource)) {
            return $payload;
        }

        return [
            ...$payload,
            'correctStructureId' => $this->correct_structure_id === null
                ? null
                : (string) $this->correct_structure_id,
            'correctStructureName' => $this->whenLoaded(
                'correctStructure',
                fn (): ?string => $this->correctStructure?->name,
            ),
            'options' => $this->whenLoaded('options', fn (): array => $this->options
                ->map(static fn ($option): array => [
                    'id' => (string) $option->id,
                    'label' => $option->label,
                    'value' => $option->value,
                    'isCorrect' => $option->is_correct,
                ])
                ->all()),
        ];
    }

    private static function maySeeAnswerKey(Request $request, Question $question): bool
    {
        $user = $request->user();

        return $user instanceof User && $user->can('viewAnswerKey', $question);
    }
}
