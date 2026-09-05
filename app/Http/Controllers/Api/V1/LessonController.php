<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Lessons\LessonCompleteRequest;
use App\Http\Requests\Lessons\LessonIndexRequest;
use App\Http\Requests\Lessons\LessonProgressRequest;
use App\Http\Requests\Lessons\LessonShowRequest;
use App\Http\Resources\Lessons\LessonProgressResource;
use App\Http\Resources\Lessons\LessonResource;
use App\Http\Resources\Lessons\LessonSummaryResource;
use App\Models\Lesson;
use App\Services\Lessons\LessonService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The lesson API (docs/architecture.md §7, docs/handovers/06-lessons.md).
 *
 * Thin by construction: validate through a FormRequest, delegate to
 * LessonService with the student as an argument, return a Resource
 * (invariant 7). Nothing here decides anything — including who a progress row
 * belongs to, which is settled by the service taking a User rather than
 * reading one out of the request.
 *
 * Route model binding is deliberately not used, for the same reason
 * Api\V1\AnatomyController avoids it: binding would query the row again and
 * step around the `lesson:{slug}` cache that pays for this endpoint
 * (docs/architecture.md §11).
 */
final class LessonController extends Controller
{
    public function __construct(private readonly LessonService $lessons) {}

    /**
     * @return AnonymousResourceCollection<int, LessonSummaryResource>
     */
    public function index(LessonIndexRequest $request): AnonymousResourceCollection
    {
        $student = $request->student();
        $lessons = $this->lessons->listPublished($request->filters());

        return LessonSummaryResource::forStudent(
            $lessons,
            $this->lessons->progressForLessons($student, $lessons),
        );
    }

    public function show(LessonShowRequest $request, string $lesson): LessonResource
    {
        $student = $request->student();
        $found = $this->resolve($lesson);

        return new LessonResource($found, $this->lessons->progressFor($student, $found));
    }

    public function complete(LessonCompleteRequest $request, string $lesson): LessonProgressResource
    {
        $student = $request->student();

        return new LessonProgressResource(
            $this->lessons->complete($student, $this->resolve($lesson)),
        );
    }

    public function progress(LessonProgressRequest $request, string $lesson): LessonProgressResource
    {
        $student = $request->student();

        return new LessonProgressResource(
            $this->lessons->recordStepProgress(
                user: $student,
                lesson: $this->resolve($lesson),
                stepIndex: $request->stepIndex(),
            ),
        );
    }

    /**
     * An unknown slug and an unpublished lesson are the same 404: a draft
     * lesson's existence is not something a URL guess should confirm.
     */
    private function resolve(string $slug): Lesson
    {
        $found = $this->lessons->findPublishedBySlug($slug);

        if ($found === null) {
            throw new NotFoundHttpException('No published lesson matches that slug.');
        }

        return $found;
    }
}
