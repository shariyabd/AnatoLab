<?php

declare(strict_types=1);

namespace App\Http\Requests\Missions;

use App\Models\User;
use App\Services\Assessment\MissionAttemptData;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * What a student may state about their own run at a mission.
 *
 * The shape of the list is the point, exactly as on `RecordAttemptRequest`: it
 * accepts *what was picked at each step* and *how it went*, and nothing about
 * whether any of it was right. There is no outcome field to validate because
 * there is no way for the client to assert one — every step is compared
 * against `missions.configuration` in `MissionService`, server-side
 * (invariant 4, docs/architecture.md §5.4 rule 2).
 *
 * Structure ids are validated for shape only, not with `exists`. Existence is
 * not the question: a structure on a different organ *exists* and is still not
 * an answer to this mission. The service resolves every pick against the
 * organ payload the client was actually sent, which is a stricter check than
 * any rule could express and keeps it in one place.
 *
 * Steps are positional — entry *n* answers configured step *n* — so the array
 * is not required to be complete. A short list is a run abandoned partway, and
 * the missing steps are scored as misses with no pick.
 *
 * Everything ends at toData(): the controller never sees `$request->all()` and
 * the service never sees a Request (invariant 7).
 */
final class RecordMissionAttemptRequest extends FormRequest
{
    /**
     * The `auth` middleware, plus per-record scoping in `MissionService`, which
     * files the run against the passed-in User. There is no existing record to
     * authorize against at this point, so there is nothing here for a policy to
     * decide (docs/architecture.md §14, matching RecordAttemptRequest).
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
            // Capped rather than unbounded: the array is walked once per step
            // and a mission nobody authored cannot have a thousand of them.
            'steps' => ['required', 'array', 'max:'.self::MAX_STEPS],
            'steps.*' => ['array'],
            'steps.*.selectedStructureId' => ['nullable', 'integer', 'min:1'],
            'steps.*.hintUsed' => ['nullable', 'boolean'],
            // Capped at an hour each, for the reason RecordAttemptRequest gives:
            // timing feeds mastery's recency and speed signals
            // (docs/architecture.md §10), and an unbounded number from a client
            // is a number that can be made to mean anything.
            'steps.*.timeSpentMs' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_TIME_SPENT_MS],
            'durationMs' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_DURATION_MS],
        ];
    }

    public function toData(): MissionAttemptData
    {
        return MissionAttemptData::fromValidated($this->validated());
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

    /** Far more than any authored mission; small enough to bound the work. */
    private const MAX_STEPS = 50;

    /** One hour. */
    private const MAX_TIME_SPENT_MS = 3_600_000;

    /** Four hours — a whole session, not a step. */
    private const MAX_DURATION_MS = 14_400_000;
}
