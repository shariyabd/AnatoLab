<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Assessment\RecordAttemptRequest;
use App\Http\Resources\Assessment\AttemptResultResource;
use App\Http\Resources\Assessment\QuizResource;
use App\Services\Assessment\AssessmentService;
use App\Services\Assessment\Quiz;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The two quiz endpoints (docs/architecture.md §7, §9).
 *
 *   GET  /api/v1/quizzes/{quiz}          questions, answers stripped
 *   POST /api/v1/quizzes/{quiz}/attempt  validated result + explanation
 *
 * `{quiz}` is an organ slug — there is no `quizzes` table
 * (App\Services\Assessment\Quiz explains why). Route model binding is not used
 * for the same reason `AnatomyController` avoids it: the organ read is cached,
 * and binding would query the row again around the cache.
 *
 * Thin by construction: validate through a FormRequest, delegate to the
 * service, return a Resource (invariant 7). The grading branch, the ownership
 * assignment and the mastery dispatch are all in the service, where a queued
 * job and Handover 09's mission scorer can reach them.
 */
final class QuizController extends Controller
{
    public function __construct(private readonly AssessmentService $assessment) {}

    public function show(string $quiz): QuizResource
    {
        return new QuizResource($this->quizOr404($quiz));
    }

    /**
     * The question id travels in the body rather than the path.
     *
     * A quiz is answered one question at a time from a single mounted viewer,
     * and `POST /quizzes/{quiz}/attempt` is the endpoint docs/architecture.md
     * §7 specifies. Putting the question in the path would make the URL claim
     * a `/questions/{question}` resource that is not addressable on its own —
     * a question outside its quiz is not servable.
     */
    public function attempt(RecordAttemptRequest $request, string $quiz): AttemptResultResource
    {
        $data = $request->toData();
        $question = $this->quizOr404($quiz)->question($data->questionId);

        if ($question === null) {
            throw new NotFoundHttpException('No published question in this quiz matches that id.');
        }

        return new AttemptResultResource(
            $this->assessment->recordAttempt($request->student(), $question, $data)
        );
    }

    /**
     * An unknown slug, a draft organ and an organ with no published questions
     * are one 404. Distinguishing them would confirm which drafts exist.
     */
    private function quizOr404(string $slug): Quiz
    {
        $found = $this->assessment->findPublishedQuiz($slug);

        if ($found === null) {
            throw new NotFoundHttpException('No published quiz matches that slug.');
        }

        return $found;
    }
}
