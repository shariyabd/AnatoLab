<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * One knowledge-base passage returned by a VectorStoreInterface search.
 *
 * Carries its own source attribution because PRD §12 requires the tutor to
 * cite what it used. A chunk that cannot say where it came from is unusable
 * for a grounded answer, so sourceTitle is required rather than optional.
 */
final readonly class RetrievedChunk
{
    /**
     * @param  string  $id  vector-store document id; opaque to callers
     * @param  string  $content  the passage text, as indexed
     * @param  float  $score  similarity in [0,1], higher is closer
     * @param  string  $sourceTitle  human-readable citation shown to the student
     * @param  array<string, scalar|null>  $metadata  filter keys: organ_id,
     *                                                structure_id, education_level, page
     */
    public function __construct(
        public string $id,
        public string $content,
        public float $score,
        public string $sourceTitle,
        public array $metadata = [],
    ) {}
}
