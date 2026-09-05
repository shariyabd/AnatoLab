<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DifficultyPreference;
use App\Enums\EducationLevel;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * Hash once for the whole suite. Bcrypt is intentionally slow; hashing per
     * user turns a fast test run into a slow one for no added coverage.
     */
    private static ?string $passwordHash = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => self::$passwordHash ??= Hash::make('password'),
            'role' => UserRole::Student,
            'education_level' => EducationLevel::HighSchool,
            'difficulty_preference' => DifficultyPreference::Beginner,
            'xp' => 0,
            'level' => 1,
            'remember_token' => Str::random(10),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => UserRole::Admin,
        ]);
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }
}
