<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeChunk>
 */
final class KnowledgeChunkFactory extends Factory
{
    protected $model = KnowledgeChunk::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => KnowledgeDocument::factory(),
            'chunk_index' => 0,
            'content' => $this->faker->paragraph(),
            // Null rather than random: a random vector would give retrieval
            // tests a ranking that changes between runs.
            'embedding' => null,
            'embedding_reference' => null,
            'organ_id' => null,
            'structure_id' => null,
            'education_level' => 'high_school',
            'content_type' => null,
            'metadata' => [],
        ];
    }

    /**
     * @param  list<float>  $vector
     */
    public function embedded(array $vector): self
    {
        return $this->state(fn (): array => ['embedding' => $vector]);
    }

    public function about(?int $organId = null, ?int $structureId = null): self
    {
        return $this->state(fn (): array => [
            'organ_id' => $organId,
            'structure_id' => $structureId,
        ]);
    }
}
