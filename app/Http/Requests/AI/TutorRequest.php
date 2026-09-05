<?php

declare(strict_types=1);

namespace App\Http\Requests\AI;

use App\Enums\TutorTask;
use App\Models\User;
use App\Services\AI\TutorRequestData;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared validation for the three tutor endpoints.
 *
 * The subclasses differ only in whether a question is required, so the rules
 * that describe *what the student is looking at* live here once. Every one of
 * them ends at toData(): a controller never sees `$request->all()`, and the
 * service never sees a Request (invariant 7).
 *
 * Note what is not validated here, because it is not accepted at all: education
 * level, lesson title, and organ name are resolved server-side. A field the
 * client can set is a field that ends up in a prompt.
 */
abstract class TutorRequest extends FormRequest
{
    /**
     * Authorization is the `auth` middleware plus per-record scoping in
     * AITutorService: a conversationId naming someone else's thread starts a
     * new one rather than appending to it (docs/architecture.md §14). There is
     * no existing record to authorize against at this point, so there is
     * nothing here for a policy to decide.
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
            // Slug rather than id, matching how Handover 03's read API resolves
            // an organ (routes/features/anatomy.php).
            'organSlug' => ['nullable', 'string', 'max:120'],
            'structureId' => ['nullable', 'integer', 'min:1'],
            'conversationId' => ['nullable', 'integer', 'min:1'],
        ];
    }

    abstract protected function task(): TutorTask;

    public function toData(): TutorRequestData
    {
        return TutorRequestData::fromValidated($this->task(), $this->validated());
    }

    /**
     * The authenticated student, typed.
     *
     * The `auth` middleware guarantees one; this converts that guarantee into
     * something the service's signature can rely on instead of a nullable
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

    /**
     * The ceiling on a student question.
     *
     * Long enough for a real one, short enough that the prompt cannot be
     * stuffed with pasted text — the request body is the one part of the prompt
     * a student controls.
     */
    protected const MAX_QUESTION_LENGTH = 500;
}
