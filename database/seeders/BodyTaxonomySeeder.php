<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ModelFormat;
use App\Enums\OrganStatus;
use App\Models\BodySystem;
use App\Models\Organ;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The eleven body systems and the full organ roadmap, as unpublished rows.
 *
 * Content, not code (docs/handovers/16-full-body-coverage.md). The decisions
 * behind every row — which four entries are not single organs, why the count is
 * 43 rather than 60, and what is deliberately not duplicated as an organ — are
 * in docs/organ-taxonomy.md. Read that before adding or removing a row here;
 * this file is its executable half, not its author.
 *
 * Four things about this data are load-bearing:
 *
 * 1. Every row is `draft`. A taxonomy row exists so coverage is visible and
 *    honest before a model is encoded, and `AnatomyService` filters drafts out
 *    of both student-facing reads. Nothing here reaches a student.
 *
 * 2. `model_path` is `models/pending/<slug>.glb` and no such file exists. The
 *    column is NOT NULL because types.ts types `OrganDto.modelUrl` as a plain
 *    string, so a draft still needs a value; the `pending/` segment is what
 *    says the value is a placeholder rather than a manifest row.
 *
 * 3. No structures are seeded. Handover 16 forbids placeholder structures to
 *    satisfy a count, and a coordinate is only meaningful in the pivot space of
 *    a model that does not exist yet. Structures are authored through F13's
 *    hotspot tool against a real model.
 *
 * 4. Create-only, never update. `firstOrCreate` rather than `updateOrCreate` is
 *    the difference between re-seeding being idempotent and re-seeding
 *    demoting a published organ back to a draft pointing at a placeholder path.
 *    An organ that has since been given a model and published is left alone.
 *
 * Runs after AnatomySeeder and depends on it: the seven systems F03 established
 * are required rather than redeclared, so their descriptions live in exactly one
 * place. The dependency is asserted loudly (see `requireSystem`) rather than
 * discovered as a foreign key error.
 */
final class BodyTaxonomySeeder extends Seeder
{
    /**
     * Slugs AnatomySeeder owns.
     *
     * Checked rather than seeded. If F03's published set drifts, this seeder
     * fails with a sentence instead of quietly creating a second `heart` as a
     * draft pointing at a placeholder model.
     *
     * @var list<string>
     */
    private const PUBLISHED_ELSEWHERE = [
        'heart', 'lungs', 'brain', 'liver', 'kidneys',
        'pancreas', 'intestine', 'eyeball', 'skin',
    ];

    public function run(): void
    {
        foreach ($this->bodySystems() as $system) {
            BodySystem::query()->updateOrCreate(['slug' => $system['slug']], $system);
        }

        $this->assertPublishedSetIntact();

        foreach ($this->organs() as $organ) {
            $this->seedTaxonomyOrgan($organ);
        }
    }

    /**
     * The four systems F16 adds.
     *
     * Musculoskeletal is one system rather than skeletal and muscular as two,
     * because this platform already carries a `sensory` system that the
     * conventional eleven folds into the nervous system. The reasoning is in
     * docs/organ-taxonomy.md §2 and the count is eleven either way.
     *
     * @return list<array{slug: string, name: string, description: string}>
     */
    private function bodySystems(): array
    {
        return [
            [
                'slug' => 'musculoskeletal',
                'name' => 'Musculoskeletal System',
                'description' => 'The bones, joints, muscles, tendons and ligaments, which '
                    .'together give the body its shape, protect its organs and turn muscle '
                    .'contraction into movement.',
            ],
            [
                'slug' => 'endocrine',
                'name' => 'Endocrine System',
                'description' => 'The glands that release hormones straight into the blood, '
                    .'setting the pace of growth, metabolism, the stress response and the '
                    .'reproductive cycle.',
            ],
            [
                'slug' => 'lymphatic',
                'name' => 'Lymphatic and Immune System',
                'description' => 'The vessels, nodes and lymphoid organs that drain fluid from '
                    .'the tissues, return it to the blood, and hold the white cells that meet '
                    .'infection.',
            ],
            [
                'slug' => 'reproductive',
                'name' => 'Reproductive System',
                'description' => 'The organs that produce gametes and sex hormones, and in which '
                    .'fertilisation and pregnancy take place.',
            ],
        ];
    }

    /**
     * @return list<array{slug: string, name: string, scientific_name: string, body_system: string, description: string}>
     */
    private function organs(): array
    {
        return [
            ...$this->cardiovascularOrgans(),
            ...$this->respiratoryOrgans(),
            ...$this->nervousOrgans(),
            ...$this->sensoryOrgans(),
            ...$this->digestiveOrgans(),
            ...$this->urinaryOrgans(),
            ...$this->endocrineOrgans(),
            ...$this->lymphaticOrgans(),
            ...$this->musculoskeletalOrgans(),
            ...$this->reproductiveOrgans(),
        ];
    }

    /**
     * The vessels get one organ rather than being folded into the heart
     * (docs/organ-taxonomy.md §3.1). The heart already carries four great
     * vessels at their root; the circulation is taught as a circuit, not as an
     * appendage of the pump.
     *
     * @return list<array{slug: string, name: string, scientific_name: string, body_system: string, description: string}>
     */
    private function cardiovascularOrgans(): array
    {
        return [
            [
                'slug' => 'vascular-system',
                'name' => 'Vascular System',
                'scientific_name' => 'Vasa sanguinea',
                'body_system' => 'cardiovascular',
                'description' => 'The closed circuit of arteries, capillaries and veins that '
                    .'carries blood away from the heart, through every tissue, and back again. '
                    .'Arteries leave under pressure and have thick muscular walls; veins return '
                    .'at low pressure and carry valves to stop backflow; the exchange itself '
                    .'happens only in the capillaries, whose walls are one cell thick.',
            ],
        ];
    }

    /**
     * @return list<array{slug: string, name: string, scientific_name: string, body_system: string, description: string}>
     */
    private function respiratoryOrgans(): array
    {
        return [
            [
                'slug' => 'larynx',
                'name' => 'Larynx',
                'scientific_name' => 'Larynx',
                'body_system' => 'respiratory',
                'description' => 'The cartilage box at the top of the trachea that holds the '
                    .'vocal folds. It seals the airway during swallowing and turns exhaled air '
                    .'into sound.',
            ],
            [
                'slug' => 'diaphragm',
                'name' => 'Diaphragm',
                'scientific_name' => 'Diaphragma',
                'body_system' => 'respiratory',
                'description' => 'The dome of muscle separating the chest from the abdomen, and '
                    .'the main muscle of breathing. It flattens as it contracts, enlarging the '
                    .'chest and drawing air in.',
            ],
        ];
    }

    /**
     * @return list<array{slug: string, name: string, scientific_name: string, body_system: string, description: string}>
     */
    private function nervousOrgans(): array
    {
        return [
            [
                'slug' => 'spinal-cord',
                'name' => 'Spinal Cord',
                'scientific_name' => 'Medulla spinalis',
                'body_system' => 'nervous',
                'description' => 'The cable of nervous tissue running from the brainstem down '
                    .'inside the vertebral column. It carries every signal between the brain and '
                    .'the body below the neck, and closes reflex arcs without waiting for the '
                    .'brain to be consulted.',
            ],
            [
                'slug' => 'peripheral-nerves',
                'name' => 'Peripheral Nerves',
                'scientific_name' => 'Systema nervosum periphericum',
                'body_system' => 'nervous',
                'description' => 'The nerves that leave the brain and spinal cord and reach the '
                    .'rest of the body — sensory fibres carrying information in, motor fibres '
                    .'carrying instructions out.',
            ],
        ];
    }

    /**
     * @return list<array{slug: string, name: string, scientific_name: string, body_system: string, description: string}>
     */
    private function sensoryOrgans(): array
    {
        return [
            [
                'slug' => 'ear',
                'name' => 'Ear',
                'scientific_name' => 'Auris',
                'body_system' => 'sensory',
                'description' => 'Three regions in one organ: an outer ear that gathers sound, a '
                    .'middle ear whose three tiny bones amplify it, and an inner ear where the '
                    .'cochlea converts vibration into nerve impulses and the semicircular canals '
                    .'report the position of the head.',
            ],
            [
                'slug' => 'nose',
                'name' => 'Nose',
                'scientific_name' => 'Nasus',
                'body_system' => 'sensory',
                'description' => 'The entrance to the airway and the organ of smell. Its lining '
                    .'warms, moistens and filters inhaled air, and the olfactory epithelium in '
                    .'its roof detects airborne molecules.',
            ],
            [
                'slug' => 'tongue',
                'name' => 'Tongue',
                'scientific_name' => 'Lingua',
                'body_system' => 'sensory',
                'description' => 'A muscular organ carrying the taste buds. It moves food around '
                    .'the mouth during chewing, starts the swallow, and shapes the sounds of '
                    .'speech.',
            ],
        ];
    }

    /**
     * @return list<array{slug: string, name: string, scientific_name: string, body_system: string, description: string}>
     */
    private function digestiveOrgans(): array
    {
        return [
            [
                'slug' => 'stomach',
                'name' => 'Stomach',
                'scientific_name' => 'Gaster',
                'body_system' => 'digestive',
                'description' => 'A muscular bag between the oesophagus and the duodenum. It '
                    .'stores a meal, churns it, and mixes it with acid and pepsin until it '
                    .'leaves as chyme through the pyloric sphincter.',
            ],
            [
                'slug' => 'esophagus',
                'name' => 'Oesophagus',
                'scientific_name' => 'Oesophagus',
                'body_system' => 'digestive',
                'description' => 'The muscular tube carrying swallowed food from the pharynx to '
                    .'the stomach. Food is driven down by peristalsis rather than by gravity, '
                    .'which is why swallowing works upside down.',
            ],
            [
                'slug' => 'salivary-glands',
                'name' => 'Salivary Glands',
                'scientific_name' => 'Glandulae salivariae',
                'body_system' => 'digestive',
                'description' => 'Three paired glands — parotid, submandibular and sublingual — '
                    .'that release saliva into the mouth. Saliva lubricates food, protects the '
                    .'teeth, and begins starch digestion with amylase.',
            ],
        ];
    }

    /**
     * @return list<array{slug: string, name: string, scientific_name: string, body_system: string, description: string}>
     */
    private function urinaryOrgans(): array
    {
        return [
            [
                'slug' => 'urinary-bladder',
                'name' => 'Urinary Bladder',
                'scientific_name' => 'Vesica urinaria',
                'body_system' => 'urinary',
                'description' => 'The muscular reservoir that collects urine arriving from both '
                    .'ureters and holds it until it is convenient to empty. Its wall stretches '
                    .'as it fills and contracts to void.',
            ],
        ];
    }

    /**
     * @return list<array{slug: string, name: string, scientific_name: string, body_system: string, description: string}>
     */
    private function endocrineOrgans(): array
    {
        return [
            [
                'slug' => 'thyroid-gland',
                'name' => 'Thyroid Gland',
                'scientific_name' => 'Glandula thyroidea',
                'body_system' => 'endocrine',
                'description' => 'A butterfly-shaped gland wrapped around the front of the '
                    .'trachea. Its hormones set the resting metabolic rate of nearly every cell '
                    .'in the body.',
            ],
            [
                'slug' => 'adrenal-glands',
                'name' => 'Adrenal Glands',
                'scientific_name' => 'Glandulae suprarenales',
                'body_system' => 'endocrine',
                'description' => 'A pair of glands capping the kidneys. The outer cortex releases '
                    .'cortisol and aldosterone; the inner medulla releases adrenaline, which is '
                    .'what a fright feels like.',
            ],
            [
                'slug' => 'pituitary-gland',
                'name' => 'Pituitary Gland',
                'scientific_name' => 'Hypophysis',
                'body_system' => 'endocrine',
                'description' => 'A pea-sized gland hanging beneath the brain in its own pocket '
                    .'of bone. It takes instructions from the hypothalamus and passes them on as '
                    .'the hormones that direct most of the other endocrine glands.',
            ],
            [
                'slug' => 'parathyroid-glands',
                'name' => 'Parathyroid Glands',
                'scientific_name' => 'Glandulae parathyroideae',
                'body_system' => 'endocrine',
                'description' => 'Four glands the size of grains of rice embedded in the back of '
                    .'the thyroid. They hold blood calcium within the narrow range nerves and '
                    .'muscle need to work.',
            ],
            [
                'slug' => 'pineal-gland',
                'name' => 'Pineal Gland',
                'scientific_name' => 'Glandula pinealis',
                'body_system' => 'endocrine',
                'description' => 'A small gland deep in the centre of the brain. It releases '
                    .'melatonin in darkness and is the body\'s main link between daylight and '
                    .'the sleep–wake cycle.',
            ],
        ];
    }

    /**
     * Three discrete lymphoid organs plus one network organ. Lymph nodes are
     * hundreds of distributed structures: one is not a teaching unit and all of
     * them are not an organ, so they become structures on `lymphatic-system`
     * (docs/organ-taxonomy.md §3.3).
     *
     * @return list<array{slug: string, name: string, scientific_name: string, body_system: string, description: string}>
     */
    private function lymphaticOrgans(): array
    {
        return [
            [
                'slug' => 'spleen',
                'name' => 'Spleen',
                'scientific_name' => 'Splen',
                'body_system' => 'lymphatic',
                'description' => 'The largest lymphoid organ, sitting behind the stomach on the '
                    .'left. Its red pulp filters worn-out red cells out of the blood; its white '
                    .'pulp is lymphoid tissue that meets blood-borne infection.',
            ],
            [
                'slug' => 'thymus',
                'name' => 'Thymus',
                'scientific_name' => 'Thymus',
                'body_system' => 'lymphatic',
                'description' => 'A gland behind the sternum where T lymphocytes mature and learn '
                    .'not to attack the body\'s own tissue. It is largest in childhood and is '
                    .'slowly replaced by fat in adult life.',
            ],
            [
                'slug' => 'tonsils',
                'name' => 'Tonsils',
                'scientific_name' => 'Tonsillae',
                'body_system' => 'lymphatic',
                'description' => 'A ring of lymphoid tissue guarding the entrance to the throat, '
                    .'where the airway and the food passage meet. It samples what is inhaled and '
                    .'swallowed and is one of the first places infection is met.',
            ],
            [
                'slug' => 'lymphatic-system',
                'name' => 'Lymphatic System',
                'scientific_name' => 'Systema lymphoideum',
                'body_system' => 'lymphatic',
                'description' => 'The one-way drainage network. Fluid that has leaked out of the '
                    .'capillaries is collected as lymph, filtered through nodes clustered in the '
                    .'neck, armpit, groin and gut, and returned to the bloodstream through the '
                    .'thoracic duct.',
            ],
        ];
    }

    /**
     * Regional models, never a whole articulated skeleton — one asset far over
     * the 2 MB / 150k-triangle budget (docs/architecture.md §15.1). Bone,
     * muscle, tendon and ligament are tissue classes, so they are represented as
     * regions a student is actually examined on, with tendons and ligaments as
     * structures on the joint and the hand (docs/organ-taxonomy.md §3.2).
     *
     * @return list<array{slug: string, name: string, scientific_name: string, body_system: string, description: string}>
     */
    private function musculoskeletalOrgans(): array
    {
        return [
            [
                'slug' => 'skull',
                'name' => 'Skull',
                'scientific_name' => 'Cranium',
                'body_system' => 'musculoskeletal',
                'description' => 'Twenty-two bones, all but the mandible locked together at '
                    .'immovable sutures. The braincase protects the brain; the facial skeleton '
                    .'carries the eyes, the airway and the teeth.',
            ],
            [
                'slug' => 'rib-cage',
                'name' => 'Rib Cage',
                'scientific_name' => 'Cavea thoracis',
                'body_system' => 'musculoskeletal',
                'description' => 'Twelve pairs of ribs, the sternum and the thoracic vertebrae, '
                    .'forming a cage that protects the heart and lungs and moves with every '
                    .'breath.',
            ],
            [
                'slug' => 'vertebral-column',
                'name' => 'Vertebral Column',
                'scientific_name' => 'Columna vertebralis',
                'body_system' => 'musculoskeletal',
                'description' => 'Thirty-three vertebrae in five regions, stacked with cartilage '
                    .'discs between them. The column carries the body\'s weight, allows it to '
                    .'bend, and encloses the spinal cord.',
            ],
            [
                'slug' => 'pelvis',
                'name' => 'Pelvis',
                'scientific_name' => 'Pelvis',
                'body_system' => 'musculoskeletal',
                'description' => 'The bony ring joining the spine to the legs. It transfers the '
                    .'weight of the trunk into the hip joints and supports the bladder and the '
                    .'reproductive organs.',
            ],
            [
                'slug' => 'hand',
                'name' => 'Hand',
                'scientific_name' => 'Manus',
                'body_system' => 'musculoskeletal',
                'description' => 'Twenty-seven bones, with the muscles that move them mostly in '
                    .'the forearm and reaching the fingers through long tendons. The opposable '
                    .'thumb is what makes a grip precise rather than merely strong.',
            ],
            [
                'slug' => 'knee-joint',
                'name' => 'Knee Joint',
                'scientific_name' => 'Articulatio genus',
                'body_system' => 'musculoskeletal',
                'description' => 'The largest joint in the body, and the clearest place to see '
                    .'how a joint is built: cartilage surfaces, two menisci, cruciate and '
                    .'collateral ligaments holding it together, and the patellar tendon '
                    .'transmitting the pull of the thigh.',
            ],
            [
                'slug' => 'biceps-brachii',
                'name' => 'Biceps Brachii',
                'scientific_name' => 'Musculus biceps brachii',
                'body_system' => 'musculoskeletal',
                'description' => 'The two-headed muscle on the front of the upper arm. It bends '
                    .'the elbow and turns the palm upwards, and is the standard example of a '
                    .'skeletal muscle working against its antagonist, the triceps.',
            ],
        ];
    }

    /**
     * In scope, per handover 16 and the PRD's 13-18 audience. Treated exactly
     * like every other system: clinical framing, TA terms, the same panel
     * layout, no euphemism, no special-casing, and the same `is_published` gate.
     *
     * @return list<array{slug: string, name: string, scientific_name: string, body_system: string, description: string}>
     */
    private function reproductiveOrgans(): array
    {
        return [
            [
                'slug' => 'uterus',
                'name' => 'Uterus',
                'scientific_name' => 'Uterus',
                'body_system' => 'reproductive',
                'description' => 'A thick-walled muscular organ in the pelvis. Its lining is '
                    .'rebuilt and shed on a monthly cycle, and it houses and nourishes a '
                    .'developing fetus during pregnancy.',
            ],
            [
                'slug' => 'ovaries',
                'name' => 'Ovaries',
                'scientific_name' => 'Ovaria',
                'body_system' => 'reproductive',
                'description' => 'A pair of glands either side of the uterus. They hold the egg '
                    .'cells present from birth, release one at each ovulation, and produce '
                    .'oestrogen and progesterone.',
            ],
            [
                'slug' => 'uterine-tubes',
                'name' => 'Uterine Tubes',
                'scientific_name' => 'Tubae uterinae',
                'body_system' => 'reproductive',
                'description' => 'The pair of tubes running from near each ovary to the uterus. '
                    .'Fertilisation normally happens here, and cilia move the egg along toward '
                    .'the uterus.',
            ],
            [
                'slug' => 'vagina',
                'name' => 'Vagina',
                'scientific_name' => 'Vagina',
                'body_system' => 'reproductive',
                'description' => 'The muscular canal running from the cervix to the outside. It '
                    .'is the passage for menstrual flow, for intercourse, and for the birth of a '
                    .'baby.',
            ],
            [
                'slug' => 'testes',
                'name' => 'Testes',
                'scientific_name' => 'Testes',
                'body_system' => 'reproductive',
                'description' => 'A pair of glands held in the scrotum, outside the body cavity '
                    .'because sperm production needs a temperature slightly below core. They '
                    .'produce sperm and testosterone.',
            ],
            [
                'slug' => 'prostate-gland',
                'name' => 'Prostate Gland',
                'scientific_name' => 'Prostata',
                'body_system' => 'reproductive',
                'description' => 'A gland the size of a walnut sitting below the bladder and '
                    .'surrounding the urethra. Its secretions make up part of semen and help '
                    .'sperm stay mobile.',
            ],
            [
                'slug' => 'penis',
                'name' => 'Penis',
                'scientific_name' => 'Penis',
                'body_system' => 'reproductive',
                'description' => 'The organ carrying the urethra to the outside, serving both '
                    .'urination and the delivery of semen. Its erectile tissue fills with blood '
                    .'under nervous control.',
            ],
        ];
    }

    /**
     * Accent per system, not per organ.
     *
     * The nine published organs carry hand-picked accents from F03. A draft
     * carries its system's and gets its own when there is a model and a colour
     * pass to pick it against — an invented per-organ accent would be a value
     * nobody chose, sitting in the database looking chosen.
     */
    private function accentForSystem(string $systemSlug): string
    {
        return match ($systemSlug) {
            'cardiovascular' => '#E5484D',
            'respiratory' => '#3E9EDB',
            'nervous' => '#8E5BD9',
            'digestive' => '#B86858',
            'urinary' => '#C96963',
            'sensory' => '#7294B9',
            'integumentary' => '#C99277',
            'musculoskeletal' => '#9A8E7A',
            'endocrine' => '#C98A3E',
            'lymphatic' => '#6FA98C',
            'reproductive' => '#B76E8F',
            default => throw new RuntimeException("No accent colour is defined for the `{$systemSlug}` system."),
        };
    }

    /**
     * @param  array{slug: string, name: string, scientific_name: string, body_system: string, description: string}  $definition
     */
    private function seedTaxonomyOrgan(array $definition): void
    {
        $system = $this->requireSystem($definition['body_system']);

        Organ::query()->firstOrCreate(
            ['slug' => $definition['slug']],
            [
                'body_system_id' => $system->getKey(),
                'name' => $definition['name'],
                'scientific_name' => $definition['scientific_name'],
                'description' => $definition['description'],
                'model_path' => "models/pending/{$definition['slug']}.glb",
                'model_format' => ModelFormat::Glb,
                'thumbnail_path' => null,
                'accent_color' => $this->accentForSystem($definition['body_system']),
                'status' => OrganStatus::Draft,
            ],
        );
    }

    /**
     * The seven systems F03 established are required, not redeclared.
     */
    private function requireSystem(string $slug): BodySystem
    {
        $system = BodySystem::query()->where('slug', $slug)->first();

        if (! $system instanceof BodySystem) {
            throw new RuntimeException(
                "The `{$slug}` body system is missing. BodyTaxonomySeeder extends the set "
                .'AnatomySeeder establishes and must run after it (database/seeders/DatabaseSeeder.php).'
            );
        }

        return $system;
    }

    /**
     * Fail loudly if F03's published set has drifted.
     *
     * Without this check, renaming `eyeball` to `eye` upstream would leave this
     * seeder happily creating a second, draft `eyeball` pointing at a
     * placeholder model — a duplicate organ nobody asked for and nobody would
     * notice until it appeared in the admin library.
     */
    private function assertPublishedSetIntact(): void
    {
        $missing = array_values(array_diff(
            self::PUBLISHED_ELSEWHERE,
            Organ::query()->whereIn('slug', self::PUBLISHED_ELSEWHERE)->pluck('slug')->all(),
        ));

        if ($missing !== []) {
            throw new RuntimeException(
                'BodyTaxonomySeeder expects AnatomySeeder to have seeded these organs, and they '
                .'are absent: '.implode(', ', $missing).'. Either it has not run, or F03\'s '
                .'published set has changed and docs/organ-taxonomy.md §4 needs re-deciding.'
            );
        }
    }
}
