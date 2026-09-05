<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\LearningContext;
use Illuminate\Support\Facades\Log;

/**
 * The last thing between a model and a 13-year-old (docs/architecture.md §8.4).
 *
 * Scope, honesty, and reading level are asked for in the system prompt, because
 * that is where they are enforceable. This class is the backstop for the subset
 * a machine can check *after* the fact — and it is deliberate that the subset is
 * small and precise rather than a keyword blocklist:
 *
 * - **Clinical framing addressed to the reader as a patient.** "The ventricle
 *   wall thickens in hypertension" is exactly what a student should be told.
 *   "You probably have a condition" is not. The patterns below target the
 *   second and leave the first alone.
 * - **Length.** A ceiling PRD §24 calls for and that the model routinely
 *   overshoots.
 * - **Emptiness and truncation.** A half sentence is worse than a short one.
 *
 * A failure returns a safe fallback and logs the incident. It never returns raw
 * model output, and it never returns a provider error.
 */
final class AIResponseValidator
{
    /**
     * Anchored on advice *directed at the reader*, which is what separates
     * clinical guidance from teaching about disease.
     */
    private const CLINICAL_PATTERNS = [
        'persona' => [
            '/\bi(?:\'m|\s+am)\s+(?:a|your)\s+(?:doctor|physician|medical\s+professional)\b/i',
            '/\bas\s+your\s+(?:doctor|physician|clinician)\b/i',
            '/\bspeaking\s+as\s+a\s+(?:doctor|physician)\b/i',
        ],
        'diagnosis' => [
            '/\b(?:i|we)\s+diagnose\b/i',
            '/\byour\s+diagnosis\s+is\b/i',
            '/\byou\s+(?:likely|probably|may|might)\s+have\s+(?:a|an)?\s*'
                .'(?:condition|disease|disorder|infection|tumou?r|cancer|syndrome)\b/i',
            '/\bbased\s+on\s+your\s+symptoms\b/i',
        ],
        'treatment' => [
            '/\bprescrib(?:e|ing|ed)\s+(?:you|for\s+you)\b/i',
            '/\byou\s+(?:need|require|should\s+have)\s+(?:surgery|an?\s+operation|medication|treatment|chemotherapy)\b/i',
            '/\byou\s+should\s+(?:take|start|stop)\s+\S+\s*(?:mg|mcg|milligrams)\b/i',
            '/\btake\s+\d+\s*(?:mg|mcg)\b/i',
        ],
    ];

    /**
     * What a student sees when the model produced something unusable.
     *
     * Educational in tone and actionable: it tells them what the tutor can do
     * instead, rather than reporting a failure they cannot act on (PRD §40).
     */
    public const FALLBACK = 'I can\'t answer that one as asked. I can explain how a structure works, '
        .'what it does, and how it fits into the organ around it — try asking about the part you have '
        .'selected, and keep it to how the body works rather than to anyone\'s own health.';

    /**
     * Validates the answer TEXT rather than the AIResponse, because the reply
     * has already had its follow-up block split off by PromptBuilder — counting
     * that machine-readable line against a reading-length ceiling meant for
     * prose would be wrong.
     *
     * @param  bool  $truncated  the provider stopped at a token limit
     */
    public function validate(string $content, LearningContext $context, bool $truncated = false): ValidatedAnswer
    {
        $answer = trim($content);

        if ($answer === '') {
            return $this->reject($context, 'empty_response');
        }

        $violation = $this->findClinicalViolation($answer);

        if ($violation !== null) {
            return $this->reject($context, $violation);
        }

        $trimmed = false;

        if ($truncated) {
            // The provider stopped mid-token. Cutting back to the last complete
            // sentence is the difference between a short answer and a broken one.
            $answer = $this->toLastCompleteSentence($answer);
            $trimmed = true;
        }

        $ceiling = (int) config('ai.limits.max_response_words', 220);

        if ($this->wordCount($answer) > $ceiling) {
            $answer = $this->trimToWords($answer, $ceiling);
            $trimmed = true;
        }

        if (trim($answer) === '') {
            return $this->reject($context, 'empty_after_trim');
        }

        return new ValidatedAnswer(answer: trim($answer), trimmed: $trimmed);
    }

    private function reject(LearningContext $context, string $violation): ValidatedAnswer
    {
        // The user id, not the prompt and not the answer: never log a prompt
        // containing student data (docs/engineering.md §10). The reason code is
        // enough to spot a pattern; the text that triggered it is not needed to
        // know that it happened.
        Log::warning('Tutor response rejected by validator', [
            'violation' => $violation,
            'user_id' => $context->userId,
            'education_level' => $context->educationLevel,
        ]);

        return new ValidatedAnswer(
            answer: self::FALLBACK,
            replaced: true,
            violation: $violation,
        );
    }

    private function findClinicalViolation(string $answer): ?string
    {
        foreach (self::CLINICAL_PATTERNS as $reason => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $answer) === 1) {
                    return "clinical_{$reason}";
                }
            }
        }

        return null;
    }

    private function wordCount(string $text): int
    {
        return count(preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    private function trimToWords(string $text, int $limit): string
    {
        $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $this->toLastCompleteSentence(implode(' ', array_slice($words, 0, $limit)));
    }

    /**
     * Cut back to the last sentence-ending punctuation, so an answer never ends
     * mid-clause. Falls back to the original text when there is no sentence
     * boundary at all — one long clause is still better than nothing.
     */
    private function toLastCompleteSentence(string $text): string
    {
        $trimmed = rtrim($text);
        $lastStop = -1;

        foreach (['.', '!', '?'] as $terminator) {
            $position = mb_strrpos($trimmed, $terminator);

            // Strict false check: position 0 is a real position, and `?: -1`
            // would silently discard it.
            if ($position !== false && $position > $lastStop) {
                $lastStop = $position;
            }
        }

        if ($lastStop < 0) {
            return $trimmed;
        }

        return mb_substr($trimmed, 0, $lastStop + 1);
    }
}
