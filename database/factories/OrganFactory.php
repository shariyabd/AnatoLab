<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ModelFormat;
use App\Enums\OrganStatus;
use App\Models\BodySystem;
use App\Models\Organ;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organ>
 */
final class OrganFactory extends Factory
{
    protected $model = Organ::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word();
        $slug = Str::slug($name).'-'.fake()->unique()->numerify('###');

        return [
            'body_system_id' => BodySystem::factory(),
            'slug' => $slug,
            'name' => Str::title($name),
            'scientific_name' => Str::title(fake()->word()),
            'description' => fake()->paragraph(),
            'model_path' => 'models/'.$slug.'.glb',
            'model_format' => ModelFormat::Glb,
            'thumbnail_path' => 'models/'.$slug.'.webp',
            'accent_color' => fake()->hexColor(),
            // Draft by default so a test that means to expose an organ has to
            // say so, and a test that forgets discovers the guard rather than
            // passing by accident.
            'status' => OrganStatus::Draft,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OrganStatus::Published,
        ]);
    }
}
