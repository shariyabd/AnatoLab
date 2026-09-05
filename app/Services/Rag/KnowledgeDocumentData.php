<?php

declare(strict_types=1);

namespace App\Services\Rag;

use App\Enums\KnowledgeSourceType;

/**
 * The validated description of a document being added to the knowledge base.
 *
 * A typed object rather than an array, so KnowledgeService never receives
 * `$request->all()` and the admin form's field names are not load-bearing
 * (invariant 7). Handover 13 builds one of these from its FormRequest.
 */
final readonly class KnowledgeDocumentData
{
    /**
     * @param  string  $title  shown to a student as the citation
     * @param  string  $source  how the material would be cited in full
     * @param  int|null  $organId  retrieval default inherited by every chunk (PRD §28)
     * @param  string|null  $contentType  e.g. `definition`, `function`, `clinical`
     */
    public function __construct(
        public string $title,
        public string $source,
        public KnowledgeSourceType $sourceType,
        public ?int $organId = null,
        public ?int $structureId = null,
        public string $educationLevel = 'high_school',
        public ?string $contentType = null,
    ) {}

    /**
     * The metadata block stored on the document and copied onto every chunk.
     *
     * @return array<string, scalar|null>
     */
    public function retrievalDefaults(): array
    {
        return [
            'organ_id' => $this->organId,
            'structure_id' => $this->structureId,
            'education_level' => $this->educationLevel,
            'content_type' => $this->contentType,
        ];
    }
}
