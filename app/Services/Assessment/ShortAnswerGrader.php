<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use App\Models\Question;
use Illuminate\Support\Str;

/**
 * Grade a free-text answer against the rubric in `questions.metadata`.
 *
 * The rubric is authored, not inferred:
 *
 *     'rubric' => [
 *         'accepted' => ['left ventricle', 'ventriculus sinister'],  // any one
 *         'required' => ['ventricle'],                               // all of them
 *     ]
 *
 * ---------------------------------------------------------------------------
 * SEAM — AI grading (Handover 08's service)
 * ---------------------------------------------------------------------------
 * docs/handovers/07-assessment-engine.md describes short answers as graded by
 * calling Handover 08's tutor service against this rubric. That call is not
 * made here, and the reason is a boundary rather than an omission:
 * `AITutorService` exposes exactly one public method, `respond()`, which
 * returns prose for a student to read — it has no grading entry point, and its
 * own docblock says so ("It decides nothing about correctness"). Adding one
 * would mean editing the AI lane's service and writing an AI lane prompt, both
 * of which this lane is explicitly forbidden from doing
 * (docs/engineering.md §7, §8 rule 4).
 *
 * So this grades deterministically from the authored rubric, which is enough
 * for every seeded short-answer question, and the AI verdict slots in here as
 * a fallback for answers the rubric does not match — one method, one caller,
 * no other file changed. Whatever grades it, the verdict is persisted as a
 * normal attempt and the student never sees the rubric: `metadata` is not
 * serialised by any Resource in this lane.
 */
final class ShortAnswerGrader
{
    /**
     * Un-gradeable is *not* correct.
     *
     * A question with no rubric is a content bug, and defaulting it to "right"
     * would hand every student a free mark for typing anything at all.
     */
    public function grade(Question $question, string $answer): bool
    {
        $normalised = self::normalise($answer);

        if ($normalised === '') {
            return false;
        }

        $rubric = $question->metadata['rubric'] ?? null;

        if (! is_array($rubric)) {
            return false;
        }

        $accepted = self::phrases($rubric, 'accepted');
        $required = self::phrases($rubric, 'required');

        if ($accepted === [] && $required === []) {
            return false;
        }

        foreach ($required as $phrase) {
            if (! str_contains($normalised, $phrase)) {
                return false;
            }
        }

        if ($accepted === []) {
            return true;
        }

        foreach ($accepted as $phrase) {
            if (str_contains($normalised, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Case, accents and punctuation are not anatomy.
     *
     * A 14-year-old typing "left ventricle." or "Ventriculus Sinister" knows
     * the answer, and marking that wrong teaches transcription rather than the
     * subject (PRD §12 "explanation after answer" assumes the grade was fair).
     */
    private static function normalise(string $value): string
    {
        $ascii = Str::ascii($value);
        $lowered = Str::lower($ascii);
        $punctuationStripped = preg_replace('/[^a-z0-9 ]+/', ' ', $lowered) ?? '';

        return trim((string) preg_replace('/\s+/', ' ', $punctuationStripped));
    }

    /**
     * @param  array<mixed>  $rubric
     * @return list<string>
     */
    private static function phrases(array $rubric, string $key): array
    {
        $values = $rubric[$key] ?? [];

        if (! is_array($values)) {
            return [];
        }

        $normalised = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $phrase = self::normalise($value);

            if ($phrase !== '') {
                $normalised[] = $phrase;
            }
        }

        return $normalised;
    }
}
