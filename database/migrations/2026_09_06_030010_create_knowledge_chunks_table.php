<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One retrievable passage (PRD §22 `knowledge_chunks`).
 *
 * Both vector stores are backed from here. `MySqlVectorStore` — the default —
 * filters on the indexed columns in SQL, loads the surviving `embedding` blobs
 * and scores them in PHP; `PineconeVectorStore` keeps the vector remotely and
 * records the id it was stored under in `embedding_reference`. Only one of the
 * two columns is populated in a given installation, which is why both are
 * nullable (docs/architecture.md §8.1).
 *
 * The filter columns are duplicated out of the parent document's metadata
 * rather than joined, because retrieval filters on them on every question and a
 * JSON extract cannot use an index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_chunks', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('document_id')
                ->constrained('knowledge_documents')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->unsignedInteger('chunk_index');
            $table->text('content');

            // Packed little-endian float32 (4 bytes per dimension), so a
            // 1536-dimension vector is 6 KB. float32 is well inside the
            // precision cosine similarity needs and halves both the row size
            // and the bytes read per query against float64.
            //
            // Written as BLOB here and widened to LONGBLOB for MySQL below.
            $table->binary('embedding')->nullable();

            // The id the vector was stored under in an external index. Unused by
            // MySqlVectorStore, which has the vector in the row above.
            $table->string('embedding_reference')->nullable();

            // Nullable because a document can be general anatomy that belongs to
            // no single organ; an unanchored chunk is still retrievable, it just
            // never wins an organ-filtered search.
            $table->foreignId('organ_id')->nullable()
                ->constrained('organs')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->foreignId('structure_id')->nullable()
                ->constrained('anatomical_structures')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->string('education_level', 32)->nullable();
            $table->string('content_type', 32)->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();

            // The exact filter set from PRD §28, in the order retrieval narrows:
            // organ first because it is the coarsest and the one most often set.
            $table->index(['organ_id', 'structure_id', 'education_level']);

            // A re-ingest rewrites chunk N of a document in place. The pair is
            // also the vector-store id, so uniqueness here is what makes
            // VectorStoreInterface::upsert() idempotent.
            $table->unique(['document_id', 'chunk_index']);
        });

        $this->widenEmbeddingColumn();
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_chunks');
    }

    /**
     * Laravel's schema builder has no LONGBLOB generator — `binary()` emits
     * BLOB, which caps at 64 KB. That holds today's 1536-dimension vectors with
     * room to spare, but a larger embedding model is a config change away and a
     * silently truncated vector would look like a retrieval-quality problem
     * rather than an overflow.
     *
     * This is the case docs/engineering.md §1 (invariant 6) allows DB::statement
     * for: Eloquent genuinely cannot express it. Guarded by driver, because the
     * test suite runs on SQLite, where BLOB is already unbounded.
     */
    private function widenEmbeddingColumn(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE knowledge_chunks MODIFY embedding LONGBLOB NULL');
    }
};
