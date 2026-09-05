<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MissionStatus;
use App\Enums\MissionType;
use App\Models\AnatomicalStructure;
use App\Models\Mission;
use App\Models\Organ;
use Illuminate\Database\Seeder;

/**
 * The demo missions (docs/handovers/09-missions.md, PRD §13).
 *
 * Content, not code. Four things about this data are load-bearing:
 *
 * 1. **Steps name their target by structure slug**, resolved against
 *    AnatomySeeder's rows. Slugs are unique per organ, not globally — `apex`
 *    means one thing in the heart and another in the lungs — so every lookup is
 *    scoped to the organ (App\Services\Anatomy\AnatomyService).
 *
 * 2. **A prompt never names its own target.** These are the strings that reach
 *    the browser; a prompt reading "click the left atrium" would ship the
 *    answer key in the one field that is supposed to be safe. Each prompt
 *    describes the *function* the student is looking for, which is the thing
 *    the mission is actually teaching.
 *
 * 3. **A mission whose targets do not all resolve is skipped, not seeded.**
 *    `MissionService` refuses to serve one, so seeding it would produce a card
 *    that 404s — the failure AssessmentSeeder avoids by skipping a spatial
 *    question with no resolvable answer.
 *
 * 4. **Idempotent by slug.** `db:seed` twice produces the same database, not
 *    doubled rows (docs/engineering.md §6).
 *
 * "Trace the Blood" is named in PRD §13 and Handover 14's demo journey walks
 * it, so its slug is effectively public API — do not rename it. Its pathway is
 * the three chambers and vessels docs/architecture.md §9 authors verbatim
 * (left atrium → left ventricle → aorta); the journey's fourth leg, out to the
 * body, has no structure on the heart model to click and is carried in the
 * final step's prose instead.
 *
 * "Name the Airways" is the second type Handover 09 asks for — an unordered
 * `identify` run on a different organ, which is what proves the configuration
 * generalises past a single pathway.
 */
final class MissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->missions() as $definition) {
            $this->seedMission($definition);
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function seedMission(array $definition): void
    {
        $organ = Organ::query()->where('slug', $definition['organ'])->first();

        // The anatomy seeder owns the organs. If one is missing, the demo
        // dataset is already broken elsewhere and inventing an organ here would
        // hide it (matching AssessmentSeeder).
        if (! $organ instanceof Organ) {
            return;
        }

        /** @var list<array<string, string>> $steps */
        $steps = $definition['steps'];

        $slugs = array_map(static fn (array $step): string => $step['structure_id'], $steps);

        $resolved = AnatomicalStructure::query()
            ->where('organ_id', $organ->getKey())
            ->where('is_published', true)
            ->whereIn('slug', $slugs)
            ->pluck('slug');

        // Every step, or none of it. A mission with one unreachable target is
        // one no student can finish.
        if ($resolved->count() !== count(array_unique($slugs))) {
            return;
        }

        Mission::query()->updateOrCreate(
            ['slug' => $definition['slug']],
            [
                'organ_id' => $organ->getKey(),
                'title' => $definition['title'],
                'description' => $definition['description'],
                'type' => $definition['type'],
                'difficulty' => $definition['difficulty'],
                'configuration' => [
                    'steps' => $steps,
                    'scoring' => $definition['scoring'],
                ],
                'status' => MissionStatus::Published,
            ],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function missions(): array
    {
        return [
            [
                'slug' => 'trace-the-blood',
                'organ' => 'heart',
                'title' => 'Trace the Blood',
                'description' => 'Follow one drop of oxygen-rich blood from the moment it '
                    .'returns from the lungs to the moment it leaves for the body.',
                'type' => MissionType::TracePathway,
                'difficulty' => 2,
                'scoring' => ['correct' => 10, 'after_hint' => 6, 'wrong' => 0],
                'steps' => [
                    [
                        'structure_id' => 'left-atrium',
                        'prompt' => 'Blood has just been reoxygenated in the lungs and is '
                            .'flowing back through the four pulmonary veins. Click the chamber '
                            .'it arrives in.',
                        'hint' => 'It is a receiving chamber, not a pumping one — and it sits '
                            .'high and towards the back of the heart.',
                        'explanation' => 'The left atrium. Four pulmonary veins open into it, '
                            .'which is the only place in the body where veins carry oxygenated '
                            .'blood.',
                    ],
                    [
                        'structure_id' => 'left-ventricle',
                        'prompt' => 'The mitral valve opens. Click the chamber the blood drops '
                            .'into — the one built thick enough to push it around the whole body.',
                        'hint' => 'Its wall is roughly three times thicker than its opposite '
                            .'number on the right, and it runs down towards the apex.',
                        'explanation' => 'The left ventricle. It generates the systemic pressure '
                            .'you measure at the arm, which is why its wall is the thickest part '
                            .'of the heart.',
                    ],
                    [
                        'structure_id' => 'aorta',
                        'prompt' => 'The ventricle contracts and the aortic valve snaps open. '
                            .'Click the vessel that carries the blood out of the heart.',
                        'hint' => 'It is the largest artery in the body, and it arches up and '
                            .'over the top of the heart before heading down.',
                        'explanation' => 'The aorta — and from here the drop is away into the '
                            .'systemic arteries and out to the body, which is where this trace '
                            .'ends and the circulation carries on without you.',
                    ],
                ],
            ],
            [
                'slug' => 'name-the-airways',
                'organ' => 'lungs',
                'title' => 'Name the Airways',
                'description' => 'Find the four structures air passes through on its way from '
                    .'the throat into each lung. Any order — they are a set, not a sequence.',
                'type' => MissionType::Identify,
                'difficulty' => 2,
                'scoring' => ['correct' => 10, 'after_hint' => 6, 'wrong' => 0],
                'steps' => [
                    [
                        'structure_id' => 'trachea',
                        'prompt' => 'Find the single tube that carries every breath down from '
                            .'the larynx before the airway divides at all.',
                        'hint' => 'C-shaped cartilage rings hold it open. It is the one part of '
                            .'this set there is only one of.',
                        'explanation' => 'The trachea — the last undivided airway.',
                    ],
                    [
                        'structure_id' => 'carina',
                        'prompt' => 'Find the ridge at the point where that tube splits in two.',
                        'hint' => 'It is a landmark rather than a passage, and it is unusually '
                            .'sensitive — touching it triggers a hard cough.',
                        'explanation' => 'The carina. It sits at the bifurcation and is the '
                            .'reference point every bronchoscopy is oriented from.',
                    ],
                    [
                        'structure_id' => 'right-main-bronchus',
                        'prompt' => 'Find the branch on the side where an inhaled object is far '
                            .'more likely to end up.',
                        'hint' => 'It is wider and more vertical than its opposite number, which '
                            .'is exactly why things fall down it.',
                        'explanation' => 'The right main bronchus — shorter, wider and steeper, '
                            .'so aspirated objects overwhelmingly go right.',
                    ],
                    [
                        'structure_id' => 'left-main-bronchus',
                        'prompt' => 'Find the remaining branch — the longer, shallower one that '
                            .'has to travel around the heart.',
                        'hint' => 'The heart sits slightly to this side, so the airway on it is '
                            .'pushed out to a flatter angle.',
                        'explanation' => 'The left main bronchus. It is narrower and more '
                            .'horizontal because the heart is in its way.',
                    ],
                ],
            ],
        ];
    }
}
