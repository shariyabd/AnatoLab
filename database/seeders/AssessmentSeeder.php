<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Models\AnatomicalStructure;
use App\Models\Organ;
use App\Models\Question;
use App\Models\QuestionOption;
use Illuminate\Database\Seeder;

/**
 * The demo question bank (docs/handovers/07-assessment-engine.md).
 *
 * Content, not code. Three things about this data are load-bearing:
 *
 * 1. **Spatial questions name their answer by structure slug**, resolved
 *    against AnatomySeeder's rows. Slugs are unique per organ, not globally —
 *    `apex` means one thing in the heart and another in the lungs — so every
 *    lookup is scoped to the organ (App\Services\Anatomy\AnatomyService).
 *
 * 2. **The correct MCQ option is not always in the same position.** A quiz
 *    whose answer is always first teaches position, not anatomy, and it would
 *    make `QuestionOptionResource`'s answer-key test pass for the wrong
 *    reason.
 *
 * 3. **Idempotent by (organ, question text) and (question, option value).**
 *    `db:seed` twice produces the same database, not doubled rows
 *    (docs/engineering.md §6). Questions have no slug of their own, so the
 *    prose is the natural key; option `value` exists precisely so an option is
 *    addressable without depending on an auto-increment id.
 *
 * Every question here is `published`, and one per organ is deliberately left
 * in `review` so that the "a question awaiting review never reaches a student"
 * test has real seeded data to assert against rather than only factory rows.
 */
final class AssessmentSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->questions() as $organSlug => $definitions) {
            $organ = Organ::query()->where('slug', $organSlug)->first();

            // The anatomy seeder owns the organs. If one is missing, the demo
            // dataset is already broken elsewhere and inventing an organ here
            // would hide it.
            if (! $organ instanceof Organ) {
                continue;
            }

            foreach ($definitions as $definition) {
                $this->seedQuestion($organ, $definition);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function seedQuestion(Organ $organ, array $definition): void
    {
        /** @var QuestionType $type */
        $type = $definition['type'];

        /** @var string|null $answerSlug */
        $answerSlug = $definition['answer'] ?? null;

        $correctStructure = $answerSlug === null
            ? null
            : AnatomicalStructure::query()
                ->where('organ_id', $organ->getKey())
                ->where('slug', $answerSlug)
                ->first();

        // A spatial question with no resolvable answer is unanswerable. Skip it
        // rather than seed a question every student gets wrong.
        if ($type === QuestionType::Spatial && ! $correctStructure instanceof AnatomicalStructure) {
            return;
        }

        /** @var array<string, mixed> $metadata */
        $metadata = $definition['metadata'] ?? [];

        $question = Question::query()->updateOrCreate(
            [
                'organ_id' => $organ->getKey(),
                'question' => $definition['question'],
            ],
            [
                // Null throughout: `lessons` belongs to Handover 06, and these
                // are organ-wide questions rather than lesson checkpoints.
                'lesson_id' => null,
                'type' => $type,
                'difficulty' => $definition['difficulty'],
                'explanation' => $definition['explanation'],
                'correct_structure_id' => $correctStructure?->getKey(),
                'metadata' => $metadata,
                'status' => $definition['status'] ?? QuestionStatus::Published,
                'generated_by_ai' => false,
            ],
        );

        /** @var array<int, array{value: string, label: string, correct?: bool}> $options */
        $options = $definition['options'] ?? [];

        foreach ($options as $option) {
            QuestionOption::query()->updateOrCreate(
                [
                    'question_id' => $question->getKey(),
                    'value' => $option['value'],
                ],
                [
                    'label' => $option['label'],
                    'is_correct' => $option['correct'] ?? false,
                ],
            );
        }
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function questions(): array
    {
        return [
            'heart' => $this->heart(),
            'lungs' => $this->lungs(),
            'brain' => $this->brain(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function heart(): array
    {
        return [
            [
                'type' => QuestionType::Spatial,
                'question' => 'Find the chamber that pumps oxygenated blood into the aorta.',
                'answer' => 'left-ventricle',
                'difficulty' => 1,
                'explanation' => 'The left ventricle is the thickest-walled chamber because it '
                    .'has to push blood around the whole body, not just to the lungs.',
                'metadata' => ['hint' => 'It is the chamber with the thickest muscular wall.'],
            ],
            [
                'type' => QuestionType::Spatial,
                'question' => 'Find the vessel that returns deoxygenated blood from the head and arms.',
                'answer' => 'superior-vena-cava',
                'difficulty' => 2,
                'explanation' => 'The superior vena cava drains the upper body into the right '
                    .'atrium. Its inferior counterpart drains everything below the diaphragm.',
            ],
            [
                'type' => QuestionType::Spatial,
                'question' => 'Find the valve between the left atrium and the left ventricle.',
                'answer' => 'mitral-valve',
                'difficulty' => 3,
                'explanation' => 'The mitral valve has two cusps, which is why it is also called '
                    .'the bicuspid valve. It stops blood washing back into the left atrium when '
                    .'the ventricle contracts.',
                'metadata' => ['hint' => 'It is the only heart valve with two cusps rather than three.'],
            ],
            [
                'type' => QuestionType::Mcq,
                'question' => 'Which chamber receives oxygenated blood returning from the lungs?',
                'difficulty' => 1,
                'explanation' => 'Blood returns from the lungs through the pulmonary veins into '
                    .'the left atrium, then drops through the mitral valve into the left ventricle.',
                'options' => [
                    ['value' => 'right-atrium', 'label' => 'The right atrium'],
                    ['value' => 'left-atrium', 'label' => 'The left atrium', 'correct' => true],
                    ['value' => 'right-ventricle', 'label' => 'The right ventricle'],
                    ['value' => 'left-ventricle', 'label' => 'The left ventricle'],
                ],
            ],
            [
                'type' => QuestionType::Mcq,
                'question' => 'Where does the pulmonary trunk carry blood to?',
                'difficulty' => 2,
                'explanation' => 'The pulmonary trunk is the one artery in the body that carries '
                    .'deoxygenated blood: it leaves the right ventricle for the lungs.',
                'options' => [
                    ['value' => 'lungs', 'label' => 'The lungs', 'correct' => true],
                    ['value' => 'body', 'label' => 'The rest of the body'],
                    ['value' => 'heart-muscle', 'label' => 'The heart muscle itself'],
                    ['value' => 'liver', 'label' => 'The liver'],
                ],
            ],
            [
                'type' => QuestionType::ShortAnswer,
                'question' => 'Name the chamber whose wall is thickest, and say why.',
                'difficulty' => 3,
                'explanation' => 'The left ventricle. It generates the pressure that drives blood '
                    .'through the systemic circulation, which is a far longer circuit than the '
                    .'pulmonary one.',
                'metadata' => [
                    'rubric' => [
                        'accepted' => ['left ventricle'],
                        'required' => ['ventricle'],
                    ],
                ],
            ],
            [
                'type' => QuestionType::Mcq,
                'question' => 'Which structure marks the point where the heartbeat is most easily felt?',
                'difficulty' => 2,
                'explanation' => 'The apex sits closest to the chest wall, which is why the apex '
                    .'beat is the one a stethoscope picks up most clearly.',
                // Left awaiting review on purpose: it must never reach a student.
                'status' => QuestionStatus::Review,
                'options' => [
                    ['value' => 'apex', 'label' => 'The apex of the heart', 'correct' => true],
                    ['value' => 'aorta', 'label' => 'The aorta'],
                    ['value' => 'carina', 'label' => 'The right atrium'],
                ],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function lungs(): array
    {
        return [
            [
                'type' => QuestionType::Spatial,
                'question' => 'Find the ridge where the trachea divides into the two main bronchi.',
                'answer' => 'carina',
                'difficulty' => 2,
                'explanation' => 'The carina is the ridge of cartilage at the tracheal bifurcation. '
                    .'It is unusually sensitive: touching it triggers the cough reflex.',
                'metadata' => ['hint' => 'Follow the airway down until it splits in two.'],
            ],
            [
                'type' => QuestionType::Spatial,
                'question' => 'Find the airway that an inhaled object is most likely to fall into.',
                'answer' => 'right-main-bronchus',
                'difficulty' => 3,
                'explanation' => 'The right main bronchus is wider and more vertical than the '
                    .'left, so gravity favours it. This is why inhaled foreign bodies usually end '
                    .'up in the right lung.',
            ],
            [
                'type' => QuestionType::Spatial,
                'question' => 'Find the lobe that does most of the right lung\'s gas exchange.',
                'answer' => 'right-inferior-lobe',
                'difficulty' => 3,
                'explanation' => 'The right inferior lobe is the largest of the three and holds '
                    .'the greatest share of alveolar surface.',
            ],
            [
                'type' => QuestionType::Mcq,
                'question' => 'How many lobes does the right lung have?',
                'difficulty' => 1,
                'explanation' => 'Three on the right, two on the left — the left gives up a lobe '
                    .'to make room for the heart.',
                'options' => [
                    ['value' => 'two', 'label' => 'Two'],
                    ['value' => 'three', 'label' => 'Three', 'correct' => true],
                    ['value' => 'four', 'label' => 'Four'],
                    ['value' => 'five', 'label' => 'Five'],
                ],
            ],
            [
                'type' => QuestionType::Mcq,
                'question' => 'Why does the left lung have one fewer lobe than the right?',
                'difficulty' => 2,
                'explanation' => 'The heart sits slightly left of the midline, and the left lung '
                    .'is notched around it.',
                'options' => [
                    ['value' => 'smaller-chest', 'label' => 'The left side of the chest is shorter'],
                    ['value' => 'heart', 'label' => 'The heart takes up the space', 'correct' => true],
                    ['value' => 'less-air', 'label' => 'It needs to move less air'],
                ],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function brain(): array
    {
        return [
            [
                'type' => QuestionType::Spatial,
                'question' => 'Find the lobe that processes what you see.',
                'answer' => 'occipital-lobe',
                'difficulty' => 1,
                'explanation' => 'The occipital lobe sits at the back of the brain, furthest from '
                    .'the eyes — the signal travels the length of the head to get there.',
                'metadata' => ['hint' => 'It is at the very back of the brain.'],
            ],
            [
                'type' => QuestionType::Spatial,
                'question' => 'Find the structure that keeps movement smooth and balanced.',
                'answer' => 'cerebellum',
                'difficulty' => 2,
                'explanation' => 'The cerebellum does not start movements; it corrects them while '
                    .'they happen, which is why damage to it makes movement clumsy rather than '
                    .'impossible.',
            ],
            [
                'type' => QuestionType::Spatial,
                'question' => 'Find the structure that controls breathing and heart rate.',
                'answer' => 'medulla-oblongata',
                'difficulty' => 3,
                'explanation' => 'The medulla oblongata runs breathing, heart rate and blood '
                    .'pressure without any conscious involvement, which is why injury here is so '
                    .'dangerous.',
            ],
            [
                'type' => QuestionType::Mcq,
                'question' => 'Which lobe is most involved in planning and decision-making?',
                'difficulty' => 1,
                'explanation' => 'The frontal lobe handles planning, judgement and voluntary '
                    .'movement. It is also the last part of the brain to finish developing.',
                'options' => [
                    ['value' => 'occipital', 'label' => 'The occipital lobe'],
                    ['value' => 'temporal', 'label' => 'The temporal lobe'],
                    ['value' => 'frontal', 'label' => 'The frontal lobe', 'correct' => true],
                    ['value' => 'parietal', 'label' => 'The parietal lobe'],
                ],
            ],
            [
                'type' => QuestionType::ShortAnswer,
                'question' => 'Which lobe would you expect to be affected if someone could no '
                    .'longer understand speech?',
                'difficulty' => 3,
                'explanation' => 'The temporal lobe. It holds the auditory cortex and, on the '
                    .'dominant side, the region that makes speech meaningful rather than merely '
                    .'audible.',
                'metadata' => [
                    'rubric' => ['accepted' => ['temporal']],
                ],
            ],
        ];
    }
}
