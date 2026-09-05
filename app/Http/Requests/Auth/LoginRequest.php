<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
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
            // See RegisterRequest for why this is not the bare 'email' rule.
            'email' => ['required', 'string', 'email:rfc,strict'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Rate-limit key. Scoped to email AND IP so one attacker cannot lock a
     * victim out of their own account by spraying their address.
     */
    public function throttleKey(): string
    {
        return mb_strtolower((string) $this->string('email')).'|'.$this->ip();
    }
}
