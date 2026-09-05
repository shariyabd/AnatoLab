<?php

declare(strict_types=1);

namespace App\Services\Rag;

/**
 * One passage produced by ChunkingService, before it has an embedding.
 *
 * Deliberately not a KnowledgeChunk: chunking is a pure text operation, and
 * keeping its output free of Eloquent is what lets the boundary and overlap
 * rules be unit-tested without a database row (docs/engineering.md §9).
 */
final readonly class TextChunk
{
    /**
     * @param  int  $index  position in the document, 0-based
     * @param  array<string, scalar|null>  $metadata  the retrieval filters this
     *                                                chunk inherited from its document
     */
    public function __construct(
        public int $index,
        public string $content,
        public int $wordCount,
        public array $metadata = [],
    ) {}
}
