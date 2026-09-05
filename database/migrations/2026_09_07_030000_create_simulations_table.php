<?php

declare(strict_types=1);

use App\Enums\SimulationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A "what happens if…" simulation (PRD §14, §22; docs/architecture.md §12).
 *
 * Timestamp band: Handover 12 was allocated `..._0300_*` on 2026-09-07 so that
 * batch D's three table owners — 09 (`..._0100_*`), 10 (`..._0200_*`) and this
 * one — interleave deterministically when the three branches merge
 * (docs/handovers/parallel-execution-plan.md §3 C3). The date is a day after
 * batch C's so `organs` already exists when this foreign key is created.
 *
 * `configuration` is the whole simulation: `initial_state`, `variables`,
 * `actions`, `thresholds` and curated `explanations`. A JSON column rather
 * than four normalised tables for the same reason `lessons.content` is one —
 * nothing queries, filters or joins an individual action or threshold. They
 * are only ever read as part of the simulation that owns them, and they are
 * read together, in order, on every step.
 *
 * The important property of that column is that it is the *entire* behaviour.
 * A simulation is a JSON config and a `match` expression, not a state-machine
 * library (docs/engineering.md §12), and nothing outside
 * App\Services\Simulation may decide what a step does. That is what makes a
 * replay reproducible: the same configuration and the same ordered action ids
 * produce the same state, every time, on any machine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('simulations', function (Blueprint $table): void {
            $table->id();

            // Restrict rather than cascade, matching `lessons.organ_id`:
            // deleting an organ that still has simulations authored against it
            // is a content mistake, and silently deleting them hides it.
            $table->foreignId('organ_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();

            $table->string('slug')->unique();
            $table->string('title');
            $table->text('description')->nullable();

            // The one-line framing shown above every run. Not nullable: PRD §14
            // is explicit that these are educational models and never
            // diagnostic tools, and a simulation with nothing to say about its
            // own scope has no honest way to open.
            $table->text('premise');

            $table->json('configuration');
            $table->string('status')->default(SimulationStatus::Draft->value);
            $table->timestamps();

            // The picker lists published simulations, and the explore page will
            // offer the ones belonging to the organ on screen.
            $table->index(['status', 'organ_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('simulations');
    }
};
