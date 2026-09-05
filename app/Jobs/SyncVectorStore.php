<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\VectorStoreInterface;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Rag\KnowledgeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Step three of ingest: the passages become searchable
 * (docs/architecture.md §8.3).
 *
 * Talks to VectorStoreInterface and to nothing store-specific, which is the
 * whole reason this is a separate job: the same code indexes into MySQL or into
 * Pinecone, and switching between them is `VECTOR_STORE` in the environment
 * (PRD §43). It is also the step that is safe to re-run on its own — a store
 * that was rebuilt or switched needs re-syncing, not re-embedding.
 *
 * The document reaches `indexed` here and nowhere else: a student can be shown
 * a citation from this document only once its vectors are actually in the index.
 */
final class SyncVectorStore implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Vectors per upsert call. Large enough that a document is a handful of
     * round trips, small enough to stay inside a managed index's request size
     * limit — Pinecone caps an upsert at a couple of megabytes, and 1536
     * float32 dimensions serialise to roughly 20 KB of JSON each.
     */
    private const BATCH = 100;

    public function __construct(private readonly int $documentId)
    {
        $this->onQueue('ingest');
    }

    public function handle(KnowledgeService $knowledge, VectorStoreInterface $store): void
    {
        $document = KnowledgeDocument::query()->find($this->documentId);

        if (! $document instanceof KnowledgeDocument) {
            return;
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, KnowledgeChunk> $chunks */
        $chunks = $document->chunks()->whereNotNull('embedding')->get();

        if ($chunks->isEmpty()) {
            $knowledge->markFailed($document, 'No embedded chunks were available to index.');

            return;
        }

        foreach ($chunks->chunk(self::BATCH) as $batch) {
            $store->upsert($batch->map($this->toStoreDocument(...))->values()->all());
        }

        // Recorded after the upsert succeeded, so the column means "this chunk
        // is in the index under this id" rather than "we intended to put it
        // there". It is the same id in both stores by design: re-pointing
        // VECTOR_STORE must not invalidate it.
        foreach ($chunks as $chunk) {
            $chunk->embedding_reference = $chunk->vectorId();
            $chunk->save();
        }

        $knowledge->markIndexed($document);
    }

    public function failed(?Throwable $exception): void
    {
        $document = KnowledgeDocument::query()->find($this->documentId);

        if ($document instanceof KnowledgeDocument) {
            app(KnowledgeService::class)->markFailed(
                $document,
                $exception?->getMessage() ?? 'Indexing failed.',
            );
        }
    }

    /**
     * @return array{
     *     id: string,
     *     vector: list<float>,
     *     content: string,
     *     metadata: array<string, scalar|null>
     * }
     */
    private function toStoreDocument(KnowledgeChunk $chunk): array
    {
        /** @var array<string, scalar|null> $metadata */
        $metadata = $chunk->metadata ?? [];

        return [
            'id' => $chunk->vectorId(),
            'vector' => $chunk->embedding ?? [],
            'content' => $chunk->content,
            'metadata' => $metadata,
        ];
    }
}
