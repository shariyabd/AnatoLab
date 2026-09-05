<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreQuestionRequest;
use App\Http\Requests\Admin\UpdateQuestionRequest;
use App\Http\Resources\Admin\AdminQuestionResource;
use App\Models\Organ;
use App\Models\Question;
use App\Services\Assessment\AssessmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * The question bank and the AI review queue (PRD §24).
 *
 * `review()` and `publish()` are the control PRD §24 requires before generated
 * content becomes canonical curriculum: a question with `status = review` is
 * never served by the student-facing quiz — `Question::scopePublished()` sees
 * to that — and `AssessmentService::publishQuestion()` is the only way out of
 * that state.
 *
 * This is also the one controller that renders an answer key. It is safe
 * because AdminQuestionResource asks QuestionPolicy::viewAnswerKey() itself
 * rather than trusting the route it was reached through
 * (docs/architecture.md §14, invariant 4).
 */
final class QuestionAdminController extends Controller
{
    public function __construct(private readonly AssessmentService $assessment) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Question::class);

        $validated = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::enum(QuestionStatus::class)],
        ]);

        $filter = $validated['status'] ?? null;
        $status = is_string($filter) ? QuestionStatus::from($filter) : null;

        return Inertia::render('Admin/Questions/Index', [
            'questions' => AdminQuestionResource::collection(
                $this->assessment->paginateAllQuestions($status),
            ),
            'filters' => ['status' => $status?->value],
            'options' => $this->formOptions(),
        ]);
    }

    /**
     * Questions written by the AI and not yet approved.
     */
    public function review(): Response
    {
        Gate::authorize('viewAny', Question::class);

        return Inertia::render('Admin/Questions/Review', [
            'questions' => AdminQuestionResource::collection(
                $this->assessment->paginateReviewQueue(),
            ),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Question::class);

        return Inertia::render('Admin/Questions/Edit', [
            'question' => null,
            'options' => $this->formOptions(),
        ]);
    }

    public function store(StoreQuestionRequest $request): RedirectResponse
    {
        $question = $this->assessment->createQuestion(
            $request->safe()->except('options'),
            self::optionsFrom($request),
        );

        return redirect()
            ->route('admin.questions.edit', $question)
            ->with('success', 'Question created.');
    }

    public function edit(Question $question): Response
    {
        Gate::authorize('update', $question);

        return Inertia::render('Admin/Questions/Edit', [
            'question' => new AdminQuestionResource(
                $question->loadMissing(['organ', 'options', 'correctStructure']),
            ),
            'options' => $this->formOptions(),
        ]);
    }

    public function update(UpdateQuestionRequest $request, Question $question): RedirectResponse
    {
        $this->assessment->updateQuestion(
            $question,
            $request->safe()->except('options'),
            self::optionsFrom($request),
        );

        return back()->with('success', 'Saved.');
    }

    /**
     * Approve a question — the review queue's one write.
     *
     * QuestionPolicy::review() rather than update(): approving generated
     * content is the judgement PRD §24 asks a human to make, and it should be
     * visible in an authorization audit as its own line.
     */
    public function publish(Question $question): RedirectResponse
    {
        Gate::authorize('review', $question);

        try {
            $this->assessment->publishQuestion($question);
        } catch (InvalidArgumentException $exception) {
            // A question that cannot be answered is a validation failure the
            // reviewer can fix, not a 500.
            throw ValidationException::withMessages(['status' => $exception->getMessage()]);
        }

        return back()->with('success', 'Approved — this question is now in the pool.');
    }

    /**
     * Send a question back to draft or review.
     *
     * Deliberately does not route through publishQuestion(): withdrawing a
     * broken question must not be blocked by the check that guards publishing
     * one.
     */
    public function status(Request $request, Question $question): RedirectResponse
    {
        Gate::authorize('publish', $question);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(QuestionStatus::class)],
        ]);

        $status = QuestionStatus::from($validated['status']);

        if ($status === QuestionStatus::Published) {
            // Publishing has one door, and it is the one with the answerable
            // check on it.
            return $this->publish($question);
        }

        $this->assessment->setQuestionStatus($question, $status);

        return back()->with('success', "Moved to {$status->label()}.");
    }

    public function destroy(Question $question): RedirectResponse
    {
        Gate::authorize('delete', $question);

        $this->assessment->deleteQuestion($question);

        return redirect()
            ->route('admin.questions.index')
            ->with('success', 'Question deleted.');
    }

    /**
     * The options array in the shape AssessmentService takes, or null when the
     * form sent none — null means "leave them alone", `[]` means "remove them".
     *
     * @return list<array{label: string, value: string, is_correct: bool}>|null
     */
    private static function optionsFrom(StoreQuestionRequest|UpdateQuestionRequest $request): ?array
    {
        if (! $request->has('options')) {
            return null;
        }

        /** @var list<array{label: string, value: string, is_correct: mixed}> $options */
        $options = $request->validated('options', []);

        return array_map(static fn (array $option): array => [
            'label' => $option['label'],
            'value' => $option['value'],
            'is_correct' => (bool) $option['is_correct'],
        ], $options);
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'organs' => Organ::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(static fn (Organ $organ): array => [
                    'id' => (string) $organ->id,
                    'name' => $organ->name,
                ])
                ->all(),
            'types' => QuestionType::values(),
            'statuses' => QuestionStatus::values(),
        ];
    }
}
