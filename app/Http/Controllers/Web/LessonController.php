<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\DifficultyPreference;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lessons\LessonIndexRequest;
use App\Http\Requests\Lessons\LessonShowRequest;
use App\Http\Resources\Lessons\LessonResource;
use App\Http\Resources\Lessons\LessonSummaryResource;
use App\Services\Lessons\LessonService;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The lesson library and the lesson itself, as Inertia pages.
 *
 * First paint arrives as page props rather than a JSON round trip
 * (docs/architecture.md §7). The API controller serves the same data to the
 * interactive calls the page makes afterwards — filtering the library, and
 * recording progress — so the two are not duplicates: this one exists so a
 * lesson is deep-linkable and readable before any JavaScript runs.
 *
 * Both props are plain arrays rather than Resource instances, for the reason
 * Web\ExploreController spells out at `payload()`: the lesson's `organ` is an
 * OrganDto handed straight to ViewerStage, and an Inertia-resolved Resource
 * would deliver it wrapped in `data`, breaking the frozen contract in
 * resources/js/anatomy/types.ts in a way TypeScript cannot see.
 */
final class LessonController extends Controller
{
    public function __construct(private readonly LessonService $lessons) {}

    public function index(LessonIndexRequest $request): Response
    {
        $student = $request->student();
        $filters = $request->filters();

        return Inertia::render('Lessons/Index', [
            'lessons' => function () use ($student, $filters): array {
                $lessons = $this->lessons->listPublished($filters);

                return self::payload(LessonSummaryResource::forStudent(
                    $lessons,
                    $this->lessons->progressForLessons($student, $lessons),
                ));
            },
            'filters' => [
                'organ' => $filters->organSlug,
                'system' => $filters->systemSlug,
                'difficulty' => $filters->difficulty?->value,
            ],
            // Closure, so a partial reload asking only for `lessons` does not
            // re-derive the picker's options on every filter change.
            'filterOptions' => fn (): array => [
                ...$this->lessons->filterOptions(),
                'difficulties' => DifficultyPreference::values(),
            ],
        ]);
    }

    public function show(LessonShowRequest $request, string $lesson): Response
    {
        $student = $request->student();
        $found = $this->lessons->findPublishedBySlug($lesson);

        if ($found === null) {
            throw new NotFoundHttpException('No published lesson matches that slug.');
        }

        return Inertia::render('Lessons/Show', [
            'lesson' => fn (): array => self::payload(
                new LessonResource($found, $this->lessons->progressFor($student, $found)),
            ),
        ]);
    }

    /**
     * A Resource as the plain array the client should receive.
     *
     * Same helper, and the same reasoning, as Web\ExploreController::payload():
     * Inertia resolves a JsonResource prop by calling `toResponse()` and
     * recurses into nested Resources, so serialising here settles the shape
     * before Inertia sees it and `lesson.organ` arrives as the OrganDto the
     * viewer expects rather than `lesson.organ.data`.
     *
     * @return array<array-key, mixed>
     */
    private static function payload(JsonResource|ResourceCollection $resource): array
    {
        /** @var array<array-key, mixed> $decoded */
        $decoded = json_decode(json_encode($resource, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
