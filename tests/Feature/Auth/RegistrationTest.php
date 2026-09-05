<?php

declare(strict_types=1);

use App\Enums\DifficultyPreference;
use App\Enums\EducationLevel;
use App\Enums\UserRole;
use App\Models\User;

it('renders the registration screen with the personalisation options', function (): void {
    $this->get('/register')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Auth/Register')
            ->has('educationLevels', count(EducationLevel::cases()))
            ->has('difficultyPreferences', count(DifficultyPreference::cases()))
        );
});

it('registers a student and logs them straight in', function (): void {
    $this->post('/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.test',
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
        'education_level' => EducationLevel::HighSchool->value,
        'difficulty_preference' => DifficultyPreference::Intermediate->value,
    ])->assertRedirect('/dashboard');

    $this->assertAuthenticated();

    $user = User::query()->where('email', 'ada@example.test')->sole();

    expect($user->education_level)->toBe(EducationLevel::HighSchool)
        ->and($user->difficulty_preference)->toBe(DifficultyPreference::Intermediate)
        ->and($user->xp)->toBe(0)
        ->and($user->level)->toBe(1);
});

it('never lets a registration payload create an administrator', function (): void {
    // Two independent barriers protect this: `role` is absent from $fillable
    // and absent from the validated input. Privilege escalation through mass
    // assignment is the one mistake here that cannot be walked back.
    $this->post('/register', [
        'name' => 'Mallory',
        'email' => 'mallory@example.test',
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
        'education_level' => EducationLevel::HighSchool->value,
        'difficulty_preference' => DifficultyPreference::Beginner->value,
        'role' => UserRole::Admin->value,
    ]);

    expect(User::query()->where('email', 'mallory@example.test')->sole()->role)
        ->toBe(UserRole::Student);
});

it('rejects a duplicate email', function (): void {
    User::factory()->create(['email' => 'taken@example.test']);

    $this->post('/register', [
        'name' => 'Someone',
        'email' => 'taken@example.test',
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
        'education_level' => EducationLevel::HighSchool->value,
        'difficulty_preference' => DifficultyPreference::Beginner->value,
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('rejects a short password', function (): void {
    $this->post('/register', [
        'name' => 'Someone',
        'email' => 'short@example.test',
        'password' => 'short',
        'password_confirmation' => 'short',
        'education_level' => EducationLevel::HighSchool->value,
        'difficulty_preference' => DifficultyPreference::Beginner->value,
    ])->assertSessionHasErrors('password');

    $this->assertGuest();
});

it('rejects an unknown education level', function (): void {
    $this->post('/register', [
        'name' => 'Someone',
        'email' => 'level@example.test',
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
        'education_level' => 'postgraduate',
        'difficulty_preference' => DifficultyPreference::Beginner->value,
    ])->assertSessionHasErrors('education_level');
});
