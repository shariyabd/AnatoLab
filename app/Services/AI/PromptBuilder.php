<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\LearningContext;
use App\Contracts\RetrievedChunk;
use App\Enums\EducationLevel;
use App\Enums\TutorTask;

/**
 * Owns the contract between this application and the model — in both directions.
 *
 * It writes the system prompt and the context block (PRD §39), and it reads back
 * the one structured thing it asked the model for: the follow-up questions. A
 * separate parser would mean the instruction and the thing that understands the
 * instruction could drift apart in two different commits.
 *
 * The safety rules here are the *primary* control (docs/architecture.md §8.4).
 * AIResponseValidator is the backstop for the subset of them that a machine can
 * actually check afterwards.
 */
final class PromptBuilder
{
    /**
     * The marker the model is asked to end on. Distinctive enough that it will
     * not appear inside a sentence about anatomy.
     */
    public const FOLLOW_UP_MARKER = 'FOLLOW-UP:';

    private const FOLLOW_UP_SEPARATOR = '||';

    /**
     * Provider-agnostic chat messages. The system prompt travels in $options
     * (see chatOptions), because Anthropic takes it as a top-level field and
     * OpenAI takes it as a message — normalising that is each adapter's job.
     *
     * @param  list<string>  $curatedNotes  published metadata from Handover 03
     * @param  list<RetrievedChunk>  $sources  empty until Handover 11
     * @param  list<array{role: string, content: string}>  $history  earlier turns, oldest first
     * @return list<array{role: string, content: string}>
     */
    public function messages(
        LearningContext $context,
        TutorTask $task,
        string $question,
        array $curatedNotes = [],
        array $sources = [],
        array $history = [],
    ): array {
        return [...$history, [
            'role' => 'user',
            'content' => $this->userTurn($context, $task, $question, $curatedNotes, $sources),
        ]];
    }

    public function systemPrompt(LearningContext $context, TutorTask $task): string
    {
        $level = EducationLevel::tryFrom($context->educationLevel) ?? EducationLevel::HighSchool;
        $maxWords = (int) config('ai.limits.max_response_words', 220);

        $rules = [
            'You are an anatomy tutor inside an educational application used by students aged 13 to 18.',
            'Stay strictly within educational anatomy and physiology. Decline anything else and say why in one sentence.',
            'You are not a doctor and must never present yourself as one.',
            'Never diagnose a condition, interpret a symptom, or recommend a treatment, a medication, or a dose. '
                .'If the question is about someone\'s own health, say plainly that you can explain how the body works '
                .'but that health questions belong to a qualified clinician, then offer the educational version of the question.',
            'If you are not confident, say so. Never invent a fact, a figure, or a citation.',
            'Prefer the supplied sources and curated notes over your own recall, and say which you used.',
            "Write for a {$level->label()} reader: {$this->readingGuidance($level)}",
            "Keep the answer under {$maxWords} words. Short paragraphs, no headings, no markdown tables.",
            $this->taskInstruction($task),
            sprintf(
                'End your reply with a single final line in exactly this form, offering two or three short questions '
                .'the student could ask next: %s question one %s question two',
                self::FOLLOW_UP_MARKER,
                self::FOLLOW_UP_SEPARATOR,
            ),
        ];

        return implode("\n\n", $rules);
    }

    /**
     * Provider-agnostic hints for AIProviderInterface::chat().
     *
     * @return array<string, mixed>
     */
    public function chatOptions(LearningContext $context, TutorTask $task): array
    {
        return [
            'system' => $this->systemPrompt($context, $task),
            'max_tokens' => (int) config('ai.tutor.max_tokens', 700),
            'temperature' => (float) config('ai.tutor.temperature', 0.3),
        ];
    }

    /**
     * Split the model's reply into the answer and its follow-up questions.
     *
     * Tolerant on purpose: a missing or malformed marker yields the whole reply
     * as the answer and no follow-ups. A model that ignored the instruction has
     * still written something useful, and dropping the answer over a formatting
     * miss would be the worse failure.
     *
     * @return array{answer: string, followUps: list<string>}
     */
    public function splitFollowUps(string $content): array
    {
        $lines = preg_split('/\R/', trim($content));

        if ($lines === false) {
            return ['answer' => trim($content), 'followUps' => []];
        }

        $followUps = [];
        $kept = [];

        foreach ($lines as $line) {
            if (! str_starts_with(trim($line), self::FOLLOW_UP_MARKER)) {
                $kept[] = $line;

                continue;
            }

            $raw = trim(substr(trim($line), strlen(self::FOLLOW_UP_MARKER)));

            foreach (explode(self::FOLLOW_UP_SEPARATOR, $raw) as $candidate) {
                $question = trim($candidate);

                if ($question !== '') {
                    $followUps[] = $question;
                }
            }
        }

        return [
            'answer' => trim(implode("\n", $kept)),
            // Three is the ceiling the prompt asks for; trimming here means a
            // model that offered eight does not turn the panel into a menu.
            'followUps' => array_slice($followUps, 0, 3),
        ];
    }

    /**
     * @param  list<string>  $curatedNotes
     * @param  list<RetrievedChunk>  $sources
     */
    private function userTurn(
        LearningContext $context,
        TutorTask $task,
        string $question,
        array $curatedNotes,
        array $sources,
    ): string {
        // The block layout follows PRD §39 so that what the model receives is
        // legible next to the specification it came from.
        $blocks = [
            'Student level:'."\n".$this->levelLabel($context),
            'Difficulty preference:'."\n".$context->difficultyPreference,
        ];

        if ($context->organName !== null) {
            $blocks[] = 'Current organ:'."\n".$context->organName;
        }

        if ($context->structureName !== null) {
            $blocks[] = 'Selected structure:'."\n".$context->structureName;
        }

        if ($context->lessonTitle !== null) {
            $blocks[] = 'Current lesson:'."\n".$context->lessonTitle;
        }

        if ($context->recentMistakes !== []) {
            // Names of structures recently confused — never which one was right.
            $blocks[] = 'Recently confused structures:'."\n".implode(', ', $context->recentMistakes);
        }

        if ($context->masteryGaps !== []) {
            $blocks[] = 'Topics this student is weaker on:'."\n".implode(', ', $context->masteryGaps);
        }

        $blocks[] = $curatedNotes === []
            ? 'Curated notes:'."\n".'None available for this selection.'
            : 'Curated notes (written and published by this application\'s editors):'."\n"
                .implode("\n", array_map(static fn (string $note): string => "- {$note}", $curatedNotes));

        $blocks[] = $sources === []
            ? 'Relevant educational sources:'."\n"
                .'None. There is no indexed source material for this question, so answer from the curated notes '
                .'above and from well-established anatomy, and tell the student that is what you did.'
            : 'Relevant educational sources:'."\n".$this->formatSources($sources);

        $blocks[] = 'Student question:'."\n".($question === '' ? $this->impliedQuestion($context, $task) : $question);

        return implode("\n\n", $blocks);
    }

    /**
     * @param  list<RetrievedChunk>  $sources
     */
    private function formatSources(array $sources): string
    {
        return implode("\n\n", array_map(
            static fn (RetrievedChunk $chunk): string => "[{$chunk->sourceTitle}]\n{$chunk->content}",
            $sources,
        ));
    }

    private function impliedQuestion(LearningContext $context, TutorTask $task): string
    {
        $subject = $context->structureName ?? $context->organName ?? 'this structure';

        return match ($task) {
            TutorTask::Explain => "Explain {$subject}: what it is, what it does, and why it matters.",
            TutorTask::Hint => "Give me a hint about {$subject} without telling me the answer.",
            TutorTask::Ask => "Tell me something worth knowing about {$subject}.",
        };
    }

    private function levelLabel(LearningContext $context): string
    {
        return (EducationLevel::tryFrom($context->educationLevel) ?? EducationLevel::HighSchool)->label();
    }

    private function readingGuidance(EducationLevel $level): string
    {
        return match ($level) {
            EducationLevel::MiddleSchool => 'plain everyday words, short sentences, one concrete comparison, '
                .'and define any anatomical term the first time you use it.',
            EducationLevel::HighSchool => 'standard biology vocabulary, defined briefly on first use, '
                .'with the mechanism explained rather than only stated.',
            EducationLevel::Advanced => 'precise anatomical and physiological terminology, including Terminologia '
                .'Anatomica terms where they clarify, without simplifying the mechanism away.',
        };
    }

    private function taskInstruction(TutorTask $task): string
    {
        return match ($task) {
            TutorTask::Ask => 'Answer the student\'s question directly, using the selected structure as the anchor. '
                .'Lead with the answer, then the reason.',
            TutorTask::Explain => 'Explain the selected structure: what it is, what it does, and how it relates to '
                .'the organ around it. There is no question to answer — build the explanation yourself.',
            TutorTask::Hint => 'Give one hint and stop. Point at what to look at or what principle applies. '
                .'Do not state the answer, do not confirm or deny anything the student has said, and finish '
                .'with a question that moves them one step forward. Keep it under sixty words.',
        };
    }
}
