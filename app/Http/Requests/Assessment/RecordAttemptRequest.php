<?php

declare(strict_types=1);

namespace App\Http\Requests\Assessment;

use App\Models\User;
use App\Services\Assessment\AttemptData;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * What a student may state about their own answer.
 *
 * Note the shape of this list: it accepts *what was picked* and *how it went*,
 * and nothing about whether it was right. There is no `is_correct` field to
 * validate because there is no way for the client to assert one — the verdict
 * is derived in AssessmentService from the question's own answer key
 * (invariant 4, docs/architecture.md §5.4 rule 2).
 *
 * Ids are validated for shape only, not with `exists`. Existence is not the
 * question: an option belonging to a different question and a structure on a
 * different organ both *exist*, and both are still not answers to this
 * question. The service resolves each id through the question it was asked
 * about and discards anything that does not belong, which is a stricter check
 * than any validation rule could express and keeps it in one place.
 *
 * Everything ends at toData(): the controller never sees `$request->all()` and
 * the service never sees a Request (invariant 7).
 */
final class RecordAttemptRequest extends FormRequest
{
    /**
     * The `auth` middleware plus per-record scoping in AssessmentService,
     * which files the attempt against the passed-in User. There is no existing
     * record to authorize against at this point, so there is nothing here for
     * a policy to decide (docs/architecture.md §14).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'questionId' => ['required', 'integer', 'min:1'],
            'selectedStructureId' => ['nullable', 'integer', 'min:1'],
            'selectedOptionId' => ['nullable', 'integer', 'min:1'],
            'answerText' => ['nullable', 'string', 'max:'.self::MAX_ANSWER_LENGTH],
            // Capped at an hour. Timing feeds mastery's recency and speed
            // signals (docs/architecture.md §10), and an unbounded number from
            // a client is a number that can be made to mean anything.
            'timeSpentMs' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_TIME_SPENT_MS],
            'hintUsed' => ['nullable', 'boolean'],
        ];
    }

    public function toData(): AttemptData
    {
        return AttemptData::fromValidated($this->validated());
    }

    /**
     * The authenticated student, typed.
     *
     * The `auth` middleware guarantees one; this turns that guarantee into
     * something the service's signature can rely on rather than a nullable
     * Authenticatable.
     *
     * @throws AuthenticationException
     */
    public function student(): User
    {
        $user = $this->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }

    /** Long enough for a real short answer, short enough not to be an essay. */
    private const MAX_ANSWER_LENGTH = 1_000;

    /** One hour. */
    private const MAX_TIME_SPENT_MS = 3_600_000;
}
