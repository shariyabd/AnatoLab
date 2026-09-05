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
 * The three MVP organs and everything labelled on them.
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
 * 2. `model_path` is a PLACEHOLDER. public/models/manifest.json is
 *    `"status": "pending-licence"` with an empty `models` array, because the
 *    upstream GLBs carry no licence at all (docs/licence-log.md §3,
 *    docs/asset-register.md §2). When the licence gate closes, F02 publishes
 *    real filenames and the swap is one commit touching only the `model_path`
 *    values below — no schema change, no API change.
 *
 * 3. `model_object_name` is null on every row, deliberately. The audited assets
 *    are a single mesh with one node named `tripo_node_<uuid>`; there is no
 *    per-structure geometry to name (docs/project-context.md §2.2).
 *
 * The English prose is written here rather than migrated from the upstream
 * repo's `app/i18n/organs/en.ts`, which is AI-generated and unverified
 * (docs/project-context.md §5.4). Anchor coordinates are authored, not
 * measured: the models they describe are not in this repository yet, so they
 * are plausible placements awaiting a pass through F04's authoring mode.
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
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function organs(): array
    {
        return [$this->heart(), $this->lungs(), $this->brain()];
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
}
