<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One labelled structure within an organ.
 *
 * Identity is `slug` + `ta_term` + `anchor_position`, not a mesh name. Every
 * audited GLB is a single mesh whose only node is `tripo_node_<uuid>`, so
 * there is no per-structure geometry to select
 * (docs/project-context.md §2.2, §5.5).
 *
 * `anchor_position` is therefore the working selection mechanism, and it is
 * authored in the FIT_SIZE = 3.8 pivot space every model is normalised into.
 * `model_object_name` is nullable and unused: if per-structure models ever
 * land, the viewer gains mesh picking with no schema and no API change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anatomical_structures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organ_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('slug');

            // Nullable because the admin authoring tool (Handover 13) creates a
            // hotspot from a click before its Terminologia Anatomica term has
            // been confirmed. Everything seeded here has one; the column is not
            // an invitation to skip it.
            $table->string('ta_term')->nullable();

            $table->string('name');
            $table->string('scientific_name')->nullable();
            $table->text('description')->nullable();
            $table->text('function')->nullable();
            $table->string('location')->nullable();
            $table->unsignedTinyInteger('difficulty')->default(1);

            // [x, y, z] in FIT_SIZE pivot space. JSON rather than three float
            // columns because it is read and written as one value and never
            // queried component-wise.
            $table->json('anchor_position');

            $table->string('model_object_name')->nullable();
            $table->string('marker_color', 9)->nullable();
            $table->json('metadata')->nullable();

            // Defaults to false: authored-but-unverified content must not reach
            // a student by omission (docs/architecture.md §6).
            $table->boolean('is_published')->default(false);
            $table->timestamps();

            $table->unique(['organ_id', 'slug']);

            // Resolution by TA term is how the question bank, the RAG filter,
            // and the AI context all address a structure
            // (docs/project-context.md §2.3).
            $table->index('ta_term');

            // The organ payload loads exactly this slice on every viewer load.
            $table->index(['organ_id', 'is_published']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anatomical_structures');
    }
};
