<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ModelFormat;
use App\Enums\OrganStatus;
use App\Enums\StructureRelationType;
use App\Models\AnatomicalStructure;
use App\Models\BodySystem;
use App\Models\Organ;
use App\Models\StructureRelation;
use Illuminate\Database\Seeder;

/**
 * The nine organs and everything labelled on them.
 *
 * Content, not code (docs/handovers/03-anatomy-domain-api.md). Three things
 * about this data are load-bearing and should be read before editing it:
 *
 * 1. `anchor_position` is authored in the FIT_SIZE = 3.8 pivot space every
 *    model is normalised into — half-extent ±1.9, +X patient-left, +Y superior,
 *    +Z anterior. Changing config('anatomy.fit_size') invalidates every value
 *    here and there is no migration that can repair them
 *    (docs/architecture.md §5.4 rule 1).
 *
 * 2. `model_path` names a real file now. Every value below has a matching row
 *    in public/models/manifest.json, produced by scripts/encode-model.mjs and
 *    re-checked by `npm run models:verify`. The manifest still reads
 *    `"status": "pending-licence"`: the files exist on a developer machine and
 *    are excluded by .gitignore, but the upstream GLBs carry no licence, so
 *    nothing here may be redistributed or deployed until docs/licence-log.md §4
 *    records a decision. Adding an organ is a manifest row plus a method here —
 *    no schema change, no API change (docs/adding-an-organ.md).
 *
 * 3. `model_object_name` is null on every row, deliberately. The audited assets
 *    are a single mesh with one node named `tripo_node_<uuid>`; there is no
 *    per-structure geometry to name (docs/project-context.md §2.2).
 *
 * The English prose is written here rather than migrated from the upstream
 * repo's `app/i18n/organs/en.ts`, which is AI-generated and unverified
 * (docs/project-context.md §5.4).
 *
 * Anchor coordinates have two provenances, and the difference matters when one
 * of them looks wrong on screen. Heart, lungs and brain were authored against
 * a description rather than a model and are plausible placements awaiting a
 * pass through F04's authoring mode. The six organs below them carry the
 * coordinates the upstream repo authored against these same meshes, so they
 * are measured rather than guessed — with two interpolated exceptions marked
 * in place (`liver.gallbladder`, `kidneys.renal-pelvis`).
 *
 * Idempotent by slug throughout: `db:seed` twice produces the same database.
 */
final class AnatomySeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->bodySystems() as $system) {
            BodySystem::query()->updateOrCreate(['slug' => $system['slug']], $system);
        }

        foreach ($this->organs() as $organ) {
            $this->seedOrgan($organ);
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function seedOrgan(array $definition): void
    {
        /** @var BodySystem $system */
        $system = BodySystem::query()->where('slug', $definition['body_system'])->sole();

        /** @var array<int, array<string, mixed>> $structureDefinitions */
        $structureDefinitions = $definition['structures'];

        /** @var array<int, array{0: string, 1: string, 2: StructureRelationType}> $relationDefinitions */
        $relationDefinitions = $definition['relations'];

        $organ = Organ::query()->updateOrCreate(
            ['slug' => $definition['slug']],
            [
                'body_system_id' => $system->getKey(),
                'name' => $definition['name'],
                'scientific_name' => $definition['scientific_name'],
                'description' => $definition['description'],
                'model_path' => $definition['model_path'],
                'model_format' => ModelFormat::Glb,
                'thumbnail_path' => $definition['thumbnail_path'],
                'accent_color' => $definition['accent_color'],
                'status' => OrganStatus::Published,
            ],
        );

        /** @var array<string, AnatomicalStructure> $structures */
        $structures = [];

        foreach ($structureDefinitions as $structure) {
            $structures[$structure['slug']] = AnatomicalStructure::query()->updateOrCreate(
                ['organ_id' => $organ->getKey(), 'slug' => $structure['slug']],
                [
                    'ta_term' => $structure['ta_term'],
                    'name' => $structure['name'],
                    'scientific_name' => $structure['ta_term'],
                    'description' => $structure['description'],
                    'function' => $structure['function'],
                    'location' => $structure['location'],
                    'difficulty' => $structure['difficulty'],
                    'anchor_position' => $structure['anchor_position'],
                    'model_object_name' => null,
                    'marker_color' => $structure['marker_color'],
                    'metadata' => [],
                    'is_published' => true,
                ],
            );
        }

        foreach ($relationDefinitions as [$from, $to, $type]) {
            StructureRelation::query()->updateOrCreate(
                [
                    'structure_id' => $structures[$from]->getKey(),
                    'related_structure_id' => $structures[$to]->getKey(),
                    'relation_type' => $type,
                ],
                [],
            );
        }
    }

    /**
     * @return list<array{slug: string, name: string, description: string}>
     */
    private function bodySystems(): array
    {
        return [
            [
                'slug' => 'cardiovascular',
                'name' => 'Cardiovascular System',
                'description' => 'The heart and the blood vessels, which together move blood, '
                    .'oxygen, nutrients, hormones and heat around the body.',
            ],
            [
                'slug' => 'respiratory',
                'name' => 'Respiratory System',
                'description' => 'The airways and lungs, where oxygen enters the blood and '
                    .'carbon dioxide leaves it.',
            ],
            [
                'slug' => 'nervous',
                'name' => 'Nervous System',
                'description' => 'The brain, spinal cord and nerves, which sense the world, '
                    .'coordinate the body and produce thought and memory.',
            ],
            [
                'slug' => 'digestive',
                'name' => 'Digestive System',
                'description' => 'The gut and the glands that feed into it, which break food '
                    .'down into molecules small enough to cross into the blood.',
            ],
            [
                'slug' => 'urinary',
                'name' => 'Urinary System',
                'description' => 'The kidneys and the tract that drains them, which filter the '
                    .'blood and adjust the body\'s water, salt and acid balance.',
            ],
            [
                'slug' => 'sensory',
                'name' => 'Sensory System',
                'description' => 'The sense organs and the nerves that carry their signals, '
                    .'which turn light, sound, pressure and chemistry into nerve impulses.',
            ],
            [
                'slug' => 'integumentary',
                'name' => 'Integumentary System',
                'description' => 'The skin and its appendages, the body\'s outer boundary and '
                    .'its largest organ by surface area.',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function organs(): array
    {
        return [
            $this->heart(),
            $this->lungs(),
            $this->brain(),
            $this->liver(),
            $this->kidneys(),
            $this->pancreas(),
            $this->intestine(),
            $this->eyeball(),
            $this->skin(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function heart(): array
    {
        $accent = '#E5484D';

        return [
            'slug' => 'heart',
            'name' => 'Heart',
            'scientific_name' => 'Cor',
            'body_system' => 'cardiovascular',
            'accent_color' => $accent,
            'model_path' => 'models/heart.glb',
            'thumbnail_path' => 'models/heart.webp',
            'description' => 'A muscular pump about the size of a closed fist, sitting between '
                .'the lungs and tilted so that its tip points down and to the left. Four '
                .'chambers and four valves keep blood moving in one direction: used blood to '
                .'the lungs, freshly oxygenated blood to the rest of the body.',
            'structures' => [
                [
                    'slug' => 'right-atrium',
                    'ta_term' => 'Atrium dextrum',
                    'name' => 'Right Atrium',
                    'description' => 'The upper right chamber. It is the heart\'s collecting '
                        .'room for blood returning from the body after the oxygen has been used.',
                    'function' => 'Receives deoxygenated blood from the venae cavae and the '
                        .'coronary sinus, then empties it into the right ventricle.',
                    'location' => 'Upper right side of the heart, behind the sternum.',
                    'difficulty' => 1,
                    'anchor_position' => [-0.85, 0.55, 0.35],
                    'marker_color' => '#6E8BB5',
                ],
                [
                    'slug' => 'right-ventricle',
                    'ta_term' => 'Ventriculus dexter',
                    'name' => 'Right Ventricle',
                    'description' => 'The lower right chamber, with a thinner wall than its '
                        .'partner on the left because it only has to push blood as far as the lungs.',
                    'function' => 'Pumps deoxygenated blood through the pulmonary valve into '
                        .'the pulmonary trunk and on to the lungs.',
                    'location' => 'Lower right and front of the heart, forming most of its '
                        .'anterior surface.',
                    'difficulty' => 1,
                    'anchor_position' => [-0.55, -0.70, 0.75],
                    'marker_color' => '#6E8BB5',
                ],
                [
                    'slug' => 'left-atrium',
                    'ta_term' => 'Atrium sinistrum',
                    'name' => 'Left Atrium',
                    'description' => 'The upper left chamber, sitting furthest back in the '
                        .'chest. Four pulmonary veins open into it.',
                    'function' => 'Receives oxygenated blood returning from the lungs and '
                        .'passes it through the mitral valve into the left ventricle.',
                    'location' => 'Upper left of the heart, forming most of its posterior base.',
                    'difficulty' => 2,
                    'anchor_position' => [0.80, 0.60, -0.45],
                    'marker_color' => '#D34B4B',
                ],
                [
                    'slug' => 'left-ventricle',
                    'ta_term' => 'Ventriculus sinister',
                    'name' => 'Left Ventricle',
                    'description' => 'The lower left chamber and the strongest part of the '
                        .'heart. Its wall is roughly three times thicker than the right '
                        .'ventricle\'s because it drives blood around the entire body.',
                    'function' => 'Ejects oxygenated blood through the aortic valve into the '
                        .'aorta, generating the systemic blood pressure measured at the arm.',
                    'location' => 'Lower left of the heart, running down to the apex.',
                    'difficulty' => 1,
                    'anchor_position' => [0.70, -0.75, 0.65],
                    'marker_color' => '#D34B4B',
                ],
                [
                    'slug' => 'aorta',
                    'ta_term' => 'Aorta',
                    'name' => 'Aorta',
                    'description' => 'The largest artery in the body, arching up and over from '
                        .'the top of the heart before running down through the chest and abdomen.',
                    'function' => 'Carries oxygenated blood out of the left ventricle and '
                        .'distributes it to every systemic artery.',
                    'location' => 'Rises from the top of the heart, arches to the left, then '
                        .'descends behind it.',
                    'difficulty' => 1,
                    'anchor_position' => [0.10, 1.35, -0.20],
                    'marker_color' => '#D34B4B',
                ],
                [
                    'slug' => 'pulmonary-trunk',
                    'ta_term' => 'Truncus pulmonalis',
                    'name' => 'Pulmonary Trunk',
                    'description' => 'A short, wide vessel that leaves the right ventricle and '
                        .'divides almost immediately into the left and right pulmonary arteries.',
                    'function' => 'Carries deoxygenated blood from the right ventricle to the '
                        .'lungs — the only arteries in the body that carry deoxygenated blood.',
                    'location' => 'Front of the heart, crossing in front of the aorta as it rises.',
                    'difficulty' => 2,
                    'anchor_position' => [-0.30, 1.15, 0.55],
                    'marker_color' => '#6E8BB5',
                ],
                [
                    'slug' => 'superior-vena-cava',
                    'ta_term' => 'Vena cava superior',
                    'name' => 'Superior Vena Cava',
                    'description' => 'A large vein formed where the veins of the head, neck and '
                        .'arms join behind the right side of the sternum.',
                    'function' => 'Returns deoxygenated blood from the upper half of the body '
                        .'into the right atrium.',
                    'location' => 'Enters the top of the right atrium from above.',
                    'difficulty' => 2,
                    'anchor_position' => [-0.95, 1.05, 0.10],
                    'marker_color' => '#6E8BB5',
                ],
                [
                    'slug' => 'mitral-valve',
                    'ta_term' => 'Valva mitralis',
                    'name' => 'Mitral Valve',
                    'description' => 'The only heart valve with two cusps rather than three, '
                        .'which is why it is also called the bicuspid valve.',
                    'function' => 'Opens to let blood pass from the left atrium into the left '
                        .'ventricle and snaps shut during contraction so none flows backwards.',
                    'location' => 'Between the left atrium and the left ventricle.',
                    'difficulty' => 3,
                    'anchor_position' => [0.45, 0.05, 0.10],
                    'marker_color' => '#F2B13C',
                ],
                [
                    'slug' => 'apex',
                    'ta_term' => 'Apex cordis',
                    'name' => 'Apex of the Heart',
                    'description' => 'The blunt tip of the heart, formed by the left ventricle. '
                        .'Its beat can be felt on the chest wall in the fifth intercostal space.',
                    'function' => 'Marks the point where the heartbeat is most easily felt and '
                        .'listened to; it is the leading edge of ventricular contraction.',
                    'location' => 'Points down, forward and to the left.',
                    'difficulty' => 2,
                    'anchor_position' => [0.55, -1.45, 0.40],
                    'marker_color' => '#F2B13C',
                ],
            ],
            'relations' => [
                ['superior-vena-cava', 'right-atrium', StructureRelationType::FlowsInto],
                ['right-atrium', 'right-ventricle', StructureRelationType::FlowsInto],
                ['right-ventricle', 'pulmonary-trunk', StructureRelationType::FlowsInto],
                ['left-atrium', 'left-ventricle', StructureRelationType::FlowsInto],
                ['left-ventricle', 'aorta', StructureRelationType::FlowsInto],
                ['mitral-valve', 'left-atrium', StructureRelationType::Adjacent],
                ['mitral-valve', 'left-ventricle', StructureRelationType::Adjacent],
                ['apex', 'left-ventricle', StructureRelationType::PartOf],
                ['left-ventricle', 'right-ventricle', StructureRelationType::Counterpart],
                ['left-atrium', 'right-atrium', StructureRelationType::Counterpart],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lungs(): array
    {
        return [
            'slug' => 'lungs',
            'name' => 'Lungs',
            'scientific_name' => 'Pulmones',
            'body_system' => 'respiratory',
            'accent_color' => '#3E9EDB',
            'model_path' => 'models/lungs.glb',
            'thumbnail_path' => 'models/lungs.webp',
            'description' => 'A pair of spongy organs filling most of the chest. The right lung '
                .'has three lobes, the left only two — the heart takes the space where a third '
                .'would be. Air reaches roughly 300 million alveoli, where oxygen crosses into '
                .'the blood and carbon dioxide crosses out.',
            'structures' => [
                [
                    'slug' => 'trachea',
                    'ta_term' => 'Trachea',
                    'name' => 'Trachea',
                    'description' => 'The windpipe: a tube about 10–12 cm long held open by '
                        .'C-shaped rings of cartilage, incomplete at the back so the oesophagus '
                        .'behind it can expand.',
                    'function' => 'Carries air between the larynx and the bronchi, warming and '
                        .'filtering it on the way.',
                    'location' => 'Midline of the neck and upper chest, in front of the oesophagus.',
                    'difficulty' => 1,
                    'anchor_position' => [0.00, 1.55, 0.10],
                    'marker_color' => '#3E9EDB',
                ],
                [
                    'slug' => 'carina',
                    'ta_term' => 'Carina tracheae',
                    'name' => 'Carina',
                    'description' => 'The ridge of cartilage at the point where the trachea '
                        .'divides. It is one of the most sensitive areas of the airway — touching '
                        .'it triggers a violent cough reflex.',
                    'function' => 'Marks the division of the trachea into the two main bronchi '
                        .'and guards the airway below it.',
                    'location' => 'At the base of the trachea, roughly level with the fifth '
                        .'thoracic vertebra.',
                    'difficulty' => 4,
                    'anchor_position' => [0.00, 0.85, 0.05],
                    'marker_color' => '#7BC2E8',
                ],
                [
                    'slug' => 'right-main-bronchus',
                    'ta_term' => 'Bronchus principalis dexter',
                    'name' => 'Right Main Bronchus',
                    'description' => 'Wider, shorter and more vertical than the left. Inhaled '
                        .'objects end up here far more often for exactly that reason.',
                    'function' => 'Carries air from the carina into the right lung.',
                    'location' => 'Runs from the carina to the hilum of the right lung.',
                    'difficulty' => 3,
                    'anchor_position' => [-0.45, 0.62, 0.05],
                    'marker_color' => '#7BC2E8',
                ],
                [
                    'slug' => 'left-main-bronchus',
                    'ta_term' => 'Bronchus principalis sinister',
                    'name' => 'Left Main Bronchus',
                    'description' => 'Narrower and more horizontal than the right, because it '
                        .'has to pass under the arch of the aorta to reach the left lung.',
                    'function' => 'Carries air from the carina into the left lung.',
                    'location' => 'Runs from the carina to the hilum of the left lung.',
                    'difficulty' => 3,
                    'anchor_position' => [0.48, 0.58, 0.05],
                    'marker_color' => '#7BC2E8',
                ],
                [
                    'slug' => 'right-superior-lobe',
                    'ta_term' => 'Lobus superior pulmonis dextri',
                    'name' => 'Right Superior Lobe',
                    'description' => 'The uppermost of the right lung\'s three lobes, reaching '
                        .'slightly above the first rib at the apex.',
                    'function' => 'Ventilates the upper right chest; its apex is where '
                        .'tuberculosis classically settles.',
                    'location' => 'Above the horizontal fissure of the right lung.',
                    'difficulty' => 2,
                    'anchor_position' => [-0.95, 0.95, 0.25],
                    'marker_color' => '#3E9EDB',
                ],
                [
                    'slug' => 'right-middle-lobe',
                    'ta_term' => 'Lobus medius pulmonis dextri',
                    'name' => 'Right Middle Lobe',
                    'description' => 'The smallest lobe, and the one the left lung has no '
                        .'equivalent of. It is a wedge between the horizontal and oblique fissures.',
                    'function' => 'Ventilates the front-lower part of the right chest.',
                    'location' => 'Between the horizontal and oblique fissures, facing forwards.',
                    'difficulty' => 3,
                    'anchor_position' => [-1.05, -0.10, 0.55],
                    'marker_color' => '#3E9EDB',
                ],
                [
                    'slug' => 'right-inferior-lobe',
                    'ta_term' => 'Lobus inferior pulmonis dextri',
                    'name' => 'Right Inferior Lobe',
                    'description' => 'The largest lobe of the right lung, sitting on the '
                        .'diaphragm and extending further down at the back than the front.',
                    'function' => 'Does most of the right lung\'s gas exchange, especially '
                        .'during deep breathing.',
                    'location' => 'Below the oblique fissure, resting on the diaphragm.',
                    'difficulty' => 2,
                    'anchor_position' => [-1.00, -1.05, -0.15],
                    'marker_color' => '#3E9EDB',
                ],
                [
                    'slug' => 'left-superior-lobe',
                    'ta_term' => 'Lobus superior pulmonis sinistri',
                    'name' => 'Left Superior Lobe',
                    'description' => 'The upper of the left lung\'s two lobes. Its front edge '
                        .'is scooped out by the cardiac notch, where the heart sits against it.',
                    'function' => 'Ventilates the upper left chest.',
                    'location' => 'Above the oblique fissure of the left lung.',
                    'difficulty' => 2,
                    'anchor_position' => [1.00, 0.90, 0.30],
                    'marker_color' => '#3E9EDB',
                ],
                [
                    'slug' => 'left-inferior-lobe',
                    'ta_term' => 'Lobus inferior pulmonis sinistri',
                    'name' => 'Left Inferior Lobe',
                    'description' => 'The lower of the left lung\'s two lobes, sitting on the '
                        .'left dome of the diaphragm.',
                    'function' => 'Does most of the left lung\'s gas exchange.',
                    'location' => 'Below the oblique fissure, resting on the diaphragm.',
                    'difficulty' => 2,
                    'anchor_position' => [1.05, -1.00, -0.20],
                    'marker_color' => '#3E9EDB',
                ],
            ],
            'relations' => [
                ['trachea', 'carina', StructureRelationType::FlowsInto],
                ['carina', 'right-main-bronchus', StructureRelationType::FlowsInto],
                ['carina', 'left-main-bronchus', StructureRelationType::FlowsInto],
                ['right-main-bronchus', 'left-main-bronchus', StructureRelationType::Counterpart],
                ['right-superior-lobe', 'right-middle-lobe', StructureRelationType::Adjacent],
                ['right-middle-lobe', 'right-inferior-lobe', StructureRelationType::Adjacent],
                ['left-superior-lobe', 'left-inferior-lobe', StructureRelationType::Adjacent],
                ['right-superior-lobe', 'left-superior-lobe', StructureRelationType::Counterpart],
                ['right-inferior-lobe', 'left-inferior-lobe', StructureRelationType::Counterpart],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function brain(): array
    {
        return [
            'slug' => 'brain',
            'name' => 'Brain',
            'scientific_name' => 'Encephalon',
            'body_system' => 'nervous',
            'accent_color' => '#8E5BD9',
            'model_path' => 'models/brain.glb',
            'thumbnail_path' => 'models/brain.webp',
            'description' => 'Roughly 1.3 kg of tissue holding something like 86 billion '
                .'neurons. The wrinkled outer cortex is folded so that a large surface area '
                .'fits inside the skull; beneath it the cerebellum coordinates movement and the '
                .'brainstem keeps breathing and heartbeat running without conscious effort.',
            'structures' => [
                [
                    'slug' => 'frontal-lobe',
                    'ta_term' => 'Lobus frontalis',
                    'name' => 'Frontal Lobe',
                    'description' => 'The largest lobe, occupying everything in front of the '
                        .'central sulcus. It is the last part of the brain to finish maturing, '
                        .'typically in the mid-twenties.',
                    'function' => 'Planning, decision-making, personality, working memory and '
                        .'voluntary movement; it also contains Broca\'s area for speech production.',
                    'location' => 'Front of the cerebrum, behind the forehead.',
                    'difficulty' => 1,
                    'anchor_position' => [0.10, 0.65, 1.25],
                    'marker_color' => '#8E5BD9',
                ],
                [
                    'slug' => 'parietal-lobe',
                    'ta_term' => 'Lobus parietalis',
                    'name' => 'Parietal Lobe',
                    'description' => 'Sits behind the central sulcus, along the top of the '
                        .'brain. Its front edge carries the map of body sensation.',
                    'function' => 'Processes touch, temperature, pain and body position, and '
                        .'builds the sense of where the body is in space.',
                    'location' => 'Top of the cerebrum, between the frontal and occipital lobes.',
                    'difficulty' => 1,
                    'anchor_position' => [0.15, 1.15, -0.35],
                    'marker_color' => '#8E5BD9',
                ],
                [
                    'slug' => 'temporal-lobe',
                    'ta_term' => 'Lobus temporalis',
                    'name' => 'Temporal Lobe',
                    'description' => 'The lobe below the lateral sulcus, roughly behind the '
                        .'ear. The hippocampus is folded inside its medial surface.',
                    'function' => 'Hearing, language comprehension, and the formation of new '
                        .'long-term memories.',
                    'location' => 'Side of the cerebrum, below the lateral sulcus.',
                    'difficulty' => 2,
                    'anchor_position' => [1.05, -0.35, 0.35],
                    'marker_color' => '#A47BE3',
                ],
                [
                    'slug' => 'occipital-lobe',
                    'ta_term' => 'Lobus occipitalis',
                    'name' => 'Occipital Lobe',
                    'description' => 'The smallest lobe, at the very back of the brain — a long '
                        .'way from the eyes that feed it.',
                    'function' => 'Almost entirely devoted to vision: interpreting shape, '
                        .'colour, motion and depth from the signals arriving down the optic nerve.',
                    'location' => 'Rear of the cerebrum, above the cerebellum.',
                    'difficulty' => 2,
                    'anchor_position' => [0.05, 0.45, -1.35],
                    'marker_color' => '#A47BE3',
                ],
                [
                    'slug' => 'cerebellum',
                    'ta_term' => 'Cerebellum',
                    'name' => 'Cerebellum',
                    'description' => 'The "little brain" tucked under the back of the cerebrum. '
                        .'It holds more neurons than the rest of the brain combined, packed into '
                        .'a much smaller volume.',
                    'function' => 'Coordinates timing, balance and fine motor control, and '
                        .'smooths movement that the cerebrum has only sketched.',
                    'location' => 'Below the occipital lobe, behind the brainstem.',
                    'difficulty' => 2,
                    'anchor_position' => [0.00, -0.75, -1.00],
                    'marker_color' => '#5BC0A8',
                ],
                [
                    'slug' => 'pons',
                    'ta_term' => 'Pons',
                    'name' => 'Pons',
                    'description' => 'A bulge on the front of the brainstem whose name means '
                        .'"bridge" — its fibres cross to the cerebellum on either side.',
                    'function' => 'Relays signals between the cerebrum and the cerebellum and '
                        .'helps set the rhythm of breathing.',
                    'location' => 'Middle section of the brainstem, above the medulla oblongata.',
                    'difficulty' => 4,
                    'anchor_position' => [0.00, -0.85, 0.05],
                    'marker_color' => '#5BC0A8',
                ],
                [
                    'slug' => 'medulla-oblongata',
                    'ta_term' => 'Medulla oblongata',
                    'name' => 'Medulla Oblongata',
                    'description' => 'The lowest part of the brainstem, continuous with the '
                        .'spinal cord. Damage here is life-threatening in a way damage to a '
                        .'cortical lobe is not.',
                    'function' => 'Runs the reflexes nobody chooses: heart rate, blood '
                        .'pressure, breathing, swallowing, coughing and vomiting.',
                    'location' => 'Base of the brainstem, at the opening of the skull.',
                    'difficulty' => 3,
                    'anchor_position' => [0.00, -1.45, -0.15],
                    'marker_color' => '#5BC0A8',
                ],
                [
                    'slug' => 'longitudinal-fissure',
                    'ta_term' => 'Fissura longitudinalis cerebri',
                    'name' => 'Longitudinal Fissure',
                    'description' => 'The deep groove running front to back along the top of '
                        .'the brain, separating the two cerebral hemispheres.',
                    'function' => 'Divides the left and right hemispheres; the corpus callosum '
                        .'runs across its floor and carries the traffic between them.',
                    'location' => 'Midline of the cerebrum, from front to back.',
                    'difficulty' => 3,
                    'anchor_position' => [0.00, 1.30, 0.45],
                    'marker_color' => '#A47BE3',
                ],
            ],
            'relations' => [
                ['frontal-lobe', 'parietal-lobe', StructureRelationType::Adjacent],
                ['parietal-lobe', 'occipital-lobe', StructureRelationType::Adjacent],
                ['temporal-lobe', 'frontal-lobe', StructureRelationType::Adjacent],
                ['temporal-lobe', 'parietal-lobe', StructureRelationType::Adjacent],
                ['occipital-lobe', 'cerebellum', StructureRelationType::Adjacent],
                ['cerebellum', 'pons', StructureRelationType::Adjacent],
                ['pons', 'medulla-oblongata', StructureRelationType::FlowsInto],
                ['longitudinal-fissure', 'frontal-lobe', StructureRelationType::Adjacent],
                ['longitudinal-fissure', 'parietal-lobe', StructureRelationType::Adjacent],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function liver(): array
    {
        return [
            'slug' => 'liver',
            'name' => 'Liver',
            'scientific_name' => 'Hepar',
            'body_system' => 'digestive',
            'accent_color' => '#B86858',
            'model_path' => 'models/liver.glb',
            'thumbnail_path' => 'models/liver.webp',
            'description' => 'The largest internal organ, weighing around 1.5 kg in an adult. '
                .'It is a dark reddish-brown wedge tucked under the right dome of the '
                .'diaphragm, and it takes about 1.5 litres of blood a minute — three quarters '
                .'of it arriving from the gut rather than from an artery.',
            'structures' => [
                [
                    'slug' => 'right-lobe',
                    'ta_term' => 'Lobus hepatis dexter',
                    'name' => 'Right Lobe',
                    'description' => 'Much the larger of the two lobes, filling the space under '
                        .'the right ribs. The falciform ligament marks its border with the left '
                        .'lobe on the front surface.',
                    'function' => 'Its hepatocytes strip nutrients, drugs and worn-out red cell '
                        .'pigment out of the blood arriving from the gut, store glucose as '
                        .'glycogen, and secrete bile.',
                    'location' => 'Right upper quadrant of the abdomen, under the right dome of '
                        .'the diaphragm and largely hidden behind the lower ribs.',
                    'difficulty' => 1,
                    'anchor_position' => [-0.75, 0.35, 0.75],
                    'marker_color' => '#D34B4B',
                ],
                [
                    'slug' => 'left-lobe',
                    'ta_term' => 'Lobus hepatis sinister',
                    'name' => 'Left Lobe',
                    'description' => 'The smaller and flatter lobe. It crosses the midline to '
                        .'lie on top of the stomach, separated from the right lobe by the '
                        .'falciform ligament.',
                    'function' => 'Does the same work as the right lobe — bile production, '
                        .'glycogen storage and detoxification — on its share of the incoming '
                        .'portal blood.',
                    'location' => 'Left of the falciform ligament, resting against the front of '
                        .'the stomach.',
                    'difficulty' => 1,
                    'anchor_position' => [0.85, 0.25, 0.75],
                    'marker_color' => '#F2B13C',
                ],
                [
                    'slug' => 'portal',
                    'ta_term' => 'Vena portae hepatis',
                    'name' => 'Hepatic Portal Vein',
                    'description' => 'A short, wide vein formed behind the neck of the pancreas '
                        .'where the splenic and superior mesenteric veins meet. It is a portal '
                        .'vein because it runs from one capillary bed to another instead of '
                        .'straight back to the heart.',
                    'function' => 'Delivers roughly three quarters of the liver\'s blood '
                        .'supply, carrying everything absorbed from the intestines past the '
                        .'hepatocytes before any of it reaches the rest of the body.',
                    'location' => 'Enters the underside of the liver at the porta hepatis, '
                        .'lying behind the bile duct and the hepatic artery.',
                    'difficulty' => 3,
                    'anchor_position' => [0.10, -0.30, 0.82],
                    'marker_color' => '#6E8BB5',
                ],
                [
                    'slug' => 'gallbladder',
                    'ta_term' => 'Vesica biliaris',
                    'name' => 'Gallbladder',
                    'description' => 'A pear-shaped sac about 8 cm long holding around 50 ml of '
                        .'bile. Its lining is thrown into folds when empty and stretches smooth '
                        .'as it fills.',
                    'function' => 'Stores bile between meals and concentrates it by absorbing '
                        .'water, then contracts when fatty food reaches the duodenum and pushes '
                        .'the bile out along the cystic duct.',
                    'location' => 'In a shallow fossa on the underside of the right lobe, just '
                        .'to the right of the porta hepatis.',
                    'difficulty' => 2,
                    // Interpolated: X is the midpoint of the right-lobe and portal anchors and
                    // Z matches the portal anchor, because the gallbladder sits on the visceral
                    // surface beside the porta hepatis; Y is dropped below the portal anchor
                    // because the fossa is on the inferior surface of the right lobe.
                    'anchor_position' => [-0.33, -0.60, 0.82],
                    'marker_color' => '#C98BBE',
                ],
                [
                    'slug' => 'hepatic-artery',
                    'ta_term' => 'Arteria hepatica propria',
                    'name' => 'Proper Hepatic Artery',
                    'description' => 'A vessel about 4 mm across that reaches the liver as one '
                        .'of the three structures of the portal triad, then splits into a right '
                        .'and a left branch, one for each lobe.',
                    'function' => 'Brings the remaining quarter of the liver\'s blood supply '
                        .'straight from the aorta, fully oxygenated. It is the only supply the '
                        .'bile ducts themselves have, which is why blocking it damages them first.',
                    'location' => 'Runs up to the porta hepatis in front of the portal vein and '
                        .'to the left of the bile duct.',
                    'difficulty' => 3,
                    // Placed from the portal anchor: the artery lies in the same portal triad,
                    // so it keeps that anchor's depth and sits to the patient-left of it
                    // (+X) and marginally lower, the arrangement of the triad at the porta.
                    // needs an authoring pass
                    'anchor_position' => [0.48, -0.38, 0.80],
                    'marker_color' => '#D34B4B',
                ],
                [
                    'slug' => 'hepatic-veins',
                    'ta_term' => 'Venae hepaticae',
                    'name' => 'Hepatic Veins',
                    'description' => 'Usually three large veins — right, middle and left — that '
                        .'gather blood from the whole liver. Unlike most veins of this size '
                        .'they have no valves.',
                    'function' => 'Carry blood out of the liver into the inferior vena cava, '
                        .'just below the diaphragm, completing the route that started at the '
                        .'portal vein. They are the liver\'s only venous exit.',
                    'location' => 'Emerge from the back of the upper surface, where the '
                        .'inferior vena cava is bedded in a groove between the two lobes.',
                    'difficulty' => 4,
                    // Placed above the midpoint of the right-lobe and left-lobe anchors, since
                    // the veins converge between the lobes at the top of the organ; Z is pulled
                    // back from the 0.75 the lobes use because the groove is a posterior
                    // feature, but kept positive so the marker stays on a visible surface.
                    // needs an authoring pass
                    'anchor_position' => [-0.10, 0.90, 0.55],
                    'marker_color' => '#6E8BB5',
                ],
                [
                    'slug' => 'common-bile-duct',
                    'ta_term' => 'Ductus choledochus',
                    'name' => 'Common Bile Duct',
                    'description' => 'A tube about 7 cm long and 6 mm wide, formed where the '
                        .'duct leaving the gallbladder joins the duct leaving the liver.',
                    'function' => 'Carries bile down to the duodenum, passing through the head '
                        .'of the pancreas on the way. A stone stuck in it dams bile back into '
                        .'the blood, which turns the skin and eyes yellow.',
                    'location' => 'Descends from the porta hepatis in the free edge of the '
                        .'lesser omentum, to the right of the hepatic artery.',
                    'difficulty' => 3,
                    // Placed below the gallbladder and portal anchors and between them in X,
                    // because the duct forms just beneath the porta hepatis where the cystic
                    // duct from the gallbladder meets the common hepatic duct, then runs down;
                    // Z is held at the visceral-surface depth those two anchors share.
                    // needs an authoring pass
                    'anchor_position' => [-0.05, -0.95, 0.78],
                    'marker_color' => '#C98BBE',
                ],
                [
                    'slug' => 'falciform-ligament',
                    'ta_term' => 'Ligamentum falciforme hepatis',
                    'name' => 'Falciform Ligament',
                    'description' => 'A thin sickle-shaped sheet of peritoneum, two layers '
                        .'thick. Its free lower edge contains the round ligament, which is the '
                        .'closed-off umbilical vein everybody used before birth.',
                    'function' => 'Anchors the front of the liver to the diaphragm and the '
                        .'abdominal wall, and marks on the surface where the right and left '
                        .'lobes meet.',
                    'location' => 'Runs up the front surface of the liver in the midline, '
                        .'between the two lobes.',
                    'difficulty' => 2,
                    // Placed at the midpoint of the right-lobe and left-lobe anchors, which is
                    // by definition the line the ligament marks; Z is raised to the 0.82 the
                    // portal and gallbladder anchors use because the ligament is the most
                    // anterior feature on the organ.
                    // needs an authoring pass
                    'anchor_position' => [0.05, 0.30, 0.82],
                    'marker_color' => '#F2B13C',
                ],
            ],
            'relations' => [
                ['portal', 'right-lobe', StructureRelationType::FlowsInto],
                ['portal', 'left-lobe', StructureRelationType::FlowsInto],
                ['right-lobe', 'gallbladder', StructureRelationType::FlowsInto],
                ['left-lobe', 'gallbladder', StructureRelationType::FlowsInto],
                ['right-lobe', 'left-lobe', StructureRelationType::Counterpart],
                ['gallbladder', 'portal', StructureRelationType::Adjacent],
                ['hepatic-artery', 'right-lobe', StructureRelationType::FlowsInto],
                ['hepatic-artery', 'left-lobe', StructureRelationType::FlowsInto],
                ['right-lobe', 'hepatic-veins', StructureRelationType::FlowsInto],
                ['left-lobe', 'hepatic-veins', StructureRelationType::FlowsInto],
                ['gallbladder', 'common-bile-duct', StructureRelationType::FlowsInto],
                ['hepatic-artery', 'portal', StructureRelationType::Adjacent],
                ['common-bile-duct', 'portal', StructureRelationType::Adjacent],
                ['common-bile-duct', 'hepatic-artery', StructureRelationType::Adjacent],
                ['falciform-ligament', 'right-lobe', StructureRelationType::Adjacent],
                ['falciform-ligament', 'left-lobe', StructureRelationType::Adjacent],
                ['hepatic-veins', 'right-lobe', StructureRelationType::PartOf],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function kidneys(): array
    {
        return [
            'slug' => 'kidneys',
            'name' => 'Kidneys',
            'scientific_name' => 'Renes',
            'body_system' => 'urinary',
            'accent_color' => '#C96963',
            'model_path' => 'models/kidneys.glb',
            'thumbnail_path' => 'models/kidneys.webp',
            'description' => 'A pair of bean-shaped organs, each about 11 cm long and 150 g, '
                .'lying against the back wall of the abdomen behind the peritoneum. Between '
                .'them they filter roughly 180 litres of fluid out of the blood every day and '
                .'take almost all of it back, leaving one to two litres of urine.',
            'structures' => [
                [
                    'slug' => 'cortex',
                    'ta_term' => 'Cortex renalis',
                    'name' => 'Renal Cortex',
                    'description' => 'The outer shell of the kidney, about 1 cm thick and '
                        .'grainy in texture because it is packed with glomeruli. Columns of it '
                        .'dip inwards between the pyramids of the medulla.',
                    'function' => 'Holds the glomeruli, where blood pressure forces water, '
                        .'salts, glucose and urea out of the capillaries to form filtrate.',
                    'location' => 'Directly beneath the fibrous capsule, wrapping the whole '
                        .'outer surface of the kidney.',
                    'difficulty' => 1,
                    'anchor_position' => [-0.90, 0.55, 0.70],
                    'marker_color' => '#D34B4B',
                ],
                [
                    'slug' => 'medulla',
                    'ta_term' => 'Medulla renalis',
                    'name' => 'Renal Medulla',
                    'description' => 'The inner region, built from roughly 8 to 18 cone-shaped '
                        .'renal pyramids. Their striped appearance comes from the parallel '
                        .'tubules and collecting ducts running through them.',
                    'function' => 'Concentrates the filtrate: the loops of Henle hold a salt '
                        .'gradient that draws water back into the blood, and the collecting '
                        .'ducts deliver the finished urine to the pyramid tips.',
                    'location' => 'Deep to the cortex, with the tip of each pyramid pointing '
                        .'inwards towards the renal sinus.',
                    'difficulty' => 2,
                    'anchor_position' => [0.85, 0.20, 0.70],
                    'marker_color' => '#F2B13C',
                ],
                [
                    'slug' => 'renal-pelvis',
                    'ta_term' => 'Pelvis renalis',
                    'name' => 'Renal Pelvis',
                    'description' => 'The flattened funnel formed where the major calices '
                        .'merge. Each minor calix below it cups the tip of one pyramid.',
                    'function' => 'Collects urine from every pyramid tip and channels it into '
                        .'the ureter, where the first peristaltic waves take over.',
                    'location' => 'Inside the renal sinus, narrowing as it leaves the hilum on '
                        .'the medial border of the kidney.',
                    'difficulty' => 2,
                    // Interpolated: the midpoint of the medulla and ureter anchors, because
                    // the pelvis is exactly the funnel that joins the two.
                    'anchor_position' => [0.63, -0.45, 0.60],
                    'marker_color' => '#C98BBE',
                ],
                [
                    'slug' => 'ureter',
                    'ta_term' => 'Ureter',
                    'name' => 'Ureter',
                    'description' => 'A muscular tube 25 to 30 cm long running from the kidney '
                        .'to the back of the bladder. It narrows at three points, and those are '
                        .'where kidney stones tend to lodge.',
                    'function' => 'Squeezes urine down towards the bladder in peristaltic '
                        .'waves, so it keeps working whatever position the body is in.',
                    'location' => 'Descends on the psoas muscle at the back of the abdomen, '
                        .'then crosses the pelvic brim to reach the bladder.',
                    'difficulty' => 1,
                    'anchor_position' => [0.40, -1.10, 0.50],
                    'marker_color' => '#6E8BB5',
                ],
                [
                    'slug' => 'renal-artery',
                    'ta_term' => 'Arteria renalis',
                    'name' => 'Renal Artery',
                    'description' => 'A short, wide branch straight off the aorta — one for '
                        .'each kidney. It divides into five segmental arteries before it even '
                        .'gets inside the organ.',
                    'function' => 'Delivers the blood that is about to be filtered. The two '
                        .'renal arteries together carry roughly a fifth of everything the heart '
                        .'pumps, which is why narrowing one raises blood pressure across the '
                        .'whole body.',
                    'location' => 'Runs sideways from the aorta into the hilum, lying behind '
                        .'the renal vein.',
                    'difficulty' => 2,
                    // Placed on the line from the renal-pelvis anchor towards the midline,
                    // because artery, vein and pelvis all enter at the same hilum and the
                    // artery comes in from the aorta at x ≈ 0; Y and Z sit between the medulla
                    // and renal-pelvis anchors, and Z stays behind the vein below.
                    // needs an authoring pass
                    'anchor_position' => [0.32, 0.02, 0.58],
                    'marker_color' => '#D34B4B',
                ],
                [
                    'slug' => 'renal-vein',
                    'ta_term' => 'Vena renalis',
                    'name' => 'Renal Vein',
                    'description' => 'The vessel that leaves the hilum in front of the artery. '
                        .'The left one is about three times longer than the right, because it '
                        .'has to cross the midline in front of the aorta to reach the inferior '
                        .'vena cava.',
                    'function' => 'Returns the blood the kidney has finished cleaning — now '
                        .'low in urea and with its salt and water balance corrected — to the '
                        .'inferior vena cava.',
                    'location' => 'Leaves the hilum in front of the renal artery and runs '
                        .'medially towards the inferior vena cava.',
                    'difficulty' => 3,
                    // Placed just in front of and below the renal-artery anchor above, which is
                    // the constant relationship at the hilum (vein anterior, artery posterior);
                    // Z is lifted to 0.75, a little forward of the 0.70 the cortex and medulla
                    // anchors use, to keep the two vessels distinguishable.
                    // needs an authoring pass
                    'anchor_position' => [0.20, -0.40, 0.75],
                    'marker_color' => '#6E8BB5',
                ],
                [
                    'slug' => 'renal-capsule',
                    'ta_term' => 'Capsula fibrosa renis',
                    'name' => 'Renal Capsule',
                    'description' => 'A tough, smooth sheet of fibrous tissue less than a '
                        .'millimetre thick that wraps the kidney like cling film and strips off '
                        .'cleanly from a healthy organ.',
                    'function' => 'Protects the soft tissue inside and holds its shape. It '
                        .'barely stretches, so anything that swells the kidney presses on the '
                        .'nerves in the capsule — that is the pain of a kidney infection.',
                    'location' => 'The outermost layer of the kidney itself, directly over the '
                        .'cortex and under the surrounding fat.',
                    'difficulty' => 3,
                    // Placed on the same kidney as the cortex anchor but further out along its
                    // lateral convex border (more negative X) and lower, since the capsule is
                    // the surface the cortex sits under and needs its own clear patch of that
                    // surface; Z matches the anterior band of the existing anchors.
                    // needs an authoring pass
                    'anchor_position' => [-1.35, -0.15, 0.60],
                    'marker_color' => '#F2B13C',
                ],
                [
                    'slug' => 'major-calyx',
                    'ta_term' => 'Calix renalis major',
                    'name' => 'Major Calyx',
                    'description' => 'One of the two or three short funnels inside the kidney, '
                        .'each built from two or three minor calices joining together.',
                    'function' => 'Gathers urine from a group of pyramids and passes it on to '
                        .'the renal pelvis. Its wall contains smooth muscle, so the squeezing '
                        .'that drives urine towards the bladder begins here.',
                    'location' => 'In the renal sinus, between the tips of the pyramids and the '
                        .'renal pelvis.',
                    'difficulty' => 4,
                    // Placed between the medulla and renal-pelvis anchors, which is exactly
                    // where the calices sit in the drainage chain, then pushed laterally
                    // (+X, away from the hilum) so it does not crowd either of them; Z stays
                    // in the band those two anchors share.
                    // needs an authoring pass
                    'anchor_position' => [0.95, -0.22, 0.62],
                    'marker_color' => '#C98BBE',
                ],
            ],
            'relations' => [
                ['cortex', 'medulla', StructureRelationType::FlowsInto],
                ['medulla', 'renal-pelvis', StructureRelationType::FlowsInto],
                ['renal-pelvis', 'ureter', StructureRelationType::FlowsInto],
                ['renal-pelvis', 'medulla', StructureRelationType::Adjacent],
                ['renal-artery', 'cortex', StructureRelationType::FlowsInto],
                ['cortex', 'renal-vein', StructureRelationType::FlowsInto],
                ['medulla', 'major-calyx', StructureRelationType::FlowsInto],
                ['major-calyx', 'renal-pelvis', StructureRelationType::FlowsInto],
                ['renal-artery', 'renal-vein', StructureRelationType::Adjacent],
                ['renal-vein', 'renal-pelvis', StructureRelationType::Adjacent],
                ['renal-capsule', 'cortex', StructureRelationType::Adjacent],
                ['major-calyx', 'medulla', StructureRelationType::Adjacent],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pancreas(): array
    {
        return [
            'slug' => 'pancreas',
            'name' => 'Pancreas',
            'scientific_name' => 'Pancreas',
            'body_system' => 'digestive',
            'accent_color' => '#C69A5E',
            'model_path' => 'models/pancreas.glb',
            'thumbnail_path' => 'models/pancreas.webp',
            'description' => 'A soft, pale, lobulated gland about 15 cm long lying across the '
                .'back of the upper abdomen. It is two glands sharing one body: clusters of '
                .'acini that make digestive enzymes, and around a million islets of Langerhans '
                .'that release insulin and glucagon straight into the blood.',
            'structures' => [
                [
                    'slug' => 'head',
                    'ta_term' => 'Caput pancreatis',
                    'name' => 'Head of the Pancreas',
                    'description' => 'The widest part of the gland, sitting inside the C-shaped '
                        .'curve of the duodenum. The lower end of the bile duct passes through '
                        .'it on its way to the gut.',
                    'function' => 'Makes enzyme-rich pancreatic juice like the rest of the '
                        .'gland, and carries the last stretch of the bile duct to its shared '
                        .'opening into the duodenum.',
                    'location' => 'Right of the midline at about the level of the second lumbar '
                        .'vertebra, cradled by the duodenum.',
                    'difficulty' => 2,
                    'anchor_position' => [-1.32, -0.36, 0.55],
                    'marker_color' => '#D34B4B',
                ],
                [
                    'slug' => 'body',
                    'ta_term' => 'Corpus pancreatis',
                    'name' => 'Body of the Pancreas',
                    'description' => 'The long middle section, roughly triangular in '
                        .'cross-section, running up and to the left as it crosses the midline.',
                    'function' => 'Produces most of the gland\'s pancreatic juice and drains it '
                        .'sideways into the pancreatic duct running through its length.',
                    'location' => 'Behind the stomach, crossing in front of the aorta and the '
                        .'first lumbar vertebra.',
                    'difficulty' => 1,
                    'anchor_position' => [0.05, 0.25, 0.45],
                    'marker_color' => '#F2B13C',
                ],
                [
                    'slug' => 'tail',
                    'ta_term' => 'Cauda pancreatis',
                    'name' => 'Tail of the Pancreas',
                    'description' => 'The narrow left end, and the only part of the gland not '
                        .'fixed to the back wall, so it moves with the spleen it touches.',
                    'function' => 'Carries the densest concentration of islets of Langerhans, '
                        .'making it the main source of the insulin and glucagon the pancreas '
                        .'releases into the blood.',
                    'location' => 'Reaches left as far as the hilum of the spleen, held between '
                        .'the two layers of the splenorenal ligament.',
                    'difficulty' => 2,
                    'anchor_position' => [1.55, 0.30, 0.35],
                    'marker_color' => '#6E8BB5',
                ],
                [
                    'slug' => 'duct',
                    'ta_term' => 'Ductus pancreaticus',
                    'name' => 'Pancreatic Duct',
                    'description' => 'A duct running the full length of the gland from tail to '
                        .'head, widening as side branches join it. It is around 3 mm across by '
                        .'the time it reaches the head.',
                    'function' => 'Collects pancreatic juice from the acini and delivers it to '
                        .'the duodenum, where its enzymes break down starch, fat and protein.',
                    'location' => 'Runs lengthwise through the gland, joining the bile duct at '
                        .'the hepatopancreatic ampulla before opening at the major duodenal '
                        .'papilla.',
                    'difficulty' => 3,
                    'anchor_position' => [-0.61, 0.39, 0.50],
                    'marker_color' => '#C98BBE',
                ],
                [
                    'slug' => 'neck',
                    'ta_term' => 'Collum pancreatis',
                    'name' => 'Neck of the Pancreas',
                    'description' => 'A short waist about 2 cm long where the gland narrows '
                        .'between the head and the body.',
                    'function' => 'Passes the pancreatic duct on from the body towards the '
                        .'head. Its back surface is the roof over the point where the splenic '
                        .'and superior mesenteric veins join to become the portal vein, so a '
                        .'tumour here blocks that vein early.',
                    'location' => 'In front of the first lumbar vertebra, between the head to '
                        .'its right and the body to its left.',
                    'difficulty' => 3,
                    // Placed between the head and body anchors, which is what the neck is by
                    // definition, dropped below the duct anchor so the two do not overlap;
                    // Z sits between the head's 0.55 and the body's 0.45 to follow the front
                    // surface across the waist.
                    // needs an authoring pass
                    'anchor_position' => [-0.66, -0.10, 0.52],
                    'marker_color' => '#F2B13C',
                ],
                [
                    'slug' => 'uncinate-process',
                    'ta_term' => 'Processus uncinatus pancreatis',
                    'name' => 'Uncinate Process',
                    'description' => 'A hook of pancreatic tissue that projects from the lower '
                        .'left corner of the head and runs backwards underneath the rest of the '
                        .'gland. Some people have almost none of it.',
                    'function' => 'Makes pancreatic juice like the rest of the gland. Its shape '
                        .'is what makes it surgically important: it wraps behind the superior '
                        .'mesenteric artery and vein, so a tumour in it reaches those vessels '
                        .'quickly.',
                    'location' => 'Below and behind the head, extending left towards the '
                        .'midline under the neck.',
                    'difficulty' => 5,
                    // Placed down and to the patient-left of the head anchor, the direction the
                    // hook projects from the head, and kept well clear of the neck anchor above
                    // it; Z is just behind the head's 0.55 because the process passes behind
                    // the vessels.
                    // needs an authoring pass
                    'anchor_position' => [-1.05, -0.85, 0.48],
                    'marker_color' => '#D34B4B',
                ],
                [
                    'slug' => 'accessory-duct',
                    'ta_term' => 'Ductus pancreaticus accessorius',
                    'name' => 'Accessory Pancreatic Duct',
                    'description' => 'A second, smaller duct draining the upper front part of '
                        .'the head. It is the leftover of the duct the developing pancreas used '
                        .'before its two buds fused, and it stays open in a minority of adults.',
                    'function' => 'Gives the upper head its own route into the duodenum, '
                        .'opening at the minor duodenal papilla about 2 cm above where the main '
                        .'duct opens.',
                    'location' => 'In the upper head, running forwards above the main '
                        .'pancreatic duct and usually still connected to it.',
                    'difficulty' => 4,
                    // Placed between the head and duct anchors and slightly above the line
                    // joining them, since the accessory duct branches off the main duct at the
                    // neck and runs into the upper head; Z matches the duct anchor's 0.50.
                    // needs an authoring pass
                    'anchor_position' => [-1.10, 0.10, 0.52],
                    'marker_color' => '#C98BBE',
                ],
                [
                    'slug' => 'islets',
                    'ta_term' => 'Insulae pancreaticae',
                    'name' => 'Islets of Langerhans',
                    'description' => 'Around a million tiny balls of hormone-making cells '
                        .'scattered through the gland like currants in a cake. Together they '
                        .'are only about 2 per cent of its weight.',
                    'function' => 'Release hormones straight into the bloodstream rather than '
                        .'into a duct: beta cells make insulin, which moves glucose out of the '
                        .'blood, and alpha cells make glucagon, which puts it back. Type 1 '
                        .'diabetes is the loss of the beta cells.',
                    'location' => 'Spread through the whole gland, but packed most densely in '
                        .'the tail.',
                    'difficulty' => 4,
                    // Placed inboard of the tail anchor, on the line back towards the body,
                    // because the islets are densest in the tail but belong to the gland as a
                    // whole; Z sits between the tail's 0.35 and the body's 0.45.
                    // needs an authoring pass
                    'anchor_position' => [1.15, -0.10, 0.42],
                    'marker_color' => '#6E8BB5',
                ],
            ],
            'relations' => [
                ['tail', 'duct', StructureRelationType::FlowsInto],
                ['body', 'duct', StructureRelationType::FlowsInto],
                ['duct', 'head', StructureRelationType::FlowsInto],
                ['head', 'body', StructureRelationType::Adjacent],
                ['body', 'tail', StructureRelationType::Adjacent],
                ['head', 'neck', StructureRelationType::Adjacent],
                ['neck', 'body', StructureRelationType::Adjacent],
                ['neck', 'duct', StructureRelationType::Adjacent],
                ['uncinate-process', 'head', StructureRelationType::PartOf],
                ['uncinate-process', 'neck', StructureRelationType::Adjacent],
                ['head', 'accessory-duct', StructureRelationType::FlowsInto],
                ['accessory-duct', 'duct', StructureRelationType::Adjacent],
                ['islets', 'tail', StructureRelationType::PartOf],
                ['islets', 'body', StructureRelationType::PartOf],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function eyeball(): array
    {
        $accent = '#7294B9';

        return [
            'slug' => 'eyeball',
            'name' => 'Eyeball',
            'scientific_name' => 'Oculus',
            'body_system' => 'sensory',
            'accent_color' => $accent,
            'model_path' => 'models/eyeball.glb',
            'thumbnail_path' => 'models/eyeball.webp',
            'description' => 'A sphere roughly 24 mm across, sitting in the bony orbit and '
                .'moved by six small muscles. Light crosses the cornea, passes through the '
                .'pupil and the lens, and lands on the retina lining the back of the globe, '
                .'where it becomes nerve signals.',
            'structures' => [
                [
                    'slug' => 'cornea',
                    'ta_term' => 'Cornea',
                    'name' => 'Cornea',
                    'description' => 'The transparent dome at the front of the eye, about '
                        .'0.5 mm thick in the centre. It carries no blood vessels at all and '
                        .'takes its oxygen straight from the air through the tear film.',
                    'function' => 'Does most of the eye\'s focusing. Its curved front surface '
                        .'bends incoming light about twice as strongly as the lens does, and '
                        .'the lens then makes the fine adjustment.',
                    'location' => 'Front of the eyeball, meeting the white sclera at the limbus.',
                    'difficulty' => 1,
                    'anchor_position' => [-0.94, 0.05, 1.47],
                    'marker_color' => '#6E8BB5',
                ],
                [
                    'slug' => 'iris',
                    'ta_term' => 'Iris',
                    'name' => 'Iris',
                    'description' => 'The coloured ring of muscle just behind the cornea. The '
                        .'pupil is simply the hole in its centre, and eye colour comes from how '
                        .'much melanin the tissue holds rather than from any blue pigment.',
                    'function' => 'Changes the pupil between roughly 2 and 8 mm across: a ring '
                        .'of circular muscle narrows it in bright light, radial fibres pull it '
                        .'wide in the dark.',
                    'location' => 'Behind the cornea and in front of the lens, separating the '
                        .'anterior and posterior chambers.',
                    'difficulty' => 1,
                    'anchor_position' => [-1.22, -0.53, 1.15],
                    'marker_color' => '#F2B13C',
                ],
                [
                    'slug' => 'lens',
                    'ta_term' => 'Lens',
                    'name' => 'Lens',
                    'description' => 'A clear, biconvex disc about 10 mm across and 4 mm thick, '
                        .'slung behind the iris on a ring of fine zonular fibres. Like the '
                        .'cornea it holds no blood vessels, which is what keeps it transparent.',
                    'function' => 'Makes the fine focusing adjustment the cornea cannot. The '
                        .'ciliary muscle around it slackens the zonular fibres, the lens '
                        .'springs into a rounder shape and near objects come into focus; the '
                        .'lens stiffens with age, which is why reading glasses are usually '
                        .'needed from about 45.',
                    'location' => 'Directly behind the iris and pupil, on the visual axis, with '
                        .'the vitreous body behind it.',
                    'difficulty' => 2,
                    // Interpolated: the midpoint of the cornea and iris anchors, then moved
                    // 0.55 along the line towards the retina anchor, because the lens sits
                    // immediately behind the pupil rather than on the surface with them.
                    // Keeps z at 1.13, inside the 0.54-1.47 band the existing four use.
                    // needs an authoring pass
                    'anchor_position' => [-0.57, -0.29, 1.13],
                    'marker_color' => '#6E8BB5',
                ],
                [
                    'slug' => 'sclera',
                    'ta_term' => 'Sclera',
                    'name' => 'Sclera',
                    'description' => 'The tough white outer coat of the eye, built from dense '
                        .'collagen and between about 0.3 and 1 mm thick. It covers roughly the '
                        .'back five-sixths of the globe; the clear cornea covers the rest.',
                    'function' => 'Holds the eye in shape against the pressure of the fluid '
                        .'inside it, protects the softer layers underneath, and gives the six '
                        .'muscles that move the eye something firm to pull on.',
                    'location' => 'Outermost layer of the globe, running from the limbus at the '
                        .'edge of the cornea back to where the optic nerve leaves.',
                    'difficulty' => 1,
                    // Offset from the retina anchor: 1.6 superior to it and back towards the
                    // midline in x, which lands on the outer wall of the globe body above the
                    // stretch of retina it encloses. Well clear of the cornea and optic
                    // anchors at each end. needs an authoring pass
                    'anchor_position' => [0.05, 1.25, 1.05],
                    'marker_color' => '#F2B13C',
                ],
                [
                    'slug' => 'choroid',
                    'ta_term' => 'Choroidea',
                    'name' => 'Choroid',
                    'description' => 'A thin, dark, blood-rich sheet about 0.2 mm thick, '
                        .'sandwiched between the retina and the sclera. It is packed with '
                        .'melanin, which is why the inside of the eye is black.',
                    'function' => 'Feeds the outer retina. The photoreceptors have no blood '
                        .'vessels of their own and take their oxygen by diffusion from the '
                        .'choroid\'s capillaries. The pigment soaks up light that has already '
                        .'passed the retina so it cannot scatter back and blur the image.',
                    'location' => 'The middle of the eye\'s three coats, between the retina '
                        .'inside and the sclera outside.',
                    'difficulty' => 3,
                    // Mirrors the sclera anchor across the globe's horizontal midline and
                    // sits 0.85 inferior to the retina anchor, which is the relationship the
                    // layer has: immediately outside the retina, immediately inside the
                    // sclera. z 0.95 keeps it in the existing anterior band.
                    // needs an authoring pass
                    'anchor_position' => [0.30, -1.20, 0.95],
                    'marker_color' => '#C98BBE',
                ],
                [
                    'slug' => 'retina',
                    'ta_term' => 'Retina',
                    'name' => 'Retina',
                    'description' => 'A sheet of nervous tissue about 0.2 mm thick lining the '
                        .'inside of the globe. It holds something like 120 million rods, which '
                        .'work in dim light, and 6 million cones, which carry colour.',
                    'function' => 'Turns light into electrical signals: photoreceptors respond '
                        .'to photons, and the ganglion cells they feed send the result out of '
                        .'the eye along the optic nerve.',
                    'location' => 'Lines the inner wall of the eye from behind the ciliary body '
                        .'back to the optic disc.',
                    'difficulty' => 2,
                    // Interpolated: the midpoint of the iris and optic-nerve anchors, which
                    // puts it on the lateral wall of the globe between them. The retina lines
                    // that whole inner surface, so any point on the run is representative.
                    'anchor_position' => [0.20, -0.36, 0.85],
                    'marker_color' => '#D34B4B',
                ],
                [
                    'slug' => 'macula',
                    'ta_term' => 'Macula lutea',
                    'name' => 'Macula Lutea',
                    'description' => 'A yellowish patch of retina about 5 mm across at the back '
                        .'of the eye, with a small pit at its centre called the fovea. The '
                        .'fovea is roughly 1.5 mm wide and holds cones only — no rods at all.',
                    'function' => 'Delivers sharp detailed vision. Cones are packed more '
                        .'densely here than anywhere else in the retina, so whatever you look '
                        .'straight at lands on this patch; the rest of the retina fills in a '
                        .'much coarser surrounding image.',
                    'location' => 'On the retina at the back of the globe, a few millimetres to '
                        .'the temporal side of the optic disc.',
                    'difficulty' => 4,
                    // Placed between the retina and optic anchors — the macula sits just to
                    // one side of where the nerve leaves — then lifted about 0.4 superior so
                    // it does not land on the straight line joining them and crowd either
                    // marker. Roughly 0.8 from both. needs an authoring pass
                    'anchor_position' => [0.90, 0.05, 0.75],
                    'marker_color' => '#D34B4B',
                ],
                [
                    'slug' => 'optic',
                    'ta_term' => 'Nervus opticus',
                    'name' => 'Optic Nerve',
                    'description' => 'The second cranial nerve. Roughly a million axons from '
                        .'the retina\'s ganglion cells leave the eye together at the optic '
                        .'disc, which holds no photoreceptors and so produces the blind spot.',
                    'function' => 'Carries the retina\'s output back to the brain, where the '
                        .'fibres for the inner half of each retina cross to the opposite side '
                        .'at the optic chiasm.',
                    'location' => 'Leaves the back of the eyeball slightly towards the nose, '
                        .'then runs through the orbit and the optic canal.',
                    'difficulty' => 2,
                    'anchor_position' => [1.61, -0.18, 0.54],
                    'marker_color' => '#C98BBE',
                ],
            ],
            // Light is not a fluid and the eye is not a pipe, so the path cornea -> iris ->
            // retina is recorded as adjacency, not FlowsInto.
            'relations' => [
                ['cornea', 'iris', StructureRelationType::Adjacent],
                ['retina', 'optic', StructureRelationType::Adjacent],
                ['iris', 'lens', StructureRelationType::Adjacent],
                ['cornea', 'sclera', StructureRelationType::Adjacent],
                ['sclera', 'choroid', StructureRelationType::Adjacent],
                ['choroid', 'retina', StructureRelationType::Adjacent],
                ['macula', 'retina', StructureRelationType::PartOf],
                ['macula', 'optic', StructureRelationType::Adjacent],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function intestine(): array
    {
        $accent = '#D78B77';

        return [
            'slug' => 'intestine',
            'name' => 'Intestine',
            'scientific_name' => 'Intestinum',
            'body_system' => 'digestive',
            'accent_color' => $accent,
            'model_path' => 'models/intestine.glb',
            'thumbnail_path' => 'models/intestine.webp',
            'description' => 'The coiled tube running from the stomach to the rectum. The small '
                .'intestine measures about six metres in a cadaver and rather less in a living '
                .'body, where muscle tone holds it shorter; the large intestine adds roughly '
                .'1.5 m. Folds, villi and microvilli multiply the absorbing surface several '
                .'hundred times over the bare inside of the tube.',
            'structures' => [
                [
                    'slug' => 'duodenum',
                    'ta_term' => 'Duodenum',
                    'name' => 'Duodenum',
                    'description' => 'The first and shortest part of the small intestine, about '
                        .'25 cm long and curved in a C around the head of the pancreas. Most of '
                        .'it is fixed to the back wall of the abdomen rather than hanging free.',
                    'function' => 'Takes chyme from the stomach and neutralises its acid with '
                        .'bicarbonate. Bile and pancreatic enzymes arrive through the major '
                        .'duodenal papilla and start breaking down fat, protein and starch.',
                    'location' => 'Upper abdomen, from the pylorus round to the duodenojejunal '
                        .'flexure.',
                    'difficulty' => 1,
                    'anchor_position' => [0.60, 0.80, 0.75],
                    'marker_color' => '#F2B13C',
                ],
                [
                    'slug' => 'jejunum',
                    'ta_term' => 'Jejunum',
                    'name' => 'Jejunum',
                    'description' => 'The middle stretch of the small intestine, roughly the '
                        .'first two-fifths of the gut below the duodenum. Its wall is thicker '
                        .'and redder than the ileum\'s, with tall circular folds set close '
                        .'together.',
                    'function' => 'Absorbs most of the sugars, amino acids and fatty acids that '
                        .'digestion has released, across villi standing about 1 mm tall.',
                    'location' => 'Mainly the upper left of the abdomen, slung on the mesentery.',
                    'difficulty' => 2,
                    'anchor_position' => [-0.45, 0.10, 0.82],
                    'marker_color' => '#D34B4B',
                ],
                [
                    'slug' => 'ileum',
                    'ta_term' => 'Ileum',
                    'name' => 'Ileum',
                    'description' => 'The last and longest part of the small intestine, about '
                        .'three-fifths of the length below the duodenum. Its wall is thinner '
                        .'than the jejunum\'s and its lining carries Peyer\'s patches, clumps '
                        .'of lymphoid tissue large enough to see without a microscope.',
                    'function' => 'Finishes absorption and is the only stretch of gut that takes '
                        .'up vitamin B12 and recovers bile salts for the liver to reuse.',
                    'location' => 'Mainly the lower right of the abdomen, ending at the '
                        .'ileocaecal valve.',
                    'difficulty' => 2,
                    // Interpolated: the midpoint of the jejunum and colon anchors, which is
                    // where the ileum sits in the chain and keeps it on the same anterior
                    // surface band (z is 0.77, between their 0.82 and 0.72).
                    'anchor_position' => [0.15, -0.23, 0.77],
                    'marker_color' => '#C98BBE',
                ],
                [
                    'slug' => 'caecum',
                    'ta_term' => 'Caecum',
                    'name' => 'Caecum',
                    'description' => 'A blind-ended pouch about 6 cm long that forms the first '
                        .'part of the large intestine. It is wider than any other part of the '
                        .'gut, and the appendix hangs from its lower end.',
                    'function' => 'Receives everything the small intestine has finished with. '
                        .'The ileocaecal valve at its entrance lets material through in one '
                        .'direction only, so colonic bacteria are not pushed back up into the '
                        .'ileum.',
                    'location' => 'Lower right of the abdomen, below the point where the ileum '
                        .'opens in and directly below the start of the ascending colon.',
                    'difficulty' => 2,
                    // Offset from the ileum anchor: 0.72 inferior and slightly across, since
                    // the caecum is the pouch immediately downstream of it, and left 1.03
                    // clear of the colon anchor above. z 0.78 sits inside the narrow
                    // 0.72-0.82 anterior band all four existing anchors use.
                    // needs an authoring pass
                    'anchor_position' => [-0.20, -0.95, 0.78],
                    'marker_color' => '#6E8BB5',
                ],
                [
                    'slug' => 'appendix',
                    'ta_term' => 'Appendix vermiformis',
                    'name' => 'Vermiform Appendix',
                    'description' => 'A narrow blind tube, usually 5 to 10 cm long and about as '
                        .'thick as a pencil, opening off the caecum. Its wall carries a great '
                        .'deal of lymphoid tissue, and the position of its free end varies '
                        .'widely from person to person.',
                    'function' => 'Contributes lymphoid tissue to the gut\'s immune defences '
                        .'and is thought to act as a refuge for helpful gut bacteria that can '
                        .'repopulate the colon after an infection. When its opening blocks it '
                        .'swells and infects, which is appendicitis.',
                    'location' => 'Hangs from the lower end of the caecum in the lower right of '
                        .'the abdomen, most often tucked up behind it.',
                    'difficulty' => 3,
                    // Placed 0.53 below and across from the new caecum anchor, matching the
                    // way the appendix hangs off the caecum's lower end, and kept 1.32 from
                    // the ileum anchor so the three markers at this junction stay separable.
                    // needs an authoring pass
                    'anchor_position' => [-0.55, -1.35, 0.74],
                    'marker_color' => '#6E8BB5',
                ],
                [
                    'slug' => 'colon',
                    'ta_term' => 'Colon',
                    'name' => 'Colon',
                    'description' => 'The main length of the large intestine, about 1.5 m long '
                        .'and wider in bore than the small intestine. Its outer muscle is '
                        .'gathered into three bands, the taeniae coli, which pucker the wall '
                        .'into the pouches called haustra.',
                    'function' => 'Absorbs most of the water and salts left in the chyme and '
                        .'houses the gut bacteria; what remains is compacted and stored as '
                        .'faeces.',
                    'location' => 'Frames the small intestine — up the right side, across below '
                        .'the stomach, down the left side to the rectum.',
                    'difficulty' => 1,
                    'anchor_position' => [0.75, -0.55, 0.72],
                    'marker_color' => '#6E8BB5',
                ],
                [
                    'slug' => 'sigmoid-colon',
                    'ta_term' => 'Colon sigmoideum',
                    'name' => 'Sigmoid Colon',
                    'description' => 'The S-shaped last stretch of the colon, around 40 cm long. '
                        .'Unlike the ascending and descending colon it hangs on its own fold of '
                        .'mesentery, so it is mobile rather than pinned to the back wall.',
                    'function' => 'Holds faeces until defecation and drives them on into the '
                        .'rectum with strong contractions. Its narrow bore and firm contents '
                        .'make it the commonest place for diverticula — small pouches pushed '
                        .'out through weak points in the wall.',
                    'location' => 'Lower left of the abdomen, between the end of the descending '
                        .'colon and the start of the rectum.',
                    'difficulty' => 3,
                    // Continues the colon anchor 0.68 further down its own side of the model,
                    // which is the direction the descending colon runs, and stops 0.81 short
                    // of the new rectum anchor so the two ends of the chain stay distinct.
                    // z 0.72 matches the colon anchor exactly. needs an authoring pass
                    'anchor_position' => [0.95, -1.20, 0.72],
                    'marker_color' => '#6E8BB5',
                ],
                [
                    'slug' => 'rectum',
                    'ta_term' => 'Rectum',
                    'name' => 'Rectum',
                    'description' => 'The final 12 cm or so of the large intestine, running down '
                        .'in front of the sacrum to the anal canal. Despite its name it is not '
                        .'straight — it follows the curve of the bone behind it.',
                    'function' => 'Normally empty. When faeces are pushed in from the sigmoid '
                        .'colon, stretch receptors in the wall fire and produce the urge to go '
                        .'to the toilet; the external sphincter below it stays under conscious '
                        .'control until then.',
                    'location' => 'The lowest part of the gut, in the midline of the pelvis '
                        .'behind the bladder.',
                    'difficulty' => 1,
                    // Lowest anchor on the organ, on the midline between the appendix and
                    // sigmoid anchors (0.84 and 0.81 from them), because the rectum is the
                    // end of the chain and sits centrally. z 0.70 is the front edge of the
                    // band the existing anchors use. needs an authoring pass
                    'anchor_position' => [0.25, -1.60, 0.70],
                    'marker_color' => '#6E8BB5',
                ],
            ],
            'relations' => [
                ['duodenum', 'jejunum', StructureRelationType::FlowsInto],
                ['jejunum', 'ileum', StructureRelationType::FlowsInto],
                ['ileum', 'colon', StructureRelationType::FlowsInto],
                ['duodenum', 'colon', StructureRelationType::Adjacent],
                ['ileum', 'caecum', StructureRelationType::FlowsInto],
                ['caecum', 'colon', StructureRelationType::FlowsInto],
                ['appendix', 'caecum', StructureRelationType::PartOf],
                ['colon', 'sigmoid-colon', StructureRelationType::FlowsInto],
                ['sigmoid-colon', 'colon', StructureRelationType::PartOf],
                ['sigmoid-colon', 'rectum', StructureRelationType::FlowsInto],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function skin(): array
    {
        $accent = '#C99277';

        return [
            'slug' => 'skin',
            'name' => 'Skin',
            'scientific_name' => 'Integumentum',
            'body_system' => 'integumentary',
            'accent_color' => $accent,
            'model_path' => 'models/skin.glb',
            'thumbnail_path' => 'models/skin.webp',
            'description' => 'The largest organ in the body: about two square metres of sheet '
                .'over an adult, built in three layers stacked one on top of the next. The '
                .'epidermis is outermost, the dermis lies beneath it, and the fatty hypodermis '
                .'underneath ties the whole sheet to the muscle below. It is a barrier, a '
                .'thermostat and a sense organ at once.',
            'structures' => [
                [
                    'slug' => 'stratum-corneum',
                    'ta_term' => 'Stratum corneum',
                    'name' => 'Stratum Corneum',
                    'description' => 'The outermost sheet of the epidermis: 15 to 20 layers of '
                        .'dead, flattened cells stuffed with keratin and mortared together by '
                        .'lipids. Over most of the body it is only about 0.02 mm thick, but on '
                        .'the soles of the feet it is many times that.',
                    'function' => 'Is the actual waterproof barrier — the layer that stops the '
                        .'body drying out and stops most microbes and chemicals getting in. Its '
                        .'cells take roughly two weeks to travel through it and are then shed, '
                        .'so the barrier is constantly rebuilt from below.',
                    'location' => 'The very surface of the skin, the top of the epidermis, in '
                        .'contact with the air.',
                    'difficulty' => 3,
                    // This model is a block, not an organ: all four existing anchors sit at
                    // z = 1.40 on the front face and use y for depth, epidermis highest at
                    // 0.88 down to hypodermis at -1.15. Following that convention, the
                    // stratum corneum goes 0.62 directly above the epidermis anchor, since it
                    // is the top of that layer, and keeps z = 1.40. needs an authoring pass
                    'anchor_position' => [-0.05, 1.50, 1.40],
                    'marker_color' => '#D34B4B',
                ],
                [
                    'slug' => 'epidermis',
                    'ta_term' => 'Epidermis',
                    'name' => 'Epidermis',
                    'description' => 'The outermost layer, a stack of flattened cells about '
                        .'0.1 mm thick over most of the body and up to about 1 mm on the palms '
                        .'and soles. It holds no blood vessels and is fed by diffusion from the '
                        .'dermis below it.',
                    'function' => 'Builds the waterproof keratin barrier: cells divide in the '
                        .'basal layer, are pushed towards the surface, die and flake off, '
                        .'replacing the layer in roughly four weeks. Melanocytes in that same '
                        .'basal layer make the melanin that absorbs ultraviolet light.',
                    'location' => 'Outermost of the three layers, in contact with the air.',
                    'difficulty' => 1,
                    'anchor_position' => [-0.05, 0.88, 1.40],
                    'marker_color' => '#D34B4B',
                ],
                [
                    'slug' => 'dermis',
                    'ta_term' => 'Dermis',
                    'name' => 'Dermis',
                    'description' => 'The middle layer, roughly 1 to 4 mm thick and built from '
                        .'collagen and elastin fibres. Everything in the skin with a blood '
                        .'supply lives here: capillaries, nerve endings, sweat glands and the '
                        .'roots of hairs.',
                    'function' => 'Gives skin its toughness and its stretch, supplies the '
                        .'epidermis by diffusion, and carries the receptors for touch, '
                        .'pressure, temperature and pain. Widening or narrowing its blood '
                        .'vessels is how the body sheds or keeps heat.',
                    'location' => 'Directly beneath the epidermis and above the hypodermis.',
                    'difficulty' => 1,
                    'anchor_position' => [0.29, 0.05, 1.40],
                    'marker_color' => '#F2B13C',
                ],
                [
                    'slug' => 'sebaceous-gland',
                    'ta_term' => 'Glandula sebacea',
                    'name' => 'Sebaceous Gland',
                    'description' => 'A small sac of fat-filled cells opening into the side of a '
                        .'hair follicle. They are thickest on the face and scalp, where there '
                        .'are several hundred to a square centimetre, and absent from the palms '
                        .'and soles.',
                    'function' => 'Makes sebum, an oily mixture that keeps hair and the skin '
                        .'surface supple and slightly acidic, which discourages bacteria. The '
                        .'gland has no duct of its own: whole cells burst to release their '
                        .'contents. Androgens enlarge these glands at puberty, and a blocked '
                        .'one is the start of a spot.',
                    'location' => 'In the upper dermis, opening into the neck of a hair '
                        .'follicle rather than onto the surface directly.',
                    'difficulty' => 2,
                    // Same block convention: z stays at 1.40 and y encodes depth. Placed
                    // between the dermis and follicle anchors and raised 0.74 above the
                    // follicle towards the epidermis, because the gland opens into the upper
                    // part of the follicle, not at its base. needs an authoring pass
                    'anchor_position' => [1.15, 0.30, 1.40],
                    'marker_color' => '#C98BBE',
                ],
                [
                    'slug' => 'follicle',
                    'ta_term' => 'Folliculus pili',
                    'name' => 'Hair Follicle',
                    'description' => 'A narrow tube of epidermis pushed down into the dermis, '
                        .'with the hair growing out of a bulb at its base. A sebaceous gland '
                        .'opens into its side and a small arrector pili muscle attaches higher up.',
                    'function' => 'Grows the hair shaft from dividing cells in the bulb. The '
                        .'arrector pili pulls the follicle upright, raising the hair and '
                        .'producing goose bumps, while the sebaceous gland oils the hair and '
                        .'the surface around it.',
                    'location' => 'Set at a slant through the dermis and opening onto the '
                        .'surface; the bulbs of thick hairs reach down into the hypodermis.',
                    'difficulty' => 2,
                    'anchor_position' => [0.89, -0.44, 1.40],
                    'marker_color' => '#C98BBE',
                ],
                [
                    'slug' => 'sweat-gland',
                    'ta_term' => 'Glandula sudorifera',
                    'name' => 'Sweat Gland',
                    'description' => 'A tightly coiled tube in the deep dermis with a duct that '
                        .'spirals up through the epidermis to open at a pore. An adult carries '
                        .'somewhere between two and four million of them, most densely on the '
                        .'palms, soles and forehead.',
                    'function' => 'Cools the body. The gland pushes out a dilute salt solution '
                        .'that takes about 2,400 kJ of heat from the skin for every litre that '
                        .'evaporates; in hard exercise in the heat the output can exceed a '
                        .'litre an hour, which is why fluid has to be replaced.',
                    'location' => 'The coiled secretory part sits deep in the dermis, often at '
                        .'the border with the hypodermis; only the pore is visible at the '
                        .'surface.',
                    'difficulty' => 1,
                    // Same block convention: z stays at 1.40 and y encodes depth. Set at
                    // y = -0.30, between the dermis anchor at 0.05 and the hypodermis anchor
                    // at -1.15, which is the depth the coiled part occupies. Pushed 1.5 to
                    // the far side in x, away from the follicle and sebaceous anchors, so the
                    // deep-dermis markers do not crowd. needs an authoring pass
                    'anchor_position' => [-1.20, -0.30, 1.40],
                    'marker_color' => '#F2B13C',
                ],
                [
                    'slug' => 'hypodermis',
                    'ta_term' => 'Tela subcutanea',
                    'name' => 'Hypodermis',
                    'description' => 'A loose layer of connective tissue and lobules of fat '
                        .'below the dermis. Strictly it lies under the skin rather than being '
                        .'part of it, which is what its Latin name, subcutaneous tissue, says.',
                    'function' => 'Anchors the skin to the fascia over the muscles while still '
                        .'letting it slide, insulates against heat loss, cushions impacts and '
                        .'stores energy as fat.',
                    'location' => 'Deepest of the three layers, between the dermis and the '
                        .'muscle fascia.',
                    'difficulty' => 2,
                    'anchor_position' => [-0.39, -1.15, 1.40],
                    'marker_color' => '#6E8BB5',
                ],
                [
                    'slug' => 'pacinian-corpuscle',
                    'ta_term' => 'Corpusculum lamellosum',
                    'name' => 'Pacinian Corpuscle',
                    'description' => 'A touch receptor about 1 mm long — big enough to see with '
                        .'the naked eye — built as dozens of connective-tissue layers wrapped '
                        .'round a single nerve ending, like an onion.',
                    'function' => 'Detects deep pressure and vibration. Pressing on the layers '
                        .'deforms the nerve ending inside and it fires, but the layers then '
                        .'slide and relieve the strain, so it falls silent under steady '
                        .'pressure and responds only to change — it is most sensitive to '
                        .'vibration around 250 times a second.',
                    'location' => 'Deep in the dermis and in the hypodermis, most numerous in '
                        .'the fingertips and the soles of the feet.',
                    'difficulty' => 4,
                    // Same block convention: z stays at 1.40 and y encodes depth. The
                    // deepest of the new anchors at y = -1.45, just below the hypodermis
                    // anchor at -1.15 and 0.99 from it, since this receptor sits at the
                    // bottom of the dermis and into the fat. needs an authoring pass
                    'anchor_position' => [0.55, -1.45, 1.40],
                    'marker_color' => '#6E8BB5',
                ],
            ],
            'relations' => [
                ['epidermis', 'dermis', StructureRelationType::Adjacent],
                ['dermis', 'hypodermis', StructureRelationType::Adjacent],
                ['follicle', 'epidermis', StructureRelationType::PartOf],
                ['follicle', 'dermis', StructureRelationType::Adjacent],
                ['stratum-corneum', 'epidermis', StructureRelationType::PartOf],
                ['sebaceous-gland', 'follicle', StructureRelationType::Adjacent],
                ['sebaceous-gland', 'dermis', StructureRelationType::PartOf],
                ['sweat-gland', 'dermis', StructureRelationType::PartOf],
                ['pacinian-corpuscle', 'hypodermis', StructureRelationType::PartOf],
                ['pacinian-corpuscle', 'dermis', StructureRelationType::Adjacent],
            ],
        ];
    }
}
