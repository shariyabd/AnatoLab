<?php

declare(strict_types=1);

namespace App\Http\Resources\Lessons;

use App\Models\Lesson;
use App\Models\LessonProgress;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One card in the lesson library: enough to choose a lesson, not the lesson.
 *
 * Deliberately omits `content`. A library of ten lessons would otherwise ship
 * every step of every one of them to render ten cards — the same reason
 * OrganSummaryResource is not an OrganDto.
 *
 * The student's progress is a constructor argument rather than a loaded
 * relation. `LessonService::findPublishedBySlug()` caches the Lesson model for
 * an hour and that cache is shared by every student, so a progress row hung
 * off the model as a relation would be one `setRelation` away from showing one
 * student's completion to another (docs/architecture.md §11: personalised data
 * is never cached across users).
 *
 * @mixin Lesson
 */
final class LessonSummaryResource extends JsonResource
{
    public function __construct(Lesson $lesson, private readonly ?LessonProgress $progress = null)
    {
        parent::__construct($lesson);
    }

    /**
     * The library, with each card carrying the requesting student's progress.
     *
     * `AnonymousResourceCollection` leaves a collection alone when its items
     * are already instances of what it collects, which is what lets each card
     * receive its own progress row — `self::collection()` cannot pass a second
     * constructor argument.
     *
     * @param  EloquentCollection<int, Lesson>  $lessons
     * @param  array<int, LessonProgress>  $progressByLesson
     * @return AnonymousResourceCollection<int, self>
     */
    public static function forStudent(EloquentCollection $lessons, array $progressByLesson): AnonymousResourceCollection
    {
        return new AnonymousResourceCollection(
            $lessons->map(
                static fn (Lesson $lesson): self => new self($lesson, $progressByLesson[$lesson->getKey()] ?? null),
            ),
            self::class,
        );
    }

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
            'stepCount' => LessonResource::countSteps($this->resource),
            'organ' => $this->whenLoaded('organ', fn (): array => [
                'slug' => $this->organ->slug,
                'name' => $this->organ->name,
                'accentColor' => $this->organ->accent_color,
                'bodySystem' => $this->organ->relationLoaded('bodySystem')
                    ? ['slug' => $this->organ->bodySystem->slug, 'name' => $this->organ->bodySystem->name]
                    : null,
            ]),
            'progress' => $this->progress === null ? null : new LessonProgressResource($this->progress),
        ];
    }
}
