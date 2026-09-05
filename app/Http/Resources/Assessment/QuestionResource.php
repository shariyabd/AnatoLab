<?php

declare(strict_types=1);

namespace App\Http\Resources\Assessment;

use App\Enums\QuestionType;
use App\Models\Question;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One question, as the browser sees it.
 *
 * This is the payload invariant 4 exists for. Four things on the model are
 * deliberately absent, and each one is an answer key in its own way:
 *
 * - `correct_structure_id` — the answer to a spatial question, literally.
 * - `explanation` — it names the right structure in prose. It is returned by
 *   `AttemptResultResource` *after* an answer is recorded, never before.
 * - `metadata` — holds the short-answer rubric, which lists the accepted
 *   answers. Only the authored `hint` is lifted out of it, by name.
 * - `status`, `lesson_id`, `generated_by_ai` — not secret, but not the
 *   student's business either; Handover 13's admin Resources may expose them.
 *
 * The key list is exhaustive and written out rather than spread from the
 * model, so a column added to `questions` later cannot ship to the browser by
 * default. Tests\Feature\Assessment\AnswerKeyAbsenceTest asserts the absences
 * on every endpoint that emits this shape.
 *
 * `id` values are stringified for the same reason `StructureResource` does it:
 * the client treats every identifier as an opaque token it hands back
 * unchanged (docs/architecture.md §5.4 rule 4).
 *
 * @mixin Question
 */
final class QuestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'type' => $this->type->value,
            'question' => $this->question,
            'difficulty' => $this->difficulty,
            'hint' => self::hintFrom($this->metadata),
            // Empty for spatial and short-answer questions. Always present so
            // the client has one shape to type rather than an optional key.
            'options' => QuestionOptionResource::collection(
                $this->type === QuestionType::Mcq ? $this->options : collect(),
            ),
        ];
    }

    /**
     * The one value lifted out of `metadata`, by name.
     *
     * A whitelist rather than a blacklist: `metadata` is a JSON column that
     * Handover 13's authoring tool and the AI question generator both write
     * to, so what it contains tomorrow is not knowable from here. Emitting all
     * of it minus the rubric would ship the next answer-shaped key someone
     * adds.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    private static function hintFrom(?array $metadata): ?string
    {
        $hint = $metadata['hint'] ?? null;

        return is_string($hint) && $hint !== '' ? $hint : null;
    }
}
