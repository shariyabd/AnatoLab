<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organ systems, as a table rather than a free-text column on `organs`.
 *
 * Mastery and progress are reported per system (docs/architecture.md §6,
 * PRD §15/§18) and free text cannot be grouped reliably — "Cardiovascular",
 * "cardiovascular" and "Circulatory" would be three systems on a dashboard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('body_systems', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('body_systems');
    }
};
