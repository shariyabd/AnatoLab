<?php

declare(strict_types=1);

namespace App\Services\Rag;

use App\Contracts\RetrievedChunk;
use App\Contracts\VectorStoreInterface;
use App\Exceptions\AIProviderException;
use App\Exceptions\VectorStoreException;
use Illuminate\Support\Facades\Log;

/**
 * Embed a question, then ask the vector store what it is near
 * (docs/architecture.md §8.1, §8.2).
 *
 * The only caller of `VectorStoreInterface::search()`, which is why that method
 * takes a vector: embedding lives here, once, and both store implementations
 * stay interchangeable rather than each owning a copy of it.
 *
 * This is also where the relevance threshold is applied — deliberately here and
 * not in AITutorService, so that an empty return from the seam keeps meaning
 * "nothing was relevant" rather than "nothing was indexed". The tutor's honest
 * "no indexed source matched this question" note depends on that distinction
 * (docs/handovers/08-retrieval-seam.md §2).
 */
final class RetrievalService
{
    public function __construct(
        private readonly EmbeddingService $embeddings,
        private readonly VectorStoreInterface $store,
    ) {}

    /**
     * Passages relevant to $query, most similar first, threshold applied.
     *
     * A retrieval failure is not an answer failure. A provider that cannot
     * embed or a store that cannot be reached degrades the tutor to an
     * ungrounded answer that says it had no sources — the student still gets
     * taught, and the outage is in the log with the store or provider named
     * (docs/architecture.md §5, §14). Rethrowing here would turn a knowledge-
     * base outage into a broken tutor.
     *
     * @param  array<string, scalar|null>  $filters  organ_id, structure_id,
     *                                               education_level, content_type (PRD §28)
     * @return list<RetrievedChunk>
     */
    public function search(string $query, int $topK = 5, array $filters = []): array
    {
        if (trim($query) === '' || $topK < 1) {
            return [];
        }

        try {
            $vector = $this->embeddings->embed($query);

            if ($vector === []) {
                return [];
            }

            $chunks = $this->store->search($vector, $topK, $filters);
        } catch (AIProviderException|VectorStoreException $exception) {
            Log::warning('Retrieval unavailable; answering without sources.', [
                'exception' => $exception->getMessage(),
                'filters' => array_keys($filters),
            ]);

            return [];
        }

        return $this->aboveThreshold($chunks);
    }

    /**
     * Drop everything below `ai.retrieval.min_score`.
     *
     * A weak match is worse than no match: it is cited to the student as a
     * source, so a passage about the lungs attached to a question about the
     * heart does not merely fail to help, it misrepresents where the answer
     * came from (PRD §24).
     *
     * @param  list<RetrievedChunk>  $chunks
     * @return list<RetrievedChunk>
     */
    private function aboveThreshold(array $chunks): array
    {
        $minimum = (float) config('ai.retrieval.min_score', 0.65);

        return array_values(array_filter(
            $chunks,
            static fn (RetrievedChunk $chunk): bool => $chunk->score >= $minimum,
        ));
    }
}
