<?php

declare(strict_types=1);

use App\Models\AnatomicalStructure;
use App\Models\KnowledgeDocument;
use App\Models\Lesson;
use App\Models\Mission;
use App\Models\Organ;
use App\Models\Question;
use App\Models\User;
use App\Policies\AnatomicalStructurePolicy;
use App\Policies\KnowledgeDocumentPolicy;
use App\Policies\LessonPolicy;
use App\Policies\MissionPolicy;
use App\Policies\OrganPolicy;
use App\Policies\QuestionPolicy;

/*
| The policies, called directly — no route, no middleware, no HTTP.
|
| docs/handovers/13-admin-content.md requires exactly this: "Policy enforced
| independently of middleware (test the policy directly)". A test that only
| drove the routes would pass just as happily if every policy returned true and
| EnsureUserIsAdmin were doing all the work, which is the failure mode
| docs/architecture.md §14 names — middleware alone is not authorization.
|
| Model instances are unsaved. These policies answer from the actor's role, so
| there is nothing to persist, and constructing the subject without touching the
| database keeps the assertion about authorization rather than about factories.
*/

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->make();
    $this->student = User::factory()->make();
});

/**
 * Every (policy, ability) pair in the admin surface, with the subject each
 * ability takes. Table-driven so adding an ability to a policy without adding
 * it here is visible as a gap rather than as silence.
 *
 * @return list<array{0: object, 1: string, 2: list<mixed>}>
 */
function adminAbilities(): array
{
    $organ = new Organ;
    $structure = new AnatomicalStructure;
    $lesson = new Lesson;
    $question = new Question;
    $mission = new Mission;
    $document = new KnowledgeDocument;

    return [
        [new OrganPolicy, 'viewAny', []],
        [new OrganPolicy, 'view', [$organ]],
        [new OrganPolicy, 'create', []],
        [new OrganPolicy, 'update', [$organ]],
        [new OrganPolicy, 'publish', [$organ]],
        [new OrganPolicy, 'delete', [$organ]],

        [new AnatomicalStructurePolicy, 'viewAny', []],
        [new AnatomicalStructurePolicy, 'view', [$structure]],
        [new AnatomicalStructurePolicy, 'create', []],
        [new AnatomicalStructurePolicy, 'update', [$structure]],
        [new AnatomicalStructurePolicy, 'author', [$structure]],
        [new AnatomicalStructurePolicy, 'publish', [$structure]],
        [new AnatomicalStructurePolicy, 'delete', [$structure]],

        [new LessonPolicy, 'viewAny', []],
        [new LessonPolicy, 'view', [$lesson]],
        [new LessonPolicy, 'create', []],
        [new LessonPolicy, 'update', [$lesson]],
        [new LessonPolicy, 'publish', [$lesson]],
        [new LessonPolicy, 'delete', [$lesson]],

        [new QuestionPolicy, 'viewAny', []],
        [new QuestionPolicy, 'view', [$question]],
        [new QuestionPolicy, 'viewAnswerKey', [$question]],
        [new QuestionPolicy, 'create', []],
        [new QuestionPolicy, 'update', [$question]],
        [new QuestionPolicy, 'review', [$question]],
        [new QuestionPolicy, 'publish', [$question]],
        [new QuestionPolicy, 'delete', [$question]],

        [new MissionPolicy, 'viewAny', []],
        [new MissionPolicy, 'view', [$mission]],
        [new MissionPolicy, 'viewTargetSequence', [$mission]],
        [new MissionPolicy, 'create', []],
        [new MissionPolicy, 'update', [$mission]],
        [new MissionPolicy, 'publish', [$mission]],
        [new MissionPolicy, 'delete', [$mission]],

        [new KnowledgeDocumentPolicy, 'viewAny', []],
        [new KnowledgeDocumentPolicy, 'view', [$document]],
        [new KnowledgeDocumentPolicy, 'create', []],
        [new KnowledgeDocumentPolicy, 'update', [$document]],
        [new KnowledgeDocumentPolicy, 'reingest', [$document]],
        [new KnowledgeDocumentPolicy, 'delete', [$document]],
    ];
}

it('grants every admin ability to an admin', function (): void {
    foreach (adminAbilities() as [$policy, $ability, $subject]) {
        expect($policy->{$ability}($this->admin, ...$subject))
            ->toBeTrue($policy::class."::{$ability}() denied an admin");
    }
});

it('denies every admin ability to a student', function (): void {
    foreach (adminAbilities() as [$policy, $ability, $subject]) {
        expect($policy->{$ability}($this->student, ...$subject))
            ->toBeFalse($policy::class."::{$ability}() allowed a student");
    }
});

/*
| The two exceptions to invariant 4 get their own named test.
|
| An answer key and a mission target sequence are the only correctness data the
| product ever renders, and this lane is the only place that renders them. If
| either of these abilities ever returns true for a student, invariant 4 is
| broken product-wide, so it is asserted by name rather than only inside the
| table above.
*/

it('never shows a question answer key to a student', function (): void {
    expect((new QuestionPolicy)->viewAnswerKey($this->student, new Question))->toBeFalse();
});

it('never shows a mission target sequence to a student', function (): void {
    expect((new MissionPolicy)->viewTargetSequence($this->student, new Mission))->toBeFalse();
});

it('covers every public ability each admin policy declares', function (): void {
    // Guards the table above: a policy that grows an ability nobody added here
    // would otherwise be untested and silently permissive.
    $policies = [
        OrganPolicy::class,
        AnatomicalStructurePolicy::class,
        LessonPolicy::class,
        QuestionPolicy::class,
        MissionPolicy::class,
        KnowledgeDocumentPolicy::class,
    ];

    $tested = [];

    foreach (adminAbilities() as [$policy, $ability]) {
        $tested[$policy::class][$ability] = true;
    }

    foreach ($policies as $policy) {
        $declared = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass($policy))->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        foreach ($declared as $ability) {
            expect($tested[$policy] ?? [])->toHaveKey($ability, "{$policy}::{$ability}() is untested");
        }
    }
});
