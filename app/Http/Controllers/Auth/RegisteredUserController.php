<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\DifficultyPreference;
use App\Enums\EducationLevel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class RegisteredUserController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Register', [
            'educationLevels' => $this->options(EducationLevel::cases()),
            'difficultyPreferences' => $this->options(DifficultyPreference::cases()),
        ]);
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $user = User::create($request->safe()->only([
            'name',
            'email',
            'password',
            'education_level',
            'difficulty_preference',
        ]));

        Auth::login($user);

        $request->session()->regenerate();

        return to_route('dashboard');
    }

    /**
     * @param  list<EducationLevel|DifficultyPreference>  $cases
     * @return list<array{value: string, label: string}>
     */
    private function options(array $cases): array
    {
        return array_map(
            static fn (EducationLevel|DifficultyPreference $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
            ],
            $cases,
        );
    }
}
