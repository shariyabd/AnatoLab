<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\DifficultyPreference;
use App\Enums\EducationLevel;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * The two accounts every other seeder and the demo journey assume exist.
 *
 * Idempotent by email, so `db:seed` is safe to re-run and does not reset a
 * password someone has already changed locally.
 */
final class PlatformSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->firstOrCreate(
            ['email' => 'admin@anatolab.test'],
            [
                'name' => 'AnatoLab Admin',
                'password' => Hash::make('password'),
                'role' => UserRole::Admin,
                'education_level' => EducationLevel::Advanced,
                'difficulty_preference' => DifficultyPreference::Advanced,
                'email_verified_at' => now(),
            ],
        );

        User::query()->firstOrCreate(
            ['email' => 'student@anatolab.test'],
            [
                'name' => 'Demo Student',
                'password' => Hash::make('password'),
                'role' => UserRole::Student,
                'education_level' => EducationLevel::HighSchool,
                'difficulty_preference' => DifficultyPreference::Beginner,
                'email_verified_at' => now(),
            ],
        );
    }
}
