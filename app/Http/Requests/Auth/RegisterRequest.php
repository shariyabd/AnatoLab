<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Enums\DifficultyPreference;
use App\Enums\EducationLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Registration input.
 *
 * `role` is not accepted here at any strength of validation — it is not in the
 * model's $fillable either. Two independent barriers, because privilege
 * escalation through mass assignment is the one mistake in this file that
 * cannot be walked back.
 */
final class RegisterRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],

            // 'email:rfc,strict' rather than the bare 'email' rule: the default
            // rule accepts folded/obsolete forms and is the subject of
            // CVE-2026-48019 (CRLF injection), which has no fix in Laravel 11.
            'email' => ['required', 'string', 'email:rfc,strict', 'max:255', 'unique:users,email'],

            'password' => ['required', 'confirmed', Password::defaults()],

            'education_level' => ['required', Rule::enum(EducationLevel::class)],
            'difficulty_preference' => ['required', Rule::enum(DifficultyPreference::class)],
        ];
    }
}
