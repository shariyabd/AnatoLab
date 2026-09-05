<?php

declare(strict_types=1);

use App\Enums\TopicType;
use App\Models\AnatomicalStructure;
use App\Models\Attempt;
use App\Models\BodySystem;
use App\Models\LearningMastery;
use App\Models\Organ;
use App\Models\Question;
use App\Models\User;
use App\Services\Progress\MasteryService;
use Carbon\CarbonImmutable;

/*
| Structure → organ → system, from real `attempts` rows.
|
| The calculator is tested from a table in tests/Unit; this is the other half —
| that the right attempts land in the right buckets, that the rollup is
| unweighted, and that a system's percentage is measured against the whole
| system rather than the part of it the student happened to open
| (docs/architecture.md §10).
|
| `attempts` is read and never written by this lane. Every row below is created
| through the factory that Handover 07 owns, in the shape that lane produces.
*/

function asOfNow(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-03-01 12:00:00');
}

/** One graded answer to a spatial question about `$structure`. */
function answer(User $student, AnatomicalStructure $structure, bool $correct, bool $hint = false): Attempt
{
    $question = Question::factory()->published()->spatial($structure)->create();

    $attempt = Attempt::factory()->for($student)->for($question)->create([
        'selected_structure_id' => $structure->getKey(),
        'hint_used' => $hint,
    ]);

    // `is_correct` is not fillable — the grader owns it (App\Models\Attempt).
    $attempt->is_correct = $correct;
    $attempt->save();

    return $attempt;
}

function masteryFor(User $student, TopicType $type, int $topicId): ?LearningMastery
{
    return LearningMastery::query()
        ->forUser($student)
        ->ofType($type)
        ->where('topic_id', $topicId)
        ->first();
}

beforeEach(function (): void {
    $this->service = app(MasteryService::class);
    $this->student = User::factory()->create();

    $this->system = BodySystem::factory()->create(['slug' => 'cardiovascular']);
    $this->heart = Organ::factory()->published()->for($this->system)->create(['slug' => 'heart']);

    $this->ventricle = AnatomicalStructure::factory()->published()->for($this->heart)->create();
    $this->atrium = AnatomicalStructure::factory()->published()->for($this->heart)->create();
});

it('writes a row at every level of the hierarchy', function (): void {
    answer($this->student, $this->ventricle, correct: true);

    $this->service->recalculate($this->student, asOfNow());

    expect(masteryFor($this->student, TopicType::Structure, (int) $this->ventricle->getKey()))
        ->not->toBeNull()
        ->and(masteryFor($this->student, TopicType::Organ, (int) $this->heart->getKey()))
        ->not->toBeNull()
        ->and(masteryFor($this->student, TopicType::System, (int) $this->system->getKey()))
        ->not->toBeNull();
});

it('attributes an attempt to the structure being tested, not the one picked', function (): void {
    // A wrong spatial answer: the question tests the ventricle, the student
    // clicked the atrium. Crediting the atrium would tell the student they have
    // met a structure they were mistaken about.
    $question = Question::factory()->published()->spatial($this->ventricle)->create();

    Attempt::factory()->for($this->student)->for($question)->create([
        'selected_structure_id' => $this->atrium->getKey(),
    ]);

    $this->service->recalculate($this->student, asOfNow());

    expect(masteryFor($this->student, TopicType::Structure, (int) $this->ventricle->getKey()))
        ->not->toBeNull()
        ->and(masteryFor($this->student, TopicType::Structure, (int) $this->atrium->getKey()))
        ->toBeNull();
});

it('rolls a structure-free attempt into its organ', function (): void {
    // A multiple-choice question about the organ as a whole. It has no
    // structure, so it contributes to the organ and to no structure topic.
    $question = Question::factory()->published()->create(['organ_id' => $this->heart->getKey()]);

    $attempt = Attempt::factory()->for($this->student)->for($question)->create();
    $attempt->is_correct = true;
    $attempt->save();

    $this->service->recalculate($this->student, asOfNow());

    $organ = masteryFor($this->student, TopicType::Organ, (int) $this->heart->getKey());

    expect($organ)->not->toBeNull()
        ->and($organ->attempts)->toBe(1)
        ->and($organ->covered_structures)->toBe(0)
        ->and(LearningMastery::query()->forUser($this->student)->ofType(TopicType::Structure)->count())
        ->toBe(0);
});

it('attributes an attempt with a structure but no question', function (): void {
    // The shape a mission step arrives in (Handover 09 writes `question_id`
    // null and a validated `selected_structure_id`). There is no branch for
    // missions anywhere in MasteryService — this is rule 2 of one uniform
    // attribution order.
    $attempt = Attempt::factory()->for($this->student)->create([
        'question_id' => null,
        'mission_attempt_id' => 42,
        'selected_structure_id' => $this->atrium->getKey(),
    ]);

    $attempt->is_correct = true;
    $attempt->save();

    $this->service->recalculate($this->student, asOfNow());

    expect(masteryFor($this->student, TopicType::Structure, (int) $this->atrium->getKey()))
        ->not->toBeNull()
        ->and(masteryFor($this->student, TopicType::Organ, (int) $this->heart->getKey()))
        ->not->toBeNull();
});

it('ignores an attempt it cannot attribute to any topic', function (): void {
    // A skipped mission step: no question, no structure. It stays in
    // `attempts` — this lane never writes that table — but there is nothing to
    // score it against.
    Attempt::factory()->for($this->student)->create([
        'question_id' => null,
        'selected_structure_id' => null,
    ]);

    $this->service->recalculate($this->student, asOfNow());

    expect(LearningMastery::query()->forUser($this->student)->count())->toBe(0)
        ->and(Attempt::query()->forUser($this->student)->count())->toBe(1);
});

it('does not let one heavily drilled structure carry an organ', function (): void {
    // Twelve perfect answers on the ventricle; four wrong on the atrium. A
    // count-weighted mean would put the organ near mastery.
    for ($i = 0; $i < 12; $i++) {
        answer($this->student, $this->ventricle, correct: true);
    }

    for ($i = 0; $i < 4; $i++) {
        answer($this->student, $this->atrium, correct: false);
    }

    $this->service->recalculate($this->student, asOfNow());

    $ventricle = masteryFor($this->student, TopicType::Structure, (int) $this->ventricle->getKey());
    $organ = masteryFor($this->student, TopicType::Organ, (int) $this->heart->getKey());

    expect($ventricle->score())->toBeGreaterThan(90.0)
        // Both structures covered, so coverage is 1; the gap is the unweighted
        // mean doing its job (docs/architecture.md §10).
        ->and($organ->score())->toBeLessThan(60.0);
});

it('measures a system against every organ in it, including untouched ones', function (): void {
    // A second organ in the same system, never opened. Its structures still
    // count toward the system's coverage denominator, or "mastered the
    // cardiovascular system" would mean "mastered one organ of two".
    $vessels = Organ::factory()->published()->for($this->system)->create(['slug' => 'vessels']);
    AnatomicalStructure::factory()->published()->count(3)->for($vessels)->create();

    answer($this->student, $this->ventricle, correct: true);
    answer($this->student, $this->atrium, correct: true);

    $this->service->recalculate($this->student, asOfNow());

    $organ = masteryFor($this->student, TopicType::Organ, (int) $this->heart->getKey());
    $system = masteryFor($this->student, TopicType::System, (int) $this->system->getKey());

    expect($organ->total_structures)->toBe(2)
        ->and($organ->covered_structures)->toBe(2)
        ->and($system->total_structures)->toBe(5)
        ->and($system->covered_structures)->toBe(2)
        // Full marks on the heart, but only two of the system's five
        // structures met, so the system sits well below the organ.
        ->and($system->score())->toBeLessThan($organ->score());
});

it('stores the counts the score was computed from', function (): void {
    answer($this->student, $this->ventricle, correct: true);
    answer($this->student, $this->ventricle, correct: false, hint: true);

    $this->service->recalculate($this->student, asOfNow());

    $structure = masteryFor($this->student, TopicType::Structure, (int) $this->ventricle->getKey());

    expect($structure->attempts)->toBe(2)
        ->and($structure->correct_attempts)->toBe(1)
        ->and($structure->hinted_attempts)->toBe(1)
        ->and($structure->last_activity_at)->not->toBeNull();
});

it('is idempotent', function (): void {
    answer($this->student, $this->ventricle, correct: true);

    $this->service->recalculate($this->student, asOfNow());
    $first = masteryFor($this->student, TopicType::Organ, (int) $this->heart->getKey());

    $this->service->recalculate($this->student, asOfNow());
    $second = masteryFor($this->student, TopicType::Organ, (int) $this->heart->getKey());

    // Same rows, not doubled ones — which is what makes a retried job safe.
    expect(LearningMastery::query()->forUser($this->student)->count())->toBe(3)
        ->and($second->getKey())->toBe($first->getKey())
        ->and($second->score())->toBe($first->score());
});

it('drops a row whose attempts are gone', function (): void {
    $attempt = answer($this->student, $this->ventricle, correct: true);

    $this->service->recalculate($this->student, asOfNow());
    expect(LearningMastery::query()->forUser($this->student)->count())->toBe(3);

    $attempt->delete();
    $this->service->recalculate($this->student, asOfNow());

    // A row nobody recomputes is a score that never moves again.
    expect(LearningMastery::query()->forUser($this->student)->count())->toBe(0);
});

it('scopes every row to the student it was computed for', function (): void {
    $other = User::factory()->create();

    answer($this->student, $this->ventricle, correct: true);
    answer($other, $this->atrium, correct: false);

    $this->service->recalculate($this->student, asOfNow());

    expect(LearningMastery::query()->forUser($other)->count())->toBe(0)
        ->and(LearningMastery::query()->forUser($this->student)->count())->toBe(3);
});
