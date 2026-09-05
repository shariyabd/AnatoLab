<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\KnowledgeDocument;
use App\Services\Rag\ChunkingService;
use App\Services\Rag\KnowledgeService;
use App\Services\Rag\TextChunk;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Step one of ingest: text out of the file, passages into the database
 * (docs/architecture.md §8.3).
 *
 * Queued, like the whole chain — an admin uploading a textbook must not hold a
 * web request open, and a student asking a question while it runs must not
 * queue behind it. `ingest` is its own queue for exactly that reason
 * (docs/architecture.md §11).
 *
 * Takes the document id rather than the model so a retry re-reads the row and
 * acts on the current title and metadata rather than on a snapshot taken before
 * an admin corrected them.
 */
final class ProcessKnowledgeDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(private readonly int $documentId)
    {
        $this->onQueue('ingest');
    }

    public function handle(KnowledgeService $knowledge, ChunkingService $chunking): void
    {
        $document = KnowledgeDocument::query()->find($this->documentId);

        if (! $document instanceof KnowledgeDocument) {
            // Deleted between dispatch and execution. Nothing to fail: the row
            // that would carry the failure is gone.
            return;
        }

        $knowledge->markProcessing($document);

        // Before chunking, not after: a second version with fewer passages must
        // not leave the first version's tail behind, in the table or the index.
        $knowledge->clearChunks($document);

        $chunks = $chunking->chunk($knowledge->extractText($document), $this->chunkMetadata($document));

        if ($chunks === []) {
            $knowledge->markFailed($document, 'The document contained no indexable text.');

            return;
        }

        $this->persist($document, $chunks);

        GenerateEmbeddings::dispatch($this->documentId);
    }

    /**
     * A document that cannot be processed says why, rather than sitting at
     * `processing` forever while an admin refreshes the list.
     */
    public function failed(?Throwable $exception): void
    {
        $document = KnowledgeDocument::query()->find($this->documentId);

        if ($document instanceof KnowledgeDocument) {
            app(KnowledgeService::class)->markFailed(
                $document,
                $exception?->getMessage() ?? 'Processing failed.',
            );
        }
    }

    /**
     * The retrieval filters every chunk inherits, plus the citation.
     *
     * `source_title` travels in metadata because a store that keeps its vectors
     * elsewhere — Pinecone — has no join back to `knowledge_documents` and
     * would otherwise return a passage it cannot attribute (PRD §12).
     *
     * @return array<string, scalar|null>
     */
    private function chunkMetadata(KnowledgeDocument $document): array
    {
        return [
            ...$document->retrievalDefaults(),
            'source_title' => $document->title,
            'document_id' => (int) $document->getKey(),
        ];
    }

    /**
     * @param  list<TextChunk>  $chunks
     */
    private function persist(KnowledgeDocument $document, array $chunks): void
    {
        foreach ($chunks as $chunk) {
            $document->chunks()->create([
                'chunk_index' => $chunk->index,
                'content' => $chunk->content,
                'metadata' => $chunk->metadata,
                'organ_id' => $chunk->metadata['organ_id'] ?? null,
                'structure_id' => $chunk->metadata['structure_id'] ?? null,
                'education_level' => $chunk->metadata['education_level'] ?? null,
                'content_type' => $chunk->metadata['content_type'] ?? null,
            ]);
        }
    }
}
