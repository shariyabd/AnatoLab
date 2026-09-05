<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\Assessment\QuizResource;
use App\Http\Resources\Assessment\QuizSummaryResource;
use App\Services\Assessment\AssessmentService;
use Illuminate\Http\Resources\Json\JsonResource;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The quiz pages: the picker, and one organ's round.
 *
 * The whole quiz arrives as a page prop rather than being fetched after mount.
 * Two reasons, and the second is the one that matters: the round is playable on
 * first paint, and the questions travel through exactly the same
 * `QuestionResource` the API uses — so the answer-key stripping is one code
 * path with one set of tests, not two that can drift
 * (docs/architecture.md §7: response shaping is the Resource's job, and only
 * the Resource's job).
 *
 * Props are plain arrays rather than Resource instances for the reason
 * `ExploreController::payload()` documents at length: Inertia resolves a
 * JsonResource through its HTTP response and nested collections arrive wrapped
 * in `data`, which would hand `useAnatomyViewer` something that is not an
 * `OrganDto`.
 *
 * Read-only, so there is no FormRequest — nothing arrives from the client but
 * a slug in the path. Answering is a POST to `Api\V1\QuizController`.
 */
final class QuizController extends Controller
{
    public function __construct(private readonly AssessmentService $assessment) {}

    public function index(): Response
    {
        return Inertia::render('Quiz/Index', [
            // ->resolve() rather than the collection itself: Inertia would
            // otherwise serialise a JsonResource through its HTTP response and
            // hand the page `{ data: [...] }`. Safe here, and not in `show()`,
            // because a summary card nests no other Resource — the case
            // `payload()` exists for.
            'quizzes' => fn (): array => QuizSummaryResource::collection(
                $this->assessment->listQuizzes()
            )->resolve(),
        ]);
    }

    public function show(string $quiz): Response
    {
        $found = $this->assessment->findPublishedQuiz($quiz);

        if ($found === null) {
            throw new NotFoundHttpException('No published quiz matches that slug.');
        }

        return Inertia::render('Quiz/Show', [
            'quiz' => fn (): array => self::payload(new QuizResource($found)),
        ]);
    }

    /**
     * A Resource as the plain array the client should receive.
     *
     * Same helper, same reasoning as `ExploreController::payload()`: encoding
     * settles the nested `OrganResource` and `QuestionResource` collections
     * before Inertia gets a chance to wrap them, so the `organ` key really is
     * the `OrganDto` that `resources/js/anatomy/types.ts` describes.
     *
     * @return array<string, mixed>
     */
    private static function payload(JsonResource $resource): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(json_encode($resource, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
