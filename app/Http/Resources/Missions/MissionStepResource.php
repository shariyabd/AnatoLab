<?php

declare(strict_types=1);

namespace App\Http\Resources\Missions;

use App\Services\Assessment\MissionStep;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One mission step, as the browser sees it.
 *
 * **This is the payload the lane exists to get right.** A configured step has
 * four fields and exactly two of them ship:
 *
 * - `structure_id` — the target. This is the answer, literally. It is the
 *   "mission target sequence" docs/architecture.md §5.4 rule 2 names alongside
 *   `is_correct` and `correct_structure_id`, and stripping it here is what
 *   makes the sequence unreachable from a browser.
 * - `explanation` — it names the target in prose, so it is an answer key that
 *   happens to be readable. It is returned by `MissionResultResource` *after*
 *   a run is graded and recorded, never before — the same rule
 *   `questions.explanation` follows (QuestionResource).
 *
 * The key list is written out rather than spread from the step object, so a
 * field added to `MissionStep` later cannot ship to the browser by default.
 * Tests\Feature\Missions\TargetSequenceAbsenceTest asserts the absences on
 * every endpoint and page prop that emits this shape.
 *
 * `index` is the step's position, which the client needs in order to submit
 * positionally. It reveals nothing: the *order of the prompts* is not the
 * order of the answers, and a student who knows a step is third still has to
 * know which structure belongs there.
 *
 * @property-read MissionStep $resource
 */
final class MissionStepResource extends JsonResource
{
    public function __construct(MissionStep $resource, private readonly int $index)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'index' => $this->index,
            'prompt' => $this->resource->prompt,
            // Using it is recorded and it costs points, exactly as a question's
            // hint does (App\Enums\MissionStepOutcome).
            'hint' => $this->resource->hint,
        ];
    }

    /**
     * Every step of a mission, in order, each carrying its own index.
     *
     * A named constructor rather than `collection()`, because the index is
     * positional and a Resource collection does not pass the key to the item.
     *
     * @param  list<MissionStep>  $steps
     * @return list<array<string, mixed>>
     */
    public static function forSteps(array $steps, Request $request): array
    {
        $rendered = [];

        foreach ($steps as $index => $step) {
            $rendered[] = (new self($step, $index))->toArray($request);
        }

        return $rendered;
    }
}
