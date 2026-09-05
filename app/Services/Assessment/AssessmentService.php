<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Jobs\RecalculateMastery;
use App\Models\AnatomicalStructure;
use App\Models\Attempt;
use App\Models\Organ;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\User;
use App\Services\Anatomy\AnatomyService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Reads a quiz, and grades an answer to it.
 *
 * The one rule this class exists to enforce: **correctness is decided here and
 * nowhere else.** Nothing the client sends contributes to `is_correct` — the
 * request states what was picked, this compares it against the question's own
 * answer key, and the API Resource never ships that key with the question
 * (invariant 4, docs/architecture.md §5.4 rule 2, §9).
 *
 * Takes the User as an argument and reads nothing from the request, the
 * session, or the authenticated-user helper (invariant 1). That is not
 * ceremony here: Handover 09
 * scores mission steps by calling into this same pipeline from its own
 * service, and Handover 10 re-reads attempts from a queued job.
 *
 * Ownership is assigned from the passed-in User, never from a request field
 * (docs/engineering.md §10).
 */
final class AssessmentService
{
    public function __construct(
        private readonly AnatomyService $anatomy,
        private readonly ShortAnswerGrader $shortAnswers,
    ) {}

    /**
     * Every organ that can currently ask a question, for the quiz picker.
     *
     * Built from the same query as `findPublishedQuiz`, so a card can never
     * offer a quiz that 404s when opened — which is exactly what would happen
     * if this counted `questions` rows and that one additionally required a
     * spatial question's structure to be published.
     *
     * @return Collection<int, QuizSummary>
     */
    public function listQuizzes(): Collection
    {
        /** @var Collection<int|string, int> $counts */
        $counts = $this->answerableQuestions()->pluck('organ_id')->countBy();

        if ($counts->isEmpty()) {
            /** @var Collection<int, QuizSummary> $empty */
            $empty = collect();

            return $empty;
        }

        return Organ::query()
            ->published()
            ->whereIn('id', $counts->keys()->all())
            ->orderBy('name')
            ->get()
            ->map(static fn (Organ $organ): QuizSummary => new QuizSummary(
                organ: $organ,
                questionCount: (int) $counts->get((int) $organ->getKey(), 0),
            ))
            ->values();
    }

    /**
     * The published quiz for an organ slug, or null.
     *
     * Null for an unknown slug, a draft organ, and an organ with no published
     * questions alike — the controller 404s on all three without
     * distinguishing them, for the same reason `AnatomyService` does: a draft
     * organ's existence is not something a URL guess should confirm.
     */
    public function findPublishedQuiz(string $organSlug): ?Quiz
    {
        $organ = $this->anatomy->findPublishedOrganBySlug($organSlug);

        if ($organ === null) {
            return null;
        }

        $questions = $this->answerableQuestions()
            ->where('organ_id', $organ->getKey())
            ->with('options')
            // Easiest first, then by id: a round should open on something a
            // student can answer, and the order must be identical on every
            // request so the client's own shuffle is the only randomness.
            ->orderBy('difficulty')
            ->orderBy('id')
            ->get();

        if ($questions->isEmpty()) {
            return null;
        }

        return new Quiz($organ, $questions);
    }

    /**
     * Grade one answer, persist it, and queue the mastery recalculation.
     *
     * The attempt is written before the verdict is returned, deliberately: the
     * response reveals the correct structure so the viewer can flash it green
     * (docs/architecture.md §9), and that reveal is only safe while every
     * request for it costs a recorded attempt.
     */
    public function recordAttempt(User $student, Question $question, AttemptData $data): AttemptResult
    {
        // Resolved through the question, not trusted from the payload: an id
        // naming another question's option or another organ's structure is
        // simply not an answer to this question, and must not be stored as one.
        $option = $this->resolveOption($question, $data->selectedOptionId);
        $structure = $this->resolveStructure($question, $data->selectedStructureId);

        $isCorrect = $this->isCorrect($question, $option, $structure, $data->answerText);

        $attempt = $question->attempts()->make([
            'selected_structure_id' => $structure?->getKey(),
            'selected_option_id' => $option?->getKey(),
            'answer_text' => $data->answerText === '' ? null : $data->answerText,
            'time_spent_ms' => $data->timeSpentMs,
            'hint_used' => $data->hintUsed,
        ]);

        // Neither is fillable. Ownership comes from the caller's User and the
        // verdict from the grader above; both would be forgeable if a request
        // array could reach them (App\Models\Attempt).
        $attempt->user_id = (int) $student->getKey();
        $attempt->is_correct = $isCorrect;
        $attempt->save();

        RecalculateMastery::dispatch(
            userId: (int) $student->getKey(),
            attemptId: (int) $attempt->getKey(),
        );

        return new AttemptResult(
            attemptId: (int) $attempt->getKey(),
            isCorrect: $isCorrect,
            correctStructureId: $question->type === QuestionType::Spatial
                ? $question->correct_structure_id
                : null,
            correctOptionId: $question->type === QuestionType::Mcq
                ? $this->correctOptionId($question)
                : null,
            explanation: $question->explanation,
        );
    }

    /**
     * Record one graded mission step through the same pipeline as a quiz answer.
     *
     * Handover 09 calls this rather than writing `attempts` itself, which is
     * the point of the whole arrangement: mission steps and quiz answers
     * become the same rows, written by the same code, dispatching the same
     * mastery job, so Handover 10 has one input to read rather than two
     * (docs/architecture.md §9, §10;
     * docs/handovers/parallel-execution-plan.md D5). Nothing about the
     * `attempts` schema changes to make it fit — `question_id` and
     * `mission_attempt_id` have both been nullable since the table was created
     * precisely so that this method needs no migration.
     *
     * The verdict arrives already decided, unlike `recordAttempt()` above.
     * App\Services\Assessment\MissionStepAttempt explains why that is not a
     * hole in invariant 4: a mission step's answer key is the target sequence
     * in `missions.configuration`, and splitting the parsing of it across two
     * services would put one answer key in two places.
     *
     * Dispatches per step rather than once per run, deliberately. The job takes
     * the id of the attempt that made mastery stale, and a run's steps may
     * touch several structures; collapsing them would hand Handover 10 one
     * attempt id standing in for four (App\Jobs\RecalculateMastery).
     */
    public function recordMissionStep(User $student, MissionStepAttempt $step): Attempt
    {
        $attempt = Attempt::query()->make([
            // No question row exists for a mission step; that is what the
            // nullable column is for.
            'question_id' => null,
            'mission_attempt_id' => $step->missionAttemptId,
            'selected_structure_id' => $step->selectedStructureId,
            'time_spent_ms' => $step->timeSpentMs,
            'hint_used' => $step->hintUsed,
        ]);

        // Neither is fillable, for the same reason as in recordAttempt():
        // ownership comes from the caller's User and the verdict from the
        // grader, and both would be forgeable if a request array could reach
        // them (App\Models\Attempt).
        $attempt->user_id = (int) $student->getKey();
        $attempt->is_correct = $step->isCorrect;
        $attempt->save();

        RecalculateMastery::dispatch(
            userId: (int) $student->getKey(),
            attemptId: (int) $attempt->getKey(),
        );

        return $attempt;
    }

    /**
     * Questions a student may actually be asked.
     *
     * Published, and — for spatial questions — pointing at a structure that is
     * itself published. The organ payload ships published structures only, so
     * a spatial question whose answer has no marker on the model has no
     * reachable right answer; serving it would mean a question every student
     * gets wrong.
     *
     * @return Builder<Question>
     */
    private function answerableQuestions(): Builder
    {
        return Question::query()
            ->published()
            ->where(function (Builder $query): void {
                $query
                    ->where('type', '!=', QuestionType::Spatial)
                    ->orWhereHas('correctStructure', function (Builder $structure): void {
                        $structure->where('is_published', true);
                    });
            });
    }

    private function isCorrect(
        Question $question,
        ?QuestionOption $option,
        ?AnatomicalStructure $structure,
        string $answerText,
    ): bool {
        return match ($question->type) {
            QuestionType::Spatial => $structure !== null
                && $question->correct_structure_id !== null
                && (int) $structure->getKey() === $question->correct_structure_id,
            QuestionType::Mcq => $option !== null && $option->is_correct,
            QuestionType::ShortAnswer => $this->shortAnswers->grade($question, $answerText),
        };
    }

    private function correctOptionId(Question $question): ?int
    {
        $correct = $question->options->first(
            static fn (QuestionOption $option): bool => $option->is_correct,
        );

        return $correct === null ? null : (int) $correct->getKey();
    }

    private function resolveOption(Question $question, ?int $optionId): ?QuestionOption
    {
        if ($optionId === null) {
            return null;
        }

        return $question->options->first(
            static fn (QuestionOption $option): bool => (int) $option->getKey() === $optionId,
        );
    }

    /**
     * Only a published structure on this question's own organ can be a pick.
     *
     * The organ payload ships published structures only, so anything else was
     * either guessed or replayed from another organ — not an answer, and not
     * something to record as one.
     */
    private function resolveStructure(Question $question, ?int $structureId): ?AnatomicalStructure
    {
        if ($structureId === null) {
            return null;
        }

        return AnatomicalStructure::query()
            ->published()
            ->where('organ_id', $question->organ_id)
            ->whereKey($structureId)
            ->first();
    }

    /*
    |---------------------------------------------------------------------------
    | Administration and the AI review queue (Handover 13)
    |---------------------------------------------------------------------------
    |
    | Everything above is published-only, because that is all a student may be
    | served. The review queue exists precisely to look at what is not
    | published yet, so it lives here, in the class that already owns the
    | questions tables and the answer key on them.
    |
    | PRD §24: AI-generated content is reviewable before it becomes canonical
    | curriculum. `status = review` is that gate, and publishQuestion() below is
    | the only way through it.
    */

    /**
     * Questions awaiting human approval.
     *
     * `status = review` **and** `generated_by_ai` — both, not either. A
     * human-written draft parked in review is not what PRD §24 asks a reviewer
     * to look at, and mixing the two would let the queue's meaning drift from
     * "AI output nobody has vetted" to "anything unfinished".
     *
     * Options and the correct structure are eager loaded because the reviewer
     * has to see the answer key to approve it — the one place invariant 4
     * sanctions exposing it, and only through a policy-gated Resource
     * (docs/handovers/13-admin-content.md).
     *
     * @return LengthAwarePaginator<int, Question>
     */
    public function paginateReviewQueue(int $perPage = 25): LengthAwarePaginator
    {
        return Question::query()
            ->where('status', QuestionStatus::Review)
            ->where('generated_by_ai', true)
            ->with(['organ', 'options', 'correctStructure'])
            // Oldest first: a review queue that surfaces the newest item first
            // starves its own backlog.
            ->orderBy('id')
            ->paginate($perPage);
    }

    /**
     * Every question in every state, for the admin bank.
     *
     * @return LengthAwarePaginator<int, Question>
     */
    public function paginateAllQuestions(?QuestionStatus $status = null, int $perPage = 25): LengthAwarePaginator
    {
        return Question::query()
            ->with(['organ', 'options'])
            ->when($status !== null, static fn (Builder $query): Builder => $query->where('status', $status))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function findAnyQuestion(int $id): ?Question
    {
        return Question::query()
            ->with(['organ', 'options', 'correctStructure'])
            ->find($id);
    }

    /**
     * Create a question and its options together.
     *
     * One transaction: a multiple-choice question that saved without its
     * options is a row the quiz will happily serve with nothing to pick, and
     * `answerableQuestions()` cannot filter it out because the question itself
     * is well formed.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array{label: string, value: string, is_correct: bool}>  $options
     */
    public function createQuestion(array $attributes, array $options = []): Question
    {
        return DB::transaction(function () use ($attributes, $options): Question {
            $question = new Question($attributes);
            $question->save();

            $this->replaceOptions($question, $options);

            return $question->fresh(['organ', 'options', 'correctStructure']) ?? $question;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array{label: string, value: string, is_correct: bool}>|null  $options
     */
    public function updateQuestion(Question $question, array $attributes, ?array $options = null): Question
    {
        return DB::transaction(function () use ($question, $attributes, $options): Question {
            $question->fill($attributes);
            $question->save();

            // Null means "leave the options alone" — distinct from an empty
            // array, which means "this question now has none". A publish that
            // sent no options would otherwise silently empty a question.
            if ($options !== null) {
                $this->replaceOptions($question, $options);
            }

            return $question->fresh(['organ', 'options', 'correctStructure']) ?? $question;
        });
    }

    /**
     * Approve a question for students.
     *
     * The only way a question reaches `published`. Handover 13's review queue
     * calls it, behind the `admin` middleware and QuestionPolicy::review();
     * nothing else in the codebase writes `questions.status`, which is what
     * makes PRD §24's control real rather than advisory.
     *
     * A question with no way to be answered is refused rather than published
     * into a quiz that would show a student an unanswerable card: the check
     * mirrors answerableQuestions(), so "publishable" and "servable" cannot
     * drift apart.
     */
    public function publishQuestion(Question $question): Question
    {
        if (! $this->isAnswerable($question)) {
            throw new InvalidArgumentException(
                'A question cannot be published until it has an answer: multiple choice needs a correct option, spatial needs a published structure.',
            );
        }

        return $this->setQuestionStatus($question, QuestionStatus::Published);
    }

    /**
     * Move a question between states without the publish() guard.
     *
     * Used to send something back to `draft` or `review`. Withdrawing a
     * question must never be blocked by the same validity check that guards
     * publishing it — that would trap a broken published question in place.
     */
    public function setQuestionStatus(Question $question, QuestionStatus $status): Question
    {
        $question->status = $status;
        $question->save();

        return $question;
    }

    public function deleteQuestion(Question $question): void
    {
        $question->delete();
    }

    /**
     * Whether this question can actually be answered.
     *
     * Deliberately *stricter* than answerableQuestions(), which only guards the
     * spatial case. That query filters what a student is served and cannot
     * afford an extra join per request; this runs once, when a human presses
     * publish, and can afford to also reject a multiple-choice question with no
     * correct option — a row the quiz would happily serve and every student
     * would get wrong.
     *
     * Stricter in one direction only, which is the property that matters:
     * everything publishable is servable. The reverse is allowed to differ.
     */
    public function isAnswerable(Question $question): bool
    {
        return match ($question->type) {
            QuestionType::Mcq => $question->options()->where('is_correct', true)->exists(),
            QuestionType::Spatial => $question->correctStructure !== null
                && $question->correctStructure->is_published,
            QuestionType::ShortAnswer => true,
        };
    }

    /**
     * Replace a question's options wholesale.
     *
     * Delete-and-recreate rather than a diff: options carry no history worth
     * preserving, `attempts.selected_option_id` is nullable, and a diff would
     * be the more complicated way to reach the same row set.
     *
     * @param  list<array{label: string, value: string, is_correct: bool}>  $options
     */
    private function replaceOptions(Question $question, array $options): void
    {
        $question->options()->delete();

        foreach ($options as $option) {
            $question->options()->create([
                'label' => $option['label'],
                'value' => $option['value'],
                'is_correct' => $option['is_correct'],
            ]);
        }

        $question->unsetRelation('options');
    }
}
