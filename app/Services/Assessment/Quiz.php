<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use App\Models\Organ;
use App\Models\Question;
use Illuminate\Database\Eloquent\Collection;

/**
 * One organ's published question set.
 *
 * There is no `quizzes` table — docs/architecture.md §6 does not have one, and
 * inventing one would give Handover 13 a second content type to administer for
 * no gain. A quiz is therefore *derived*: the published questions attached to
 * one published organ, addressed by that organ's slug. `{quiz}` in the API
 * path is an organ slug.
 *
 * Carrying the Organ as well as the questions is what lets the quiz page mount
 * the same viewer as Explore: it renders through `OrganResource`, so the page
 * receives the identical `OrganDto` the frozen contract describes
 * (resources/js/anatomy/types.ts).
 */
final readonly class Quiz
{
    /**
     * @param  Collection<int, Question>  $questions
     */
    public function __construct(
        public Organ $organ,
        public Collection $questions,
    ) {}

    /**
     * The question the student says they are answering, if it is in this quiz.
     *
     * Resolved from the loaded set rather than re-queried by id: that is what
     * makes "is this question part of this quiz, and is it published?" one
     * question instead of two, and it is the check that stops a draft question
     * being answered through a published quiz's URL.
     */
    public function question(int $questionId): ?Question
    {
        return $this->questions->first(
            static fn (Question $question): bool => (int) $question->getKey() === $questionId,
        );
    }
}
