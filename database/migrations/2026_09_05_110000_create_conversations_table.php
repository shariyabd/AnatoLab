<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One tutor thread belonging to one student (PRD §22 `conversations`).
 *
 * `context_type` + `context_id` record what the student was looking at when the
 * thread started. Deliberately not a polymorphic relation: nothing loads the
 * context model back, so a morphTo would add a relation with no caller
 * (see App\Enums\ConversationContextType).
 *
 * Ownership is enforced in AITutorService from the passed-in User, never from a
 * request parameter (docs/engineering.md §10). The foreign key here is the
 * second line of defence, not the first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();

            $table->string('context_type', 32)->default('general');

            // Nullable and unconstrained: it may point at an organ, a structure,
            // or a lesson, and `lessons` does not exist until Handover 06. A
            // real foreign key would have to be one of the three.
            $table->unsignedBigInteger('context_id')->nullable();

            // Derived from the student's first question, so a history list has
            // something to show without loading every message.
            $table->string('title');

            $table->timestamps();

            // The history list: this user's threads, most recent first.
            $table->index(['user_id', 'updated_at']);

            // "Threads about the left ventricle" — how the panel resumes an
            // existing conversation instead of starting a new one per question.
            $table->index(['user_id', 'context_type', 'context_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
