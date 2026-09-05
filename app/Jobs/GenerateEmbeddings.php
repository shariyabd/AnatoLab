<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Rag\EmbeddingService;
use App\Services\Rag\KnowledgeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Step two of ingest: a vector for every passage
 * (docs/architecture.md §8.3).
 *
 * Embeds through EmbeddingService, which batches: a document is hundreds of
 * chunks, and one request per chunk is the difference between a minute of
 * ingest and an hour of it.
 *
 * The vectors are written to `knowledge_chunks.embedding` whichever store is
 * configured, not only for MySqlVectorStore. That copy is what makes
 * `VECTOR_STORE` a switch rather than a migration: pointing an installation at
 * the other store re-syncs from rows that already exist instead of paying for
 * the whole corpus to be embedded again.
 */
final class GenerateEmbeddings implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(private readonly int $documentId)
    {
        $this->onQueue('ingest');
    }

    public function handle(KnowledgeService $knowledge, EmbeddingService $embeddings): void
    {
        $document = KnowledgeDocument::query()->find($this->documentId);

        if (! $document instanceof KnowledgeDocument) {
            return;
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, KnowledgeChunk> $chunks */
        $chunks = $document->chunks()->get();

        if ($chunks->isEmpty()) {
            $knowledge->markFailed($document, 'There were no chunks to embed.');

            return;
        }

        $vectors = $embeddings->embedAll(
            $chunks->map(static fn (KnowledgeChunk $chunk): string => $chunk->content)->values()->all()
        );

        if (count($vectors) !== $chunks->count()) {
            $knowledge->markFailed($document, 'The provider returned fewer embeddings than chunks.');

            return;
        }

        foreach ($chunks as $position => $chunk) {
            $chunk->embedding = $vectors[$position];
            $chunk->save();
        }

        SyncVectorStore::dispatch($this->documentId);
    }

    public function failed(?Throwable $exception): void
    {
        $document = KnowledgeDocument::query()->find($this->documentId);

        if ($document instanceof KnowledgeDocument) {
            app(KnowledgeService::class)->markFailed(
                $document,
                $exception?->getMessage() ?? 'Embedding failed.',
            );
        }
    }
}
