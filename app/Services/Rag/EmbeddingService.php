<?php

declare(strict_types=1);

namespace App\Services\Rag;

use App\Contracts\AIProviderInterface;
use App\Exceptions\VectorStoreException;

/**
 * Turns text into vectors (docs/architecture.md §8.1).
 *
 * It exists so that `VectorStoreInterface::search()` can take a vector rather
 * than a string: with embedding in one place, both store implementations stay
 * trivially interchangeable and one query vector can be scored against either.
 *
 * Depends on AIProviderInterface, never on a concrete provider (invariant 2).
 * RagServiceProvider binds that dependency contextually, so an installation can
 * run Anthropic for chat and OpenAI for embeddings — Anthropic publishes no
 * embeddings endpoint and its provider throws rather than returning zeros.
 */
final class EmbeddingService
{
    public function __construct(
        private readonly AIProviderInterface $provider,
    ) {}

    /**
     * @return list<float>
     *
     * @throws \App\Exceptions\AIProviderException
     * @throws VectorStoreException
     */
    public function embed(string $text): array
    {
        return $this->embedAll([$text])[0] ?? [];
    }

    /**
     * Embed many texts, in input order.
     *
     * Sent in batches of `ai.embeddings.batch_size` because a document is
     * thousands of chunks and providers cap the number of inputs per request;
     * one request per chunk is the difference between a minute and an hour of
     * ingest.
     *
     * @param  list<string>  $texts
     * @return list<list<float>>
     *
     * @throws \App\Exceptions\AIProviderException
     * @throws VectorStoreException
     */
    public function embedAll(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $batchSize = max(1, (int) config('ai.embeddings.batch_size', 64));
        $vectors = [];

        foreach (array_chunk($texts, $batchSize) as $batch) {
            foreach ($this->provider->generateEmbeddings($batch) as $vector) {
                $vectors[] = $this->assertDimensions($vector);
            }
        }

        return $vectors;
    }

    /**
     * The dimension count the configured store was built for.
     *
     * Read from the active store's own config block rather than from a single
     * global, because a Pinecone index is created at a fixed width and cannot
     * be re-dimensioned in place.
     */
    public function dimensions(): int
    {
        $store = config('ai.vector_store');
        $store = is_string($store) && $store !== '' ? $store : 'null';

        return (int) config("ai.vector_stores.{$store}.dimensions", 1536);
    }

    /**
     * Refuse a vector the configured store cannot hold.
     *
     * A mismatch means the corpus and the query were embedded by different
     * models. Both stores score that as zero similarity, so without this check
     * the symptom is "retrieval quietly returns nothing relevant" rather than
     * "the embedding model changed" — one of those is a five-minute fix.
     *
     * The `null` store has no dimensions of its own and accepts whatever the
     * null provider produced, which is how the test suite stays free of a
     * dimension it has to keep in sync.
     *
     * @param  list<float>  $vector
     * @return list<float>
     *
     * @throws VectorStoreException
     */
    private function assertDimensions(array $vector): array
    {
        $expected = $this->dimensions();
        $store = (string) (config('ai.vector_store') ?? 'null');

        if ($store !== 'null' && $store !== '' && count($vector) !== $expected) {
            throw VectorStoreException::dimensionMismatch($store, $expected, count($vector));
        }

        return $vector;
    }
}
