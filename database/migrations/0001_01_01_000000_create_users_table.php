<?php

declare(strict_types=1);

use App\Enums\DifficultyPreference;
use App\Enums\EducationLevel;
use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');

            // Authorization. Every account is a student unless promoted, so an
            // account created by any future path is safe by default.
            $table->enum('role', UserRole::values())->default(UserRole::Student->value)->index();

            // Personalisation (PRD §11, §22). Reading level and challenge level
            // are separate axes — see the enum docblocks.
            $table->enum('education_level', EducationLevel::values())
                ->default(EducationLevel::HighSchool->value);
            $table->enum('difficulty_preference', DifficultyPreference::values())
                ->default(DifficultyPreference::Beginner->value);

            // Gamification (PRD §16). Owned here rather than in F10's tables
            // because they are attributes of the account, and every page that
            // renders a user renders them — a join for two integers is waste.
            $table->unsignedInteger('xp')->default(0);
            $table->unsignedSmallInteger('level')->default(1);

            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
