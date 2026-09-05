<?php

declare(strict_types=1);

namespace App\Http\Requests\Progress;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The one thing a progress request may say: which organ the student is looking
 * at right now.
 *
 * Shared by all three read endpoints, because all three carry a
 * recommendation and the hint means the same thing to each. It is a tie-break
 * hint and nothing more (docs/architecture.md §10) — an
 * unknown or draft slug is ignored rather than rejected, because the
 * recommendation is still perfectly answerable without it and a 422 here would
 * turn a stale tab into a broken dashboard.
 *
 * Validated for shape only, not with `exists`: `RecommendationService` looks
 * the slug up among *published* organs, which is stricter than existence and
 * keeps the "does this draft organ exist" question unanswerable from a URL —
 * the same call App\Services\Anatomy\AnatomyService makes.
 */
final class ProgressRequest extends FormRequest
{
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
            'organ' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9-]+$/'],
        ];
    }

    public function currentOrganSlug(): ?string
    {
        $slug = $this->validated('organ');

        return is_string($slug) && $slug !== '' ? $slug : null;
    }

    /**
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
