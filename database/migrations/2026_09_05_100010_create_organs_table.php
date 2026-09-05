<?php

declare(strict_types=1);

use App\Enums\ModelFormat;
use App\Enums\OrganStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An organ, its 3D model, and its accent colour.
 *
 * `model_path` is disk-relative, resolved against config('anatomy.model_disk')
 * by the API Resource. Storing a path rather than a URL is what lets the same
 * row serve a local disk in development and a bucket in production without a
 * data migration (docs/architecture.md §2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('body_system_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('scientific_name')->nullable();
            $table->text('description')->nullable();

            // NOT NULL deliberately: types.ts types OrganDto.modelUrl as a
            // plain string, so an organ that cannot produce one cannot satisfy
            // the frozen contract. Organs whose model is still behind the
            // licence gate carry a placeholder path and stay `draft`
            // (docs/licence-log.md §3).
            $table->string('model_path');
            $table->string('model_format')->default(ModelFormat::Glb->value);
            $table->string('thumbnail_path')->nullable();
            $table->string('accent_color', 9);
            $table->string('status')->default(OrganStatus::Draft->value);
            $table->timestamps();

            // The organ list filters on status on every page load.
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organs');
    }
};
