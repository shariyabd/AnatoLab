<?php

declare(strict_types=1);

namespace App\Infrastructure\VectorStore;

use App\Contracts\RetrievedChunk;
use App\Contracts\VectorStoreInterface;

/**
 * An in-memory vector store for tests and for running the app before
 * Handover 11 implements the MySQL one.
 *
 * It implements filtering and cosine similarity for real rather than returning
 * canned rows. That matters: the shared VectorStoreInterface conformance suite
 * (docs/engineering.md §9) runs against every implementation, so a null store
 * that faked its results would let a genuine ranking bug through in the real one.
 *
 * State lives on the instance, so it must be bound as a singleton to persist
 * across a request — AppServiceProvider does that.
 */
final class NullVectorStore implements VectorStoreInterface
{
    /**
     * @var array<string, array{
     *     id: string,
     *     vector: list<float>,
     *     content: string,
     *     metadata: array<string, scalar|null>
     * }>
     */
    private array $documents = [];

    /**
     * @param  list<array{
     *     id: string,
     *     vector: list<float>,
     *     content: string,
     *     metadata: array<string, scalar|null>
     * }>  $documents
     */
    public function upsert(array $documents): void
    {
        foreach ($documents as $document) {
            $this->documents[$document['id']] = $document;
        }
    }

    /**
     * @param  list<float>  $queryVector
     * @param  array<string, scalar|null>  $filters
     * @return list<RetrievedChunk>
     */
    public function search(array $queryVector, int $topK = 5, array $filters = []): array
    {
        $scored = [];

        foreach ($this->documents as $document) {
            if (! $this->matchesFilters($document['metadata'], $filters)) {
                continue;
            }

            $scored[] = new RetrievedChunk(
                id: $document['id'],
                content: $document['content'],
                score: $this->cosineSimilarity($queryVector, $document['vector']),
                sourceTitle: (string) ($document['metadata']['source_title'] ?? 'Untitled source'),
                metadata: $document['metadata'],
            );
        }

        usort($scored, static fn (RetrievedChunk $a, RetrievedChunk $b): int => $b->score <=> $a->score);

        return array_slice($scored, 0, max(0, $topK));
    }

    /**
     * @param  list<string>  $ids
     */
    public function delete(array $ids): void
    {
        foreach ($ids as $id) {
            unset($this->documents[$id]);
        }
    }

    /**
     * Test affordance: drop everything between cases without rebuilding the
     * container binding.
     */
    public function flush(): void
    {
        $this->documents = [];
    }

    public function count(): int
    {
        return count($this->documents);
    }

    /**
     * @param  array<string, scalar|null>  $metadata
     * @param  array<string, scalar|null>  $filters
     */
    private function matchesFilters(array $metadata, array $filters): bool
    {
        foreach ($filters as $key => $value) {
            if (($metadata[$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        // Mismatched dimensions mean the corpus was embedded with a different
        // model than the query. Scoring it anyway would rank garbage highly, so
        // treat it as "no similarity" and let the caller's min_score drop it.
        if (count($a) !== count($b) || $a === []) {
            return 0.0;
        }

        $dot = 0.0;
        $magnitudeA = 0.0;
        $magnitudeB = 0.0;

        foreach ($a as $index => $value) {
            $dot += $value * $b[$index];
            $magnitudeA += $value ** 2;
            $magnitudeB += $b[$index] ** 2;
        }

        if ($magnitudeA === 0.0 || $magnitudeB === 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($magnitudeA) * sqrt($magnitudeB));
    }
}
