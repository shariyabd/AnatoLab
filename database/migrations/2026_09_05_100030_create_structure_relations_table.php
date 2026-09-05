<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Related structures" from PRD §7 — drives the compare panel and the AI
 * context builder (docs/architecture.md §6).
 *
 * Directed and typed rather than a plain many-to-many: "the left ventricle
 * ejects into the aorta" is not the same statement as its reverse, and the
 * tutor needs the verb to say anything useful. The seeder writes both
 * directions where the relationship is genuinely symmetric.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('structure_relations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('structure_id')
                ->constrained('anatomical_structures')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignId('related_structure_id')
                ->constrained('anatomical_structures')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->string('relation_type');
            $table->timestamps();

            $table->unique(['structure_id', 'related_structure_id', 'relation_type'], 'structure_relations_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('structure_relations');
    }
};
