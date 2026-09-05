<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DifficultyPreference;
use App\Enums\LessonStatus;
use App\Enums\LessonStepType;
use App\Models\Lesson;
use App\Models\Organ;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Lesson>
 */
final class LessonFactory extends Factory
{
    protected $model = Lesson::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = Str::title(fake()->unique()->words(3, true));
        $slug = Str::slug($title).'-'.fake()->unique()->numerify('###');

        return [
            'organ_id' => Organ::factory(),
            'slug' => $slug,
            'title' => $title,
            'description' => fake()->sentence(),
            'objective' => fake()->sentence(),
            'difficulty' => DifficultyPreference::Beginner,
            'estimated_minutes' => fake()->numberBetween(5, 25),
            'content' => ['steps' => self::defaultSteps()],
            // Draft by default so a test that means to expose a lesson has to
            // say so, and one that forgets meets the guard rather than passing
            // by accident (matching OrganFactory).
            'status' => LessonStatus::Draft,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => LessonStatus::Published,
        ]);
    }

    /**
     * A lesson with a known number of steps, so a test can assert an exact
     * progress percentage instead of a range.
     */
    public function withSteps(int $count): static
    {
        return $this->state(fn (array $attributes): array => [
            'content' => [
                'steps' => array_map(
                    static fn (int $index): array => [
                        'type' => LessonStepType::Explanation->value,
                        'title' => 'Step '.($index + 1),
                        'payload' => ['blocks' => [['heading' => null, 'body' => 'Body '.($index + 1)]]],
                    ],
                    range(0, max($count, 1) - 1),
                ),
            ],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function defaultSteps(): array
    {
        return [
            [
                'type' => LessonStepType::Objective->value,
                'title' => 'What you will learn',
                'payload' => ['body' => fake()->sentence(), 'outcomes' => [fake()->sentence()]],
            ],
            [
                'type' => LessonStepType::Explanation->value,
                'title' => 'How it works',
                'payload' => ['blocks' => [['heading' => null, 'body' => fake()->paragraph()]]],
            ],
            [
                'type' => LessonStepType::Reflection->value,
                'title' => 'Think it through',
                'payload' => ['prompt' => fake()->sentence(), 'placeholder' => 'Write a sentence or two.'],
            ],
        ];
    }
}
