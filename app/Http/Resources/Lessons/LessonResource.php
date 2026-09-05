<?php

declare(strict_types=1);

namespace App\Http\Resources\Lessons;

use App\Enums\LessonStepType;
use App\Http\Resources\Anatomy\OrganResource;
use App\Models\Lesson;
use App\Models\LessonProgress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One lesson, its ordered steps, and the organ its 3D steps are set on.
 *
 * `organ` is a full OrganDto rather than a summary, because the exploration
 * step hands it straight to ViewerStage — the mounting pattern Handover 05
 * documented and this lane was told to copy
 * (resources/js/Components/Anatomy/ViewerStage.vue).
 *
 * Steps are read back through LessonStepType and anything unrecognised is
 * dropped. `content` is a JSON column an admin tool will eventually write
 * (F13), and the page renders each step by looking its type up in a component
 * map — a type with no component would render nothing, silently, in the middle
 * of a lesson. Dropping it here at least makes the step count honest.
 *
 * A `knowledge_check` step's payload carries a reference and a prompt and
 * never an answer: F07 owns the question and its grading, this lane owns only
 * where the question sits in the sequence (docs/handovers/06-lessons.md).
 *
 * @mixin Lesson
 */
final class LessonResource extends JsonResource
{
    public function __construct(Lesson $lesson, private readonly ?LessonProgress $progress = null)
    {
        parent::__construct($lesson);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $steps = self::steps($this->resource);

        return [
            'id' => (string) $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'description' => $this->description,
            'objective' => $this->objective,
            'difficulty' => $this->difficulty->value,
            'estimatedMinutes' => $this->estimated_minutes,
            'stepCount' => count($steps),
            'steps' => $steps,
            'organ' => new OrganResource($this->whenLoaded('organ')),
            'progress' => $this->progress === null ? null : new LessonProgressResource($this->progress),
        ];
    }

    /**
     * The lesson's steps, in order, each tagged with its own index.
     *
     * The index is emitted rather than left to the client's array position so
     * `POST /lessons/{lesson}/progress` sends back a number the server
     * authored. It is also what makes dropping an unreadable step safe: the
     * remaining indices stay contiguous, so a percentage computed from them
     * still means what it says.
     *
     * @return list<array<string, mixed>>
     */
    public static function steps(Lesson $lesson): array
    {
        $raw = $lesson->content['steps'] ?? null;

        if (! is_array($raw)) {
            return [];
        }

        $steps = [];

        foreach ($raw as $step) {
            if (! is_array($step)) {
                continue;
            }

            $type = is_string($step['type'] ?? null) ? LessonStepType::tryFrom($step['type']) : null;

            if ($type === null) {
                continue;
            }

            $payload = $step['payload'] ?? [];

            $steps[] = [
                'index' => count($steps),
                'type' => $type->value,
                'label' => $type->label(),
                'title' => is_string($step['title'] ?? null) ? $step['title'] : $type->label(),
                // Passed through as authored. Step payloads are written in the
                // shape the client consumes (see LessonSeeder), so there is no
                // per-type transform here to drift out of sync with the six
                // step components.
                'payload' => is_array($payload) ? $payload : [],
            ];
        }

        return $steps;
    }

    /**
     * Readable step count, shared with the library card.
     *
     * On the Resource rather than the model because it is a property of what
     * the client will be able to render, not of the row (invariant 6: models
     * hold state, not behaviour).
     */
    public static function countSteps(Lesson $lesson): int
    {
        return count(self::steps($lesson));
    }
}
