<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\KnowledgeDocumentStatus;
use App\Enums\KnowledgeSourceType;
use App\Models\KnowledgeDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeDocument>
 */
final class KnowledgeDocumentFactory extends Factory
{
    protected $model = KnowledgeDocument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(4),
            'source' => $this->faker->sentence(3),
            'source_type' => KnowledgeSourceType::Reference,
            'version' => 1,
            'status' => KnowledgeDocumentStatus::Pending,
            'original_filename' => 'notes.md',
            'mime_type' => 'text/markdown',
            'size_bytes' => 1024,
            'metadata' => [],
        ];
    }

    public function indexed(): self
    {
        return $this->state(fn (): array => ['status' => KnowledgeDocumentStatus::Indexed]);
    }

    public function failed(string $reason = 'Unsupported file type.'): self
    {
        return $this->state(fn (array $attributes): array => [
            'status' => KnowledgeDocumentStatus::Failed,
            'metadata' => [...(array) ($attributes['metadata'] ?? []), 'failure_reason' => $reason],
        ]);
    }

    /**
     * The retrieval defaults this document's chunks inherit (PRD §28).
     */
    public function about(
        ?int $organId = null,
        ?int $structureId = null,
        string $educationLevel = 'high_school',
        ?string $contentType = null,
    ): self {
        return $this->state(fn (array $attributes): array => [
            'metadata' => [
                ...(array) ($attributes['metadata'] ?? []),
                'organ_id' => $organId,
                'structure_id' => $structureId,
                'education_level' => $educationLevel,
                'content_type' => $contentType,
            ],
        ]);
    }
}
