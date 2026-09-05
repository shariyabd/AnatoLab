<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StructureRelationType;
use App\Models\AnatomicalStructure;
use App\Models\StructureRelation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StructureRelation>
 */
final class StructureRelationFactory extends Factory
{
    protected $model = StructureRelation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'structure_id' => AnatomicalStructure::factory(),
            'related_structure_id' => AnatomicalStructure::factory(),
            'relation_type' => fake()->randomElement(StructureRelationType::cases()),
        ];
    }
}
