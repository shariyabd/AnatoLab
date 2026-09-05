<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * A similarity search index over knowledge-base chunks.
 *
 * The second and last abstraction (docs/engineering.md §5). MySqlVectorStore is
 * the default — at MVP corpus size a filtered cosine scan in PHP is fast enough
 * and adds no external dependency. PineconeVectorStore proves the seam.
 *
 * search() takes a VECTOR, not a string. Deliberately: if it took a string,
 * every implementation would have to own an embedding step, which duplicates
 * logic and makes the two adapters non-interchangeable. Embedding lives in
 * EmbeddingService; RetrievalService is the only caller and does embed-then-
 * search (docs/architecture.md §8.1).
 */
interface VectorStoreInterface
{
    /**
     * Insert or replace documents by id.
     *
     * Idempotent: re-upserting the same id overwrites rather than duplicating,
     * so a re-ingest of an updated document is safe to run twice.
     *
     * @param  list<array{
     *     id: string,
     *     vector: list<float>,
     *     content: string,
     *     metadata: array<string, scalar|null>
     * }>  $documents
     */
    public function upsert(array $documents): void;

    /**
     * Nearest neighbours to $queryVector, most similar first.
     *
     * $filters are exact-match metadata constraints applied BEFORE scoring —
     * organ_id, structure_id, education_level. Filtering first is what keeps
     * the MySQL implementation viable: it scores hundreds of rows, not all.
     *
     * @param  list<float>  $queryVector
     * @param  array<string, scalar|null>  $filters
     * @return list<RetrievedChunk>
     */
    public function search(array $queryVector, int $topK = 5, array $filters = []): array;

    /**
     * Remove documents by id. Unknown ids are ignored, not an error.
     *
     * @param  list<string>  $ids
     */
    public function delete(array $ids): void;
}
