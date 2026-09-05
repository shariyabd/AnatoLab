<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MissionStatus;
use App\Enums\MissionType;
use App\Models\AnatomicalStructure;
use App\Models\Mission;
use App\Models\Organ;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Mission>
 */
final class MissionFactory extends Factory
{
    protected $model = Mission::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = Str::title(fake()->unique()->words(3, true));

        return [
            'organ_id' => Organ::factory(),
            'slug' => Str::slug($title).'-'.fake()->unique()->numerify('###'),
            'title' => $title,
            'description' => fake()->sentence(),
            'type' => MissionType::TracePathway,
            'difficulty' => fake()->numberBetween(1, 5),
            // Empty by default. A mission's steps name real structures on a
            // real organ, and a factory cannot invent one that resolves — a
            // test says which structures it means with `tracing()` below.
            'configuration' => ['steps' => []],
            // Draft by default so a test that means to expose a mission has to
            // say so, and one that forgets meets the guard rather than passing
            // by accident (matching LessonFactory and OrganFactory).
            'status' => MissionStatus::Draft,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => MissionStatus::Published,
        ]);
    }

    /**
     * An ordered pathway through the given structures, in the order given.
     *
     * Takes models rather than slugs so the factory cannot produce a mission
     * whose targets do not exist — the failure `MissionService` refuses to
     * serve, and one a test should have to ask for explicitly.
     *
     * @param  list<AnatomicalStructure>  $structures
     */
    public function tracing(array $structures): static
    {
        return $this->state(fn (array $attributes): array => [
            'organ_id' => $structures === [] ? $attributes['organ_id'] : $structures[0]->organ_id,
            'type' => MissionType::TracePathway,
            'configuration' => self::configurationFor($structures),
        ]);
    }

    /**
     * The same structures as an unordered set to find.
     *
     * @param  list<AnatomicalStructure>  $structures
     */
    public function identifying(array $structures): static
    {
        return $this->state(fn (array $attributes): array => [
            'organ_id' => $structures === [] ? $attributes['organ_id'] : $structures[0]->organ_id,
            'type' => MissionType::Identify,
            'configuration' => self::configurationFor($structures),
        ]);
    }

    /**
     * A mission with an explicit scoring table, for a test asserting arithmetic.
     */
    public function scoring(int $correct, int $afterHint, int $wrong = 0): static
    {
        return $this->state(function (array $attributes) use ($correct, $afterHint, $wrong): array {
            /** @var array<string, mixed> $configuration */
            $configuration = $attributes['configuration'];

            return [
                'configuration' => [
                    ...$configuration,
                    'scoring' => ['correct' => $correct, 'after_hint' => $afterHint, 'wrong' => $wrong],
                ],
            ];
        });
    }

    /**
     * @param  list<AnatomicalStructure>  $structures
     * @return array<string, mixed>
     */
    private static function configurationFor(array $structures): array
    {
        return [
            'steps' => array_map(
                static fn (AnatomicalStructure $structure): array => [
                    'structure_id' => $structure->slug,
                    'prompt' => 'Where does it go next?',
                    'hint' => 'Follow the flow.',
                    'explanation' => 'That is the '.$structure->name.'.',
                ],
                $structures,
            ),
            'scoring' => ['correct' => 10, 'after_hint' => 6, 'wrong' => 0],
        ];
    }
}
