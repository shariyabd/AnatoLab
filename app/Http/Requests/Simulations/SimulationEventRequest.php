<?php

declare(strict_types=1);

namespace App\Http\Requests\Simulations;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * What a student may state about a simulation step.
 *
 * One field. That is the point of the shape: the client says *which action it
 * took*, and nothing about what the action does, what the state becomes, or
 * what the viewer should show. There is no `state`, no `effects` and no
 * `visualDirectives` to validate because there is no way for the browser to
 * assert one — every number in the response is computed in PHP from the
 * simulation's own configuration (docs/architecture.md §12, PRD §14).
 *
 * The id is validated for shape only, not against the configuration. Existence
 * is SimulationService's business: an action id belonging to a different
 * simulation *exists* and is still not an action on this one, and resolving it
 * through the simulation it was sent for is a stricter check than a validation
 * rule could express — the same reasoning as
 * App\Http\Requests\Assessment\RecordAttemptRequest.
 */
final class SimulationEventRequest extends FormRequest
{
    /**
     * The `auth` middleware plus per-record scoping in SimulationService, which
     * files the run against the passed-in User. There is no existing record to
     * authorize against — a first event creates the session — so there is
     * nothing here for a policy to decide (docs/architecture.md §14).
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
            // Matches the identifier grammar SimulationAction accepts, so a
            // payload that could never name an action is rejected before it
            // reaches a database read.
            'actionId' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.-]+$/'],
        ];
    }

    public function actionId(): string
    {
        /** @var array{actionId: string} $validated */
        $validated = $this->validated();

        return $validated['actionId'];
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
}
