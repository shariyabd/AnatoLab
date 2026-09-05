<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One curated educational source (PRD §22 `knowledge_documents`).
 *
 * The row is created by KnowledgeService the moment an upload is accepted and
 * is the ingest pipeline's state machine: `pending → processing → indexed |
 * failed` (docs/architecture.md §8.3). Nothing past `pending` happens in a web
 * request, so this row is also what the admin UI polls.
 *
 * `metadata` carries the retrieval defaults a document's chunks inherit —
 * organ_id, structure_id, education_level, content_type — plus the failure
 * reason when status is `failed`. They live in JSON here and as real, indexed
 * columns on `knowledge_chunks`, because chunks are what retrieval filters and
 * documents are only ever read one at a time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_documents', function (Blueprint $table): void {
            $table->id();

            $table->string('title');

            // Where the content came from, as a human would cite it: a book
            // title and edition, a URL, a curriculum reference.
            $table->string('source');
            $table->string('source_type', 32);

            // Bumped on every re-ingest. A re-uploaded document keeps its id and
            // its chunks are rebuilt, so `version` is how an admin tells which
            // pass produced the text they are looking at.
            $table->unsignedInteger('version')->default(1);

            $table->string('status', 16)->default('pending');

            // Null for a document supplied as plain text rather than a file
            // (F13 offers both). A path here is relative to the private disk and
            // is a generated name — never the uploaded filename, which is
            // attacker-controlled (docs/engineering.md §10).
            $table->string('storage_path')->nullable();
            $table->string('original_filename')->nullable();
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();

            // The admin list is "everything still processing" and "everything
            // that failed", both ordered by recency.
            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_documents');
    }
};
