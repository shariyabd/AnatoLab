<?php

declare(strict_types=1);

namespace App\Http\Resources\Assessment;

use App\Models\QuestionOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One MCQ choice, as the browser sees it.
 *
 * **`is_correct` is not here, and adding it is the single worst change anyone
 * could make to this file.** The client sends back the id of the option it
 * picked and the server decides; correctness never travels outward with a
 * question (invariant 4, docs/architecture.md §5.4 rule 2).
 *
 * Three keys, and the list is exhaustive on purpose — spreading the model or
 * adding `...$this->only(...)` would make the next column someone adds to
 * `question_options` ship to the browser by default.
 * Tests\Feature\Assessment\AnswerKeyAbsenceTest asserts it stays that way.
 *
 * @mixin QuestionOption
 */
final class QuestionOptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'label' => $this->label,
            'value' => $this->value,
        ];
    }
}
