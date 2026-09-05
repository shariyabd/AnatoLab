<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\AIProviderInterface;
use App\Contracts\LearningContext;
use App\Contracts\RetrievedChunk;
use App\Enums\ConversationContextType;
use App\Enums\MessageRole;
use App\Enums\TutorTask;
use App\Models\AnatomicalStructure;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Organ;
use App\Models\User;
use App\Services\Anatomy\AnatomyService;
use App\Services\Rag\RetrievalService;
use Illuminate\Support\Str;

/**
 * The tutor pipeline (docs/architecture.md §8.2).
 *
 *   context → [retrieval seam] → prompt → provider → validator → persist
 *
 * Takes the User as an argument and reads nothing from the request, the
 * session, or the authenticated-user helper (invariant 1): every step here has
 * to be runnable from a queued job, and conversation ownership has to come from
 * the caller rather than from ambient state. It depends on AIProviderInterface and never on a concrete provider
 * (invariant 2), which is what makes AI_PROVIDER an environment change.
 *
 * It decides nothing about correctness. Scoring is Handover 07's and Handover
 * 10's; a tutor that grades is a tutor a student can argue with.
 */
final class AITutorService
{
    public function __construct(
        private readonly AIProviderInterface $provider,
        private readonly AnatomyService $anatomy,
        private readonly AIContextBuilder $contextBuilder,
        private readonly PromptBuilder $prompts,
        private readonly AIResponseValidator $validator,
        // Handover 11, at the seam below and nowhere else.
        private readonly RetrievalService $retrieval,
    ) {}

    /**
     * Answer one question and record the turn.
     *
     * A provider failure is not caught here. AIProviderException carries the
     * provider name and upstream status, and bootstrap/app.php already logs it
     * against a correlation id and renders an educational-tone message with
     * neither (docs/architecture.md §14). Catching it here to build a second,
     * parallel error path would be the thing that eventually leaks one.
     */
    public function respond(User $user, TutorRequestData $data): TutorReply
    {
        [$organ, $structure] = $this->resolveSubject($data);

        $context = $this->contextBuilder->build(
            user: $user,
            organ: $organ,
            structure: $structure,
            // SEAM — Handover 06 (`lessons`, `lesson_progress`). PRD §39 puts
            // the current lesson and its objective in the prompt. It must be
            // resolved server-side from the student's in-progress lesson and
            // never accepted from the request: unverified client text going
            // straight into a prompt is the cheapest injection surface a tutor
            // has. Pass the title of the user's most recent in-progress lesson
            // here once `lesson_progress` exists.
            lessonTitle: null,
        );

        $question = $this->questionFor($data, $context);
        $curatedNotes = $this->contextBuilder->curatedNotesFor($organ, $structure);
        $sources = $this->retrieveGrounding($context, $question, $organ, $structure);

        // Resolved, not created: a thread is only started once there is an
        // answer to put in it. Creating it first would leave an empty titled
        // conversation behind every time a provider call fails.
        $conversation = $this->findConversation($user, $data->conversationId);

        $response = $this->provider->chat(
            messages: $this->prompts->messages(
                context: $context,
                task: $data->task,
                question: $question,
                curatedNotes: $curatedNotes,
                sources: $sources,
                history: $this->historyFor($conversation),
            ),
            options: $this->prompts->chatOptions($context, $data->task),
        );

        $split = $this->prompts->splitFollowUps($response->content);
        $validated = $this->validator->validate($split['answer'], $context, $response->truncated);

        // A rejected answer keeps no follow-ups: they were generated alongside
        // the text the validator just threw away.
        $followUps = $validated->replaced ? [] : $split['followUps'];

        $conversation ??= $this->startConversation($user, $organ, $structure, $question);

        $this->recordTurn($conversation, $question, $validated, $response->model, $sources, $followUps);

        return new TutorReply(
            answer: $validated->answer,
            followUpQuestions: $followUps,
            sources: $sources,
            sourceNote: $this->sourceNoteFor($sources, $curatedNotes, $structure),
            conversationId: (int) $conversation->getKey(),
        );
    }

    /*
    |---------------------------------------------------------------------------
    | RETRIEVAL SEAM — Handover 11 inserts here, and only here
    |---------------------------------------------------------------------------
    */

    /**
     * Fetch knowledge-base passages relevant to this question.
     *
     * **This method is the seam.** Handover 11 landed here and changed nothing
     * else in this file: everything downstream — the prompt, the sources array,
     * the source note, the message metadata, the API Resource, and the panel
     * that renders citations — was already written against a non-empty
     * `list<RetrievedChunk>` and needed no change when one arrived.
     *
     * The relevance threshold is applied inside RetrievalService, not here, so
     * an empty return keeps meaning "nothing was relevant" rather than "nothing
     * was indexed" — the distinction sourceNoteFor() depends on. A retrieval
     * outage also returns empty rather than throwing: the student still gets
     * taught, and the tutor still says the answer was not sourced
     * (docs/handovers/08-retrieval-seam.md §2).
     *
     * The organ and structure are passed in because the frozen
     * `LearningContext::retrievalFilters()` returns only `education_level`, and
     * docs/architecture.md §8.2 specifies `organ_id` and `structure_id` as
     * filters too. Reading them off the resolved models keeps the frozen
     * contract frozen.
     *
     * @return list<RetrievedChunk>
     */
    private function retrieveGrounding(
        LearningContext $context,
        string $question,
        ?Organ $organ,
        ?AnatomicalStructure $structure,
    ): array {
        return $this->retrieval->search(
            query: $question,
            topK: (int) config('ai.retrieval.top_k', 5),
            filters: array_filter([
                ...$context->retrievalFilters(),
                'organ_id' => $organ?->getKey(),
                'structure_id' => $structure?->getKey(),
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    /*
    |---------------------------------------------------------------------------
    | Pipeline internals
    |---------------------------------------------------------------------------
    */

    /**
     * @return array{0: ?Organ, 1: ?AnatomicalStructure}
     */
    private function resolveSubject(TutorRequestData $data): array
    {
        if ($data->structureId !== null) {
            $structure = $this->anatomy->findPublishedStructure($data->structureId);

            // Published structures arrive with their organ already loaded, so
            // the organ costs nothing and an explicit slug is redundant.
            if ($structure instanceof AnatomicalStructure) {
                return [$structure->organ, $structure];
            }
        }

        if ($data->organSlug !== null) {
            return [$this->anatomy->findPublishedOrganBySlug($data->organSlug), null];
        }

        // An unpublished or unknown id is treated as no selection rather than as
        // an error: the student still asked a real question, and refusing it
        // because the editor unpublished a structure mid-session would be a
        // worse experience than answering it without an anchor.
        return [null, null];
    }

    private function questionFor(TutorRequestData $data, LearningContext $context): string
    {
        if ($data->question !== '') {
            return $data->question;
        }

        $subject = $context->structureName ?? $context->organName ?? 'this structure';

        return match ($data->task) {
            TutorTask::Explain => "Explain {$subject}.",
            TutorTask::Hint => "Give me a hint about {$subject}.",
            TutorTask::Ask => "Tell me about {$subject}.",
        };
    }

    /**
     * The thread this question continues, if the student owns one.
     *
     * Scoped to the passed-in user, so a `conversationId` naming someone else's
     * thread resolves to null and a new thread is started instead of appending
     * to it (docs/engineering.md §10: ownership is never trusted from a request
     * parameter).
     */
    private function findConversation(User $user, ?int $conversationId): ?Conversation
    {
        if ($conversationId === null) {
            return null;
        }

        return Conversation::query()
            ->where('user_id', $user->getKey())
            ->whereKey($conversationId)
            ->first();
    }

    /**
     * Open a new thread, anchored to whatever the student had selected.
     */
    private function startConversation(
        User $user,
        ?Organ $organ,
        ?AnatomicalStructure $structure,
        string $question,
    ): Conversation {
        [$contextType, $contextId] = match (true) {
            $structure instanceof AnatomicalStructure => [ConversationContextType::Structure, (int) $structure->getKey()],
            $organ instanceof Organ => [ConversationContextType::Organ, (int) $organ->getKey()],
            default => [ConversationContextType::General, null],
        };

        $conversation = new Conversation([
            'context_type' => $contextType,
            'context_id' => $contextId,
            'title' => Str::limit($question, 60),
        ]);

        $conversation->user()->associate($user);
        $conversation->save();

        return $conversation;
    }

    /**
     * Earlier turns, so a follow-up question makes sense.
     *
     * Capped by config: a long thread would otherwise grow every prompt until
     * most of it is history and the student's actual question is a footnote.
     *
     * @return list<array{role: string, content: string}>
     */
    private function historyFor(?Conversation $conversation): array
    {
        if (! $conversation instanceof Conversation) {
            return [];
        }

        $turns = (int) config('ai.tutor.history_turns', 6);

        $messages = ConversationMessage::query()
            ->where('conversation_id', $conversation->getKey())
            ->orderByDesc('id')
            ->limit($turns)
            ->get()
            ->reverse()
            ->values();

        return $messages
            ->map(static fn (ConversationMessage $message): array => [
                'role' => $message->role->value,
                'content' => $message->content,
            ])
            ->all();
    }

    /**
     * Persist the student's turn and the tutor's, in that order.
     *
     * Message metadata carries what a later audit needs — model, token usage,
     * whether the validator intervened, and the retrieved source ids — and
     * never the prompt, which contains student data.
     *
     * @param  list<RetrievedChunk>  $sources
     * @param  list<string>  $followUps
     */
    private function recordTurn(
        Conversation $conversation,
        string $question,
        ValidatedAnswer $validated,
        string $model,
        array $sources,
        array $followUps,
    ): void {
        $conversation->messages()->create([
            'role' => MessageRole::User,
            'content' => $question,
        ]);

        $conversation->messages()->create([
            'role' => MessageRole::Assistant,
            'content' => $validated->answer,
            'metadata' => [
                'model' => $model,
                'validator_replaced' => $validated->replaced,
                'validator_trimmed' => $validated->trimmed,
                'violation' => $validated->violation,
                'source_ids' => array_map(
                    static fn (RetrievedChunk $chunk): string => $chunk->id,
                    $sources,
                ),
                'follow_up_questions' => $followUps,
            ],
        ]);

        // Bump updated_at so the history list orders by real activity rather
        // than by when the thread was opened.
        $conversation->touch();
    }

    /**
     * The honest sentence that goes under an uncited answer.
     *
     * Generated here rather than asked of the model, so that "the tutor says
     * when it had no sources" is a property of this application and not a hope
     * about instruction-following. Returns null once real sources are cited —
     * the citation list is the statement at that point.
     *
     * @param  list<RetrievedChunk>  $sources
     * @param  list<string>  $curatedNotes
     */
    private function sourceNoteFor(array $sources, array $curatedNotes, ?AnatomicalStructure $structure): ?string
    {
        if ($sources !== []) {
            return null;
        }

        if ($curatedNotes !== [] && $structure instanceof AnatomicalStructure) {
            return 'No indexed source matched this question, so this answer uses the curated notes for '
                ."{$structure->name} and well-established anatomy.";
        }

        return 'No indexed source matched this question, so this answer comes from well-established anatomy '
            .'rather than a cited document.';
    }
}
