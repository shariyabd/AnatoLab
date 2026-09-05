<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AnatomicalStructure;
use App\Models\Organ;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AnatomicalStructure>
 */
final class AnatomicalStructureFactory extends Factory
{
    protected $model = AnatomicalStructure::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'organ_id' => Organ::factory(),
            'slug' => Str::slug($name),
            'ta_term' => Str::title(fake()->unique()->words(2, true)),
            'name' => Str::title($name),
            'scientific_name' => Str::title(fake()->words(2, true)),
            'description' => fake()->paragraph(),
            'function' => fake()->sentence(),
            'location' => fake()->sentence(4),
            'difficulty' => fake()->numberBetween(1, 5),
            'anchor_position' => self::randomAnchorPosition(),
            // Always null. Reserved for the per-structure-mesh upgrade; a
            // factory that invented one would let a test pass against geometry
            // that does not exist (docs/project-context.md §2.2).
            'model_object_name' => null,
            'marker_color' => fake()->hexColor(),
            'metadata' => [],
            'is_published' => false,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_published' => true,
        ]);
    }

    /**
     * A point inside the FIT_SIZE cube every model is normalised into.
     *
     * Half of FIT_SIZE is the cube's half-extent, so a coordinate outside
     * ±1.9 is outside the model and would place a marker in empty space
     * (docs/architecture.md §5.4 rule 1).
     *
     * @return list<float>
     */
    private static function randomAnchorPosition(): array
    {
        $halfExtent = ((float) config('anatomy.fit_size')) / 2.0;

        return array_map(
            static fn (): float => round(fake()->randomFloat(2, -$halfExtent, $halfExtent), 2),
            [0, 1, 2],
        );
    }
}
