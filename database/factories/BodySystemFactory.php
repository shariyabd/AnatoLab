<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BodySystem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BodySystem>
 */
final class BodySystemFactory extends Factory
{
    protected $model = BodySystem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('###'),
            'name' => Str::title($name),
            'description' => fake()->sentence(),
        ];
    }
}
