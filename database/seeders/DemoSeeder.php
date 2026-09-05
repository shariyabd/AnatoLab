<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\KnowledgeSourceType;
use App\Models\AnatomicalStructure;
use App\Models\KnowledgeDocument;
use App\Models\Lesson;
use App\Models\Mission;
use App\Models\Organ;
use App\Models\Question;
use App\Models\Simulation;
use App\Services\Rag\KnowledgeDocumentData;
use App\Services\Rag\KnowledgeService;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The demo dataset (docs/handovers/14-demo-polish.md, "Demo dataset").
 *
 * PRD §44 is walked by a judge on a database that was empty five minutes ago,
 * so every step of it has to exist after one `migrate:fresh --seed`. This
 * seeder is what makes that true, and it does two different jobs to do it.
 *
 * **It asserts, it does not duplicate.** The heart, the blood-circulation
 * lesson, the spatial question on the left ventricle, the Trace the Blood
 * mission and the mitral-valve simulation are all owned by other lanes'
 * seeders. Re-creating them here would give the demo a second copy that drifts
 * from the one the rest of the application reads. So this seeder resolves each
 * one and throws if it is missing: a demo whose spine is broken must fail the
 * `migrate:fresh --seed` step of the verify gate, not be discovered on stage.
 *
 * **It seeds the knowledge corpus.** That part is genuinely absent otherwise —
 * `DatabaseSeeder` still carries an unfilled `F11 → KnowledgeSeeder` slot, so a
 * freshly seeded database has no indexed passage and the tutor can only answer
 * ungrounded. PRD §44 asks for a *grounded* answer, so the demo has to ship the
 * sources its own question is answered from.
 *
 * Three things about the corpus are load-bearing:
 *
 * 1. **It goes through the real ingest pipeline.** `KnowledgeService::storeText`
 *    → `ProcessKnowledgeDocument` → `GenerateEmbeddings` → `SyncVectorStore`,
 *    the same path an admin upload takes (docs/architecture.md §8.3). Writing
 *    rows straight into `knowledge_chunks` would seed a corpus that proves
 *    nothing about whether ingest works.
 *
 * 2. **Every passage is tagged for retrieval.** The tutor filters on
 *    `organ_id`, `structure_id` and `education_level` exactly
 *    (`AITutorService::retrieveGrounding`), so a passage that is not tagged
 *    with the structure on screen is a passage the demo question will never
 *    retrieve. The left-ventricle material is authored at all three education
 *    levels for the same reason: the level is chosen at registration, and a
 *    judge who picks "Middle school" must still get a citation.
 *
 * 3. **The prose is ours.** The corpus is under the same licence gate as the
 *    3D assets (PRD §42, docs/licence-log.md), so every passage below is
 *    original course material written for this application rather than an
 *    extract from a textbook we have no right to redistribute.
 *
 * Idempotent by document title, like every other seeder here: `db:seed` twice
 * produces the same database, not a doubled corpus (docs/engineering.md §6).
 */
final class DemoSeeder extends Seeder
{
    /**
     * The journey's spine, as (label => resolver) pairs.
     *
     * Named by the PRD step they serve so a failure message says which part of
     * the demo is missing rather than which table is empty.
     */
    public function run(): void
    {
        $organ = $this->requireOrgan('heart');
        $structures = $this->requireStructures($organ, [
            'right-atrium',
            'right-ventricle',
            'left-atrium',
            'left-ventricle',
            'aorta',
            'pulmonary-trunk',
            'superior-vena-cava',
            'mitral-valve',
        ]);

        $this->requireLesson('blood-circulation');
        $this->requireMission('trace-the-blood');
        $this->requireSimulation('mitral-valve-closure');
        $this->requireSpatialQuestionOn($structures['left-ventricle']);

        $this->seedCorpus($organ, $structures);
    }

    /*
    |---------------------------------------------------------------------------
    | The corpus
    |---------------------------------------------------------------------------
    */

    /**
     * @param  array<string, AnatomicalStructure>  $structures
     */
    private function seedCorpus(Organ $organ, array $structures): void
    {
        $organId = (int) $organ->getKey();

        foreach ($this->passages() as $passage) {
            $structure = $passage['structure'] === null
                ? null
                : $structures[$passage['structure']];

            $this->ingest(
                title: $passage['title'],
                text: $passage['text'],
                data: new KnowledgeDocumentData(
                    title: $passage['title'],
                    source: $passage['source'],
                    sourceType: $passage['sourceType'],
                    organId: $organId,
                    structureId: $structure?->getKey(),
                    educationLevel: $passage['level'],
                    contentType: $passage['contentType'],
                ),
            );
        }
    }

    /**
     * Run one document through the ingest chain, synchronously.
     *
     * `storeText` hands the document to the `ingest` queue and returns, which
     * is right for a web request and wrong for a seeder: `migrate:fresh --seed`
     * would finish with every document still `pending` and the demo would have
     * no citations until somebody happened to run a worker. Forcing the sync
     * connection for the duration runs the same three jobs in the same order,
     * in-process, so the seeder returns with the corpus actually indexed.
     */
    private function ingest(string $title, string $text, KnowledgeDocumentData $data): void
    {
        if (KnowledgeDocument::query()->where('title', $title)->exists()) {
            return;
        }

        $connection = config('queue.default');

        config(['queue.default' => 'sync']);

        try {
            app(KnowledgeService::class)->storeText($text, $data);
        } finally {
            config(['queue.default' => $connection]);
        }
    }

    /*
    |---------------------------------------------------------------------------
    | Spine assertions
    |---------------------------------------------------------------------------
    |
    | Each one names the PRD §44 step it protects. A missing row here is a
    | broken demo, and a broken demo is a failed build (docs/engineering.md §6).
    |
    */

    private function requireOrgan(string $slug): Organ
    {
        $organ = Organ::query()->where('slug', $slug)->first();

        if (! $organ instanceof Organ) {
            throw new RuntimeException(
                "The demo journey needs the `{$slug}` organ (PRD §44, \"open an organ\"). "
                .'AnatomySeeder did not produce it.'
            );
        }

        return $organ;
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, AnatomicalStructure>
     */
    private function requireStructures(Organ $organ, array $slugs): array
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, AnatomicalStructure> $found */
        $found = $organ->structures()->whereIn('slug', $slugs)->get();

        $resolved = $found->keyBy('slug')->all();

        $missing = array_values(array_diff($slugs, array_keys($resolved)));

        if ($missing !== []) {
            throw new RuntimeException(sprintf(
                'The demo journey needs these %s structures (PRD §44, "select an anatomical '
                .'structure"), and AnatomySeeder did not produce them: %s.',
                $organ->slug,
                implode(', ', $missing),
            ));
        }

        return $resolved;
    }

    private function requireLesson(string $slug): void
    {
        if (! Lesson::query()->where('slug', $slug)->exists()) {
            throw new RuntimeException(
                "The demo journey needs the `{$slug}` lesson (PRD §44, \"start a lesson\"). "
                .'LessonSeeder did not produce it.'
            );
        }
    }

    private function requireMission(string $slug): void
    {
        if (! Mission::query()->where('slug', $slug)->exists()) {
            throw new RuntimeException(
                "The demo journey needs the `{$slug}` mission (PRD §44, \"complete a mission\"). "
                .'MissionSeeder did not produce it.'
            );
        }
    }

    private function requireSimulation(string $slug): void
    {
        if (! Simulation::query()->where('slug', $slug)->exists()) {
            throw new RuntimeException(
                "The demo journey needs the `{$slug}` simulation (PRD §2.3 step 7). "
                .'SimulationSeeder did not produce it.'
            );
        }
    }

    /**
     * PRD §44's "complete a 3D spatial question" — and specifically one whose
     * answer is the structure the demo has just selected, so the judge answers
     * the question they were reading about.
     */
    private function requireSpatialQuestionOn(AnatomicalStructure $structure): void
    {
        $exists = Question::query()
            ->where('type', 'spatial')
            ->where('correct_structure_id', $structure->getKey())
            ->exists();

        if (! $exists) {
            throw new RuntimeException(
                "The demo journey needs a published spatial question answered by `{$structure->slug}` "
                .'(PRD §44, "complete a 3D spatial question"). AssessmentSeeder did not produce one.'
            );
        }
    }

    /*
    |---------------------------------------------------------------------------
    | Content
    |---------------------------------------------------------------------------
    */

    /**
     * @return list<array{
     *     title: string,
     *     source: string,
     *     sourceType: KnowledgeSourceType,
     *     structure: string|null,
     *     level: string,
     *     contentType: string,
     *     text: string
     * }>
     */
    private function passages(): array
    {
        return [
            /*
            | PRD §2.3 step 6 and §39: "why is the left ventricular wall
            | thicker?". This is the passage the demo answer is cited from, so
            | it exists at all three reading levels.
            */
            [
                'title' => 'Why the left ventricle has the thickest wall (high school)',
                'source' => 'AnatoLab course notes — Cardiovascular system, unit 2',
                'sourceType' => KnowledgeSourceType::Curriculum,
                'structure' => 'left-ventricle',
                'level' => 'high_school',
                'contentType' => 'function',
                'text' => <<<'TEXT'
                    The four chambers of the heart do not have walls of the same thickness. The two
                    atria have thin walls, the right ventricle has a wall of moderate thickness, and
                    the left ventricle has a wall roughly three times thicker than the right
                    ventricle's. That difference is not decoration; it follows directly from the job
                    each chamber does.

                    A chamber's wall thickness matches the pressure it has to generate. The atria
                    only move blood a few centimetres into the ventricle below them, largely with the
                    help of gravity and the ventricle's own relaxation, so they need very little
                    muscle. The right ventricle pumps blood into the pulmonary circuit — out to the
                    lungs and back — which is a short, low-resistance loop, and it does that at a
                    peak pressure of roughly 25 mmHg.

                    The left ventricle pumps into the systemic circuit: up to the brain, down to the
                    feet, and through every organ in between. That circuit is long and its vessels
                    are far more resistant to flow, so the left ventricle has to develop a peak
                    pressure of about 120 mmHg to move the same volume of blood through it. Pressure
                    is force per unit area, and the force comes from cardiac muscle contracting, so
                    the only way to raise the pressure is to put more muscle in the wall.

                    Both ventricles eject the same volume with every beat — they have to, or blood
                    would pile up in one circuit. The left ventricle is not moving more blood than
                    the right. It is moving the same blood against about five times the resistance,
                    and its wall is thick because that is what generating five times the pressure
                    costs.
                    TEXT,
            ],
            [
                'title' => 'Why the left ventricle has the thickest wall (middle school)',
                'source' => 'AnatoLab course notes — Cardiovascular system, unit 2',
                'sourceType' => KnowledgeSourceType::Curriculum,
                'structure' => 'left-ventricle',
                'level' => 'middle_school',
                'contentType' => 'function',
                'text' => <<<'TEXT'
                    If you could hold a heart and press on its four chambers, they would not all feel
                    the same. The two upper chambers are thin and soft. The lower right chamber is
                    firmer. The lower left chamber, the left ventricle, is the thickest and strongest
                    of all.

                    The reason is distance. The right ventricle only has to push blood to the lungs,
                    which sit right next to the heart. That is a short trip, so it does not need much
                    strength.

                    The left ventricle has to push blood everywhere else: all the way up to your
                    brain, all the way down to your toes, and out to every muscle and organ on the
                    way. That is a much longer trip, and the blood has to be pushed hard enough to
                    complete it.

                    Muscle is what does the pushing. A thicker wall means more muscle, and more
                    muscle means a harder push. So the chamber with the longest journey ahead of it
                    is the chamber with the thickest wall. Both lower chambers send out the same
                    amount of blood each beat — the left one just has to send it much further.
                    TEXT,
            ],
            [
                'title' => 'Ventricular wall thickness and afterload (advanced)',
                'source' => 'AnatoLab course notes — Cardiovascular system, unit 2',
                'sourceType' => KnowledgeSourceType::Curriculum,
                'structure' => 'left-ventricle',
                'level' => 'advanced',
                'contentType' => 'function',
                'text' => <<<'TEXT'
                    Left ventricular wall thickness is best understood as an adaptation to afterload
                    rather than to volume. Stroke volume is necessarily matched between the two
                    ventricles over time; what differs is the pressure each must generate to deliver
                    it.

                    Pulmonary vascular resistance is low, and right ventricular systolic pressure is
                    typically around 25 mmHg. Systemic vascular resistance is roughly five times
                    higher, and left ventricular systolic pressure is typically around 120 mmHg. The
                    left ventricle therefore performs several times the pressure–volume work per beat
                    for the same ejected volume.

                    The relationship between wall thickness and generated pressure is captured by the
                    law of Laplace. For a thick-walled chamber, wall stress is proportional to
                    internal pressure multiplied by chamber radius and divided by wall thickness.
                    Holding radius roughly constant, generating a higher internal pressure without
                    raising wall stress requires a proportionally greater thickness. Concentric
                    hypertrophy is the physiological expression of that relationship.

                    The same relationship explains the pathological case. In sustained systemic
                    hypertension or aortic stenosis, afterload rises and the ventricle thickens
                    further to normalise wall stress. The adaptation is initially compensatory, but a
                    thickened, stiffer ventricle fills less readily during diastole, which is why
                    concentric hypertrophy is associated with diastolic dysfunction. This is
                    educational background on how the structure responds to load; it is not clinical
                    guidance.
                    TEXT,
            ],

            /*
            | Organ-level material: retrieved when the tutor is asked about the
            | heart with no structure selected, and the grounding behind the
            | blood-circulation lesson and the Trace the Blood mission.
            */
            [
                'title' => 'The path blood takes through the heart',
                'source' => 'AnatoLab course notes — Cardiovascular system, unit 1',
                'sourceType' => KnowledgeSourceType::Curriculum,
                'structure' => null,
                'level' => 'high_school',
                'contentType' => 'definition',
                'text' => <<<'TEXT'
                    Blood passes through the heart twice on every complete circuit of the body, and
                    the two passes are kept entirely separate by the wall between the left and right
                    sides.

                    Deoxygenated blood returns from the body through the superior and inferior venae
                    cavae and enters the right atrium. It passes through the tricuspid valve into the
                    right ventricle. The right ventricle contracts and drives it through the
                    pulmonary valve into the pulmonary trunk, which divides into the left and right
                    pulmonary arteries and carries the blood to the lungs. These are the only
                    arteries in the body that carry deoxygenated blood.

                    In the lungs the blood releases carbon dioxide and takes up oxygen. It returns
                    through the pulmonary veins — the only veins carrying oxygenated blood — into the
                    left atrium. It passes through the mitral valve into the left ventricle. The left
                    ventricle contracts and drives it through the aortic valve into the aorta, and
                    from there to every tissue in the body.

                    Two rules make the whole path easier to remember. Arteries always carry blood
                    away from the heart and veins always carry it back, regardless of how oxygenated
                    it is. And blood always moves atrium to ventricle to artery on each side, never
                    backwards, because a valve sits at each of those junctions.
                    TEXT,
            ],

            /*
            | PRD §2.3 step 7's simulation. Grounding for "what happens if the
            | mitral valve does not close".
            */
            [
                'title' => 'What the mitral valve does, and what happens when it leaks',
                'source' => 'AnatoLab course notes — Cardiovascular system, unit 3',
                'sourceType' => KnowledgeSourceType::Curriculum,
                'structure' => 'mitral-valve',
                'level' => 'high_school',
                'contentType' => 'function',
                'text' => <<<'TEXT'
                    The mitral valve sits between the left atrium and the left ventricle. It has two
                    flaps, which is why it is also called the bicuspid valve, and each flap is
                    tethered from below by fibrous cords attached to small muscles in the ventricle
                    wall. Those cords do not pull the valve shut; they stop it from being pushed
                    inside out when the ventricle contracts.

                    The valve's job is one-way flow. While the ventricle relaxes, pressure in the
                    atrium is higher and the flaps swing open so blood can fill the ventricle. When
                    the ventricle contracts, pressure below the valve rises sharply, the flaps are
                    pushed together and sealed, and the only exit left for the blood is forward
                    through the aortic valve.

                    If the valve does not seal properly, some blood is driven backwards into the left
                    atrium each time the ventricle contracts. That has three consequences worth
                    tracing. Less blood leaves through the aorta, so the volume reaching the body per
                    beat falls. Pressure and volume build up in the left atrium, and behind it in the
                    pulmonary veins. And the left ventricle has to handle both the returning blood
                    and the normal filling volume on the next beat, so it works harder for less
                    delivered output.

                    This is educational background on how the structure works. It is not a
                    description of any individual's condition and not clinical advice.
                    TEXT,
            ],
        ];
    }
}
