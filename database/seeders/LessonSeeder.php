<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\DifficultyPreference;
use App\Enums\LessonStatus;
use App\Enums\LessonStepType;
use App\Models\Lesson;
use App\Models\Organ;
use Illuminate\Database\Seeder;

/**
 * Seven lessons across the three MVP organs (PRD §8).
 *
 * Content, not code. Four things about this data are load-bearing:
 *
 * 1. **`blood-circulation` is the demo lesson.** F14's competition journey
 *    walks it end to end on a cold database, and F08's prompt context names it
 *    (PRD §39). Its slug, and the fact that it is published, are a contract
 *    with that lane — rename either and the release gate fails
 *    (docs/handovers/14-demo-polish.md, "Demo dataset").
 *
 * 2. **Step payloads are authored in the shape the client consumes** —
 *    camelCase keys, structure *slugs* rather than ids. There is no per-type
 *    transform in LessonResource, so what is written here is what the six step
 *    components receive. Ids would have been the obvious choice and are wrong:
 *    they differ between a seeded database and a restored one, and a slug is
 *    stable and readable in a diff.
 *
 * 3. **A `knowledge_check` step carries a prompt and a reference, never an
 *    answer.** F07 owns the question, its options and its grading; this lane
 *    owns only where it sits in the sequence
 *    (docs/handovers/06-lessons.md, "Out of scope"). The `reference` is the
 *    stable key F07 binds a question to.
 *
 * 4. **Every structure slug below exists in AnatomySeeder.** They are how the
 *    exploration and activity steps address the model, and a typo degrades to
 *    a step that highlights nothing rather than to an error — which is exactly
 *    why LessonSeederTest asserts they resolve.
 *
 * Idempotent by slug: `db:seed` twice produces the same seven rows.
 */
final class LessonSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->lessons() as $definition) {
            /** @var Organ $organ */
            $organ = Organ::query()->where('slug', $definition['organ'])->sole();

            Lesson::query()->updateOrCreate(
                ['slug' => $definition['slug']],
                [
                    'organ_id' => $organ->getKey(),
                    'title' => $definition['title'],
                    'description' => $definition['description'],
                    'objective' => $definition['objective'],
                    'difficulty' => $definition['difficulty'],
                    'estimated_minutes' => $definition['estimated_minutes'],
                    'content' => ['steps' => $definition['steps']],
                    'status' => LessonStatus::Published,
                ],
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lessons(): array
    {
        return [
            $this->bloodCirculation(),
            $this->chambersAndValves(),
            $this->heartWallAndPressure(),
            $this->airwayToTheLungs(),
            $this->lobesOfTheLungs(),
            $this->lobesOfTheCerebrum(),
            $this->brainstemAndCerebellum(),
        ];
    }

    /**
     * The demo lesson (PRD §8 "Example lesson", PRD §44 step 6).
     *
     * @return array<string, mixed>
     */
    private function bloodCirculation(): array
    {
        return [
            'organ' => 'heart',
            'slug' => 'blood-circulation',
            'title' => 'Blood Circulation Through the Heart',
            'description' => 'Follow one drop of blood from the body, through both sides of the heart, and back out to the lungs and the body.',
            'objective' => 'Understand how blood moves through the heart.',
            'difficulty' => DifficultyPreference::Beginner,
            'estimated_minutes' => 15,
            'steps' => [
                [
                    'type' => LessonStepType::Objective->value,
                    'title' => 'What you will learn',
                    'payload' => [
                        'body' => 'Blood does not pass through the heart once. It passes through twice — the right side sends it to the lungs, the left side sends it to the rest of the body. Once that clicks, the layout of the four chambers stops looking arbitrary.',
                        'outcomes' => [
                            'Name the four chambers and say which side of the body each one serves.',
                            'Trace a single drop of blood from the vena cava back to the aorta.',
                            'Explain why the two circuits are separated.',
                        ],
                    ],
                ],
                [
                    'type' => LessonStepType::Exploration->value,
                    'title' => 'Find the four chambers',
                    'payload' => [
                        'instruction' => 'Rotate the heart and find each chamber in turn. The right side of the heart is on your left as you look at it from the front — the labels are written from the patient\'s point of view, not yours.',
                        'structures' => ['right-atrium', 'right-ventricle', 'left-atrium', 'left-ventricle'],
                    ],
                ],
                [
                    'type' => LessonStepType::Explanation->value,
                    'title' => 'Two circuits, one pump',
                    'payload' => [
                        'blocks' => [
                            [
                                'heading' => 'The right side: to the lungs',
                                'body' => 'Blood returning from the body is low in oxygen. It arrives in the right atrium through the superior and inferior vena cava, drops into the right ventricle, and is pushed out through the pulmonary trunk to the lungs. This is the pulmonary circuit, and it is short — the lungs are centimetres away.',
                            ],
                            [
                                'heading' => 'The left side: to the body',
                                'body' => 'Blood coming back from the lungs is rich in oxygen. It arrives in the left atrium, drops into the left ventricle, and is pushed out through the aorta to everything else — brain, gut, toes. This is the systemic circuit, and it is long.',
                            ],
                            [
                                'heading' => 'Why they are separated',
                                'body' => 'If the two circuits mixed, blood going out to the body would be part-oxygenated at best. The wall between the left and right sides — the septum — is what keeps a full load of oxygen going out of the aorta on every beat.',
                            ],
                        ],
                    ],
                ],
                [
                    'type' => LessonStepType::Activity->value,
                    'title' => 'Trace one drop of blood',
                    'payload' => [
                        'instruction' => 'Work through the path in order. Select each structure on the model as you reach it, and say out loud where the blood has just come from.',
                        'structures' => [
                            'superior-vena-cava',
                            'right-atrium',
                            'right-ventricle',
                            'pulmonary-trunk',
                            'left-atrium',
                            'left-ventricle',
                            'aorta',
                        ],
                    ],
                ],
                [
                    'type' => LessonStepType::KnowledgeCheck->value,
                    'title' => 'Check yourself',
                    'payload' => [
                        'prompt' => 'Which chamber pushes blood into the aorta?',
                        'reference' => 'blood-circulation.aorta-source',
                    ],
                ],
                [
                    'type' => LessonStepType::Reflection->value,
                    'title' => 'Think it through',
                    'payload' => [
                        'prompt' => 'A baby is born with a hole in the septum between the two ventricles. Using the path you just traced, what would happen to the blood leaving the aorta?',
                        'placeholder' => 'Two or three sentences is plenty.',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function chambersAndValves(): array
    {
        return [
            'organ' => 'heart',
            'slug' => 'chambers-and-valves',
            'title' => 'Chambers, Valves and the One-Way Rule',
            'description' => 'Why the heart has valves at all, and what each one stops from happening.',
            'objective' => 'Explain how the heart valves keep blood moving in one direction.',
            'difficulty' => DifficultyPreference::Intermediate,
            'estimated_minutes' => 12,
            'steps' => [
                [
                    'type' => LessonStepType::Objective->value,
                    'title' => 'What you will learn',
                    'payload' => [
                        'body' => 'A pump that pushes in both directions moves nothing. The valves are what turn a squeezing muscle into a pump.',
                        'outcomes' => [
                            'Say where each valve sits relative to the chambers.',
                            'Explain what a valve prevents rather than what it allows.',
                        ],
                    ],
                ],
                [
                    'type' => LessonStepType::Exploration->value,
                    'title' => 'Find the mitral valve',
                    'payload' => [
                        'instruction' => 'The mitral valve sits between the left atrium and the left ventricle. Select it, then select the two chambers either side of it.',
                        'structures' => ['mitral-valve', 'left-atrium', 'left-ventricle'],
                    ],
                ],
                [
                    'type' => LessonStepType::Explanation->value,
                    'title' => 'What a valve is for',
                    'payload' => [
                        'blocks' => [
                            [
                                'heading' => 'Pressure, not intention',
                                'body' => 'A heart valve has no muscle of its own. It opens and closes because of the pressure difference either side of it: when the ventricle squeezes, pressure rises, and the mitral valve is pushed shut from below.',
                            ],
                            [
                                'heading' => 'Why backflow matters',
                                'body' => 'If the mitral valve leaks, some of the blood the left ventricle is trying to push into the aorta goes backwards into the left atrium instead. The heart does the same work and delivers less of it — which is why a leaking valve shows up as breathlessness long before it shows up as chest pain.',
                            ],
                        ],
                    ],
                ],
                [
                    'type' => LessonStepType::KnowledgeCheck->value,
                    'title' => 'Check yourself',
                    'payload' => [
                        'prompt' => 'What closes the mitral valve?',
                        'reference' => 'chambers-and-valves.mitral-closure',
                    ],
                ],
                [
                    'type' => LessonStepType::Reflection->value,
                    'title' => 'Think it through',
                    'payload' => [
                        'prompt' => 'The valves make a sound when they close — the "lub-dub" of a heartbeat. Which closure do you think makes which sound, and why?',
                        'placeholder' => 'There is a reasonable answer and a wrong one. Argue for yours.',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function heartWallAndPressure(): array
    {
        return [
            'organ' => 'heart',
            'slug' => 'heart-wall-and-pressure',
            'title' => 'Why the Left Ventricle Is Thicker',
            'description' => 'The single most-asked question about the heart, answered from the geometry rather than from a textbook line.',
            'objective' => 'Relate the thickness of a chamber wall to the pressure it has to generate.',
            'difficulty' => DifficultyPreference::Advanced,
            'estimated_minutes' => 18,
            'steps' => [
                [
                    'type' => LessonStepType::Objective->value,
                    'title' => 'What you will learn',
                    'payload' => [
                        'body' => 'Both ventricles push the same volume of blood on every beat. Only one of them has a wall three times as thick. The reason is distance, not volume.',
                        'outcomes' => [
                            'Explain why equal volumes need unequal pressures.',
                            'Predict what happens to a ventricle wall when the pressure it works against rises.',
                        ],
                    ],
                ],
                [
                    'type' => LessonStepType::Exploration->value,
                    'title' => 'Compare the two ventricles',
                    'payload' => [
                        'instruction' => 'Select each ventricle in turn and compare the wall thickness. Then find the apex — the point at the bottom of the heart, which is formed almost entirely by the left ventricle.',
                        'structures' => ['left-ventricle', 'right-ventricle', 'apex'],
                    ],
                ],
                [
                    'type' => LessonStepType::Explanation->value,
                    'title' => 'Distance sets the pressure',
                    'payload' => [
                        'blocks' => [
                            [
                                'heading' => 'A short circuit and a long one',
                                'body' => 'The right ventricle pushes blood to the lungs, which sit either side of it. The left ventricle pushes the same volume to the whole body, against the resistance of every artery in it. Same volume, far more resistance, so far more pressure.',
                            ],
                            [
                                'heading' => 'Muscle answers pressure',
                                'body' => 'Cardiac muscle thickens in response to the pressure it works against, the way any muscle responds to load. That is why untreated high blood pressure shows up on a scan as a thickened left ventricle years before anything else goes wrong.',
                            ],
                        ],
                    ],
                ],
                [
                    'type' => LessonStepType::Reflection->value,
                    'title' => 'Think it through',
                    'payload' => [
                        'prompt' => 'A patient has narrowed arteries in the lungs, so the right ventricle must push harder than usual. What would you expect its wall to look like after several years?',
                        'placeholder' => 'Say what you expect, and say what would tell you if you were wrong.',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function airwayToTheLungs(): array
    {
        return [
            'organ' => 'lungs',
            'slug' => 'airway-to-the-lungs',
            'title' => 'The Airway: Trachea to Bronchi',
            'description' => 'How air gets from the throat to each lung, and why the split is not symmetrical.',
            'objective' => 'Trace the path air takes from the trachea into each lung.',
            'difficulty' => DifficultyPreference::Beginner,
            'estimated_minutes' => 10,
            'steps' => [
                [
                    'type' => LessonStepType::Objective->value,
                    'title' => 'What you will learn',
                    'payload' => [
                        'body' => 'The airway branches like an upside-down tree. The first branch is the one that matters most clinically, and it is lopsided.',
                        'outcomes' => [
                            'Name the structures air passes through on its way into a lung.',
                            'Explain why an inhaled object usually ends up in the right lung.',
                        ],
                    ],
                ],
                [
                    'type' => LessonStepType::Exploration->value,
                    'title' => 'Follow the branch',
                    'payload' => [
                        'instruction' => 'Start at the trachea and work downwards. The carina is the ridge at the point where the trachea splits.',
                        'structures' => ['trachea', 'carina', 'right-main-bronchus', 'left-main-bronchus'],
                    ],
                ],
                [
                    'type' => LessonStepType::Explanation->value,
                    'title' => 'A lopsided split',
                    'payload' => [
                        'blocks' => [
                            [
                                'heading' => 'The right bronchus is wider and steeper',
                                'body' => 'The heart sits slightly to the left, so the left main bronchus has to travel further and at a shallower angle to get around it. The right one is wider, shorter and more vertical.',
                            ],
                            [
                                'heading' => 'Why that is worth knowing',
                                'body' => 'Anything inhaled by accident — a peanut, a tooth — tends to follow gravity down the straighter pipe. Inhaled foreign bodies end up in the right lung far more often than the left, and knowing that changes where a clinician looks first.',
                            ],
                        ],
                    ],
                ],
                [
                    'type' => LessonStepType::KnowledgeCheck->value,
                    'title' => 'Check yourself',
                    'payload' => [
                        'prompt' => 'Which main bronchus is an inhaled object more likely to enter?',
                        'reference' => 'airway-to-the-lungs.inhaled-object',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lobesOfTheLungs(): array
    {
        return [
            'organ' => 'lungs',
            'slug' => 'lobes-of-the-lungs',
            'title' => 'Lung Lobes and Why They Differ',
            'description' => 'Three lobes on the right, two on the left, and the organ that explains the difference.',
            'objective' => 'Identify each lung lobe and explain why the two lungs are not mirror images.',
            'difficulty' => DifficultyPreference::Intermediate,
            'estimated_minutes' => 12,
            'steps' => [
                [
                    'type' => LessonStepType::Objective->value,
                    'title' => 'What you will learn',
                    'payload' => [
                        'body' => 'The lungs look symmetrical from a distance and are not. The asymmetry is not a quirk — it is the heart taking up room.',
                        'outcomes' => [
                            'Name all five lobes.',
                            'Explain what occupies the space the left lung does not.',
                        ],
                    ],
                ],
                [
                    'type' => LessonStepType::Exploration->value,
                    'title' => 'Find all five lobes',
                    'payload' => [
                        'instruction' => 'Work down the right lung first, then the left. Notice that the left has no middle lobe.',
                        'structures' => [
                            'right-superior-lobe',
                            'right-middle-lobe',
                            'right-inferior-lobe',
                            'left-superior-lobe',
                            'left-inferior-lobe',
                        ],
                    ],
                ],
                [
                    'type' => LessonStepType::Explanation->value,
                    'title' => 'The heart takes the space',
                    'payload' => [
                        'blocks' => [
                            [
                                'heading' => 'Three on the right, two on the left',
                                'body' => 'The right lung is divided into superior, middle and inferior lobes. The left has only superior and inferior — the space a middle lobe would occupy is taken by the heart, which sits left of the midline.',
                            ],
                            [
                                'heading' => 'Lobes are functional units',
                                'body' => 'Each lobe has its own bronchus and its own blood supply, which is why a surgeon can remove one lobe and leave the rest of the lung working.',
                            ],
                        ],
                    ],
                ],
                [
                    'type' => LessonStepType::Activity->value,
                    'title' => 'Name them in order',
                    'payload' => [
                        'instruction' => 'Go top to bottom on each side, selecting each lobe as you name it.',
                        'structures' => [
                            'right-superior-lobe',
                            'right-middle-lobe',
                            'right-inferior-lobe',
                            'left-superior-lobe',
                            'left-inferior-lobe',
                        ],
                    ],
                ],
                [
                    'type' => LessonStepType::Reflection->value,
                    'title' => 'Think it through',
                    'payload' => [
                        'prompt' => 'If the heart sat exactly in the midline instead, what would you expect the lungs to look like?',
                        'placeholder' => 'One or two sentences.',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lobesOfTheCerebrum(): array
    {
        return [
            'organ' => 'brain',
            'slug' => 'lobes-of-the-cerebrum',
            'title' => 'The Four Lobes of the Cerebrum',
            'description' => 'Where each lobe sits and what stops working when it is damaged.',
            'objective' => 'Locate the four cerebral lobes and name what each one is mainly responsible for.',
            'difficulty' => DifficultyPreference::Beginner,
            'estimated_minutes' => 14,
            'steps' => [
                [
                    'type' => LessonStepType::Objective->value,
                    'title' => 'What you will learn',
                    'payload' => [
                        'body' => 'The cerebrum is divided into four lobes per hemisphere. Learning them by location is faster than learning them by function, because the functions follow from where they are.',
                        'outcomes' => [
                            'Point to each lobe on the model.',
                            'Say what each lobe is mainly responsible for.',
                        ],
                    ],
                ],
                [
                    'type' => LessonStepType::Exploration->value,
                    'title' => 'Find each lobe',
                    'payload' => [
                        'instruction' => 'Start at the front and work backwards. The longitudinal fissure is the deep groove separating the two hemispheres.',
                        'structures' => [
                            'frontal-lobe',
                            'parietal-lobe',
                            'temporal-lobe',
                            'occipital-lobe',
                            'longitudinal-fissure',
                        ],
                    ],
                ],
                [
                    'type' => LessonStepType::Explanation->value,
                    'title' => 'Position predicts function',
                    'payload' => [
                        'blocks' => [
                            [
                                'heading' => 'Front to back',
                                'body' => 'The frontal lobe handles planning, decision-making and voluntary movement. The parietal lobe behind it handles touch and spatial awareness. The occipital lobe at the very back handles vision — which is why a blow to the back of the head can make someone see flashes.',
                            ],
                            [
                                'heading' => 'The temporal lobe sits low',
                                'body' => 'Tucked under the others, beside the ear, the temporal lobe handles hearing and a large share of memory. Its position is the clue: it is closest to the ear it serves.',
                            ],
                        ],
                    ],
                ],
                [
                    'type' => LessonStepType::KnowledgeCheck->value,
                    'title' => 'Check yourself',
                    'payload' => [
                        'prompt' => 'Which lobe processes what you see?',
                        'reference' => 'lobes-of-the-cerebrum.vision',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function brainstemAndCerebellum(): array
    {
        return [
            'organ' => 'brain',
            'slug' => 'brainstem-and-cerebellum',
            'title' => 'The Brainstem and the Cerebellum',
            'description' => 'The parts of the brain that keep you alive and keep you upright.',
            'objective' => 'Distinguish the roles of the brainstem and the cerebellum.',
            'difficulty' => DifficultyPreference::Intermediate,
            'estimated_minutes' => 12,
            'steps' => [
                [
                    'type' => LessonStepType::Objective->value,
                    'title' => 'What you will learn',
                    'payload' => [
                        'body' => 'Below the cerebrum sit two structures doing very different jobs: one runs the body\'s automatic functions, the other makes movement smooth.',
                        'outcomes' => [
                            'Locate the pons, the medulla oblongata and the cerebellum.',
                            'Say which one you could not survive without, and why.',
                        ],
                    ],
                ],
                [
                    'type' => LessonStepType::Exploration->value,
                    'title' => 'Find them on the model',
                    'payload' => [
                        'instruction' => 'Rotate the brain to look at it from behind and below. The cerebellum is the ridged structure under the back of the cerebrum; the brainstem runs down in front of it.',
                        'structures' => ['cerebellum', 'pons', 'medulla-oblongata'],
                    ],
                ],
                [
                    'type' => LessonStepType::Explanation->value,
                    'title' => 'Automatic, and smooth',
                    'payload' => [
                        'blocks' => [
                            [
                                'heading' => 'The medulla runs the essentials',
                                'body' => 'Breathing rate, heart rate and blood pressure are regulated in the medulla oblongata, without conscious involvement. Damage here is immediately life-threatening in a way damage to a cerebral lobe usually is not.',
                            ],
                            [
                                'heading' => 'The cerebellum makes movement smooth',
                                'body' => 'The cerebellum does not start a movement — the frontal lobe does that. It corrects it while it happens. Someone with cerebellar damage can still reach for a cup; the reach is just clumsy and overshoots.',
                            ],
                        ],
                    ],
                ],
                [
                    'type' => LessonStepType::Reflection->value,
                    'title' => 'Think it through',
                    'payload' => [
                        'prompt' => 'Alcohol affects the cerebellum strongly at moderate doses. Which of the effects of being drunk does that explain?',
                        'placeholder' => 'Name two, and say which part of the brain each one points to.',
                    ],
                ],
            ],
        ];
    }
}
