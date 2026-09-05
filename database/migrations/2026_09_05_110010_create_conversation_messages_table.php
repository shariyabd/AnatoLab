<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One turn in a tutor thread (PRD §22 `conversation_messages`).
 *
 * Append-only by design, which is why there is a `created_at` and no
 * `updated_at`: a message that has been edited is not the message the student
 * was shown, and the audit value of the table depends on that staying true.
 *
 * `metadata` carries the retrieved source ids for an assistant turn
 * (docs/architecture.md §8.2). It ships as an empty list until Handover 11
 * implements retrieval — the column exists now so that landing retrieval is an
 * insertion rather than a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();

            $table->string('role', 16);
            $table->text('content');

            // Assistant turns record model, token usage, whether the validator
            // replaced the answer, and the retrieved source ids. Never the
            // prompt: prompts carry student data and are not logged or stored
            // (docs/engineering.md §10).
            $table->json('metadata')->nullable();

            $table->timestamp('created_at')->nullable();

            // The thread transcript, in order.
            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_messages');
    }
};
