<?php

declare(strict_types=1);

use App\Enums\LessonStatus;
use App\Enums\LessonStepType;
use App\Models\AnatomicalStructure;
use App\Models\Lesson;
use App\Models\Organ;
use Database\Seeders\AnatomySeeder;
use Database\Seeders\LessonSeeder;

/*
| The seeded lesson content (docs/handovers/06-lessons.md, acceptance 4).
|
| The demo is a first-class artefact (docs/engineering.md §6): F14's release
| gate walks the blood-circulation lesson end to end on a cold database, so
| these assertions are a contract with that lane, not housekeeping.
*/

beforeEach(function (): void {
    $this->seed(AnatomySeeder::class);
    $this->seed(LessonSeeder::class);
});

it('seeds between five and ten published lessons', function (): void {
    $lessons = Lesson::query()->published()->get();

    expect($lessons->count())->toBeGreaterThanOrEqual(5)->toBeLessThanOrEqual(10);
});

it('seeds the blood-circulation lesson F14 depends on', function (): void {
    $lesson = Lesson::query()->where('slug', 'blood-circulation')->first();

    expect($lesson)->not->toBeNull()
        ->and($lesson->status)->toBe(LessonStatus::Published)
        ->and($lesson->organ->slug)->toBe('heart')
        ->and($lesson->objective)->toBe('Understand how blood moves through the heart.');
});

it('gives the blood-circulation lesson the full PRD §8 shape', function (): void {
    $lesson = Lesson::query()->where('slug', 'blood-circulation')->sole();

    /** @var list<array<string, mixed>> $steps */
    $steps = $lesson->content['steps'];
    $types = array_column($steps, 'type');

    expect($types)->toBe([
        LessonStepType::Objective->value,
        LessonStepType::Exploration->value,
        LessonStepType::Explanation->value,
        LessonStepType::Activity->value,
        LessonStepType::KnowledgeCheck->value,
        LessonStepType::Reflection->value,
    ]);
});

it('traces the blood-flow path PRD §8 names', function (): void {
    $lesson = Lesson::query()->where('slug', 'blood-circulation')->sole();

    /** @var list<array<string, mixed>> $steps */
    $steps = $lesson->content['steps'];
    $activity = collect($steps)->firstWhere('type', LessonStepType::Activity->value);

    expect($activity['payload']['structures'])->toContain(
        'right-atrium',
        'right-ventricle',
        'pulmonary-trunk',
        'left-atrium',
        'left-ventricle',
        'aorta',
    );
});

it('spreads lessons across all three MVP organs', function (): void {
    $organSlugs = Lesson::query()->published()->with('organ')->get()
        ->map(fn (Lesson $lesson): string => $lesson->organ->slug)
        ->unique()
        ->sort()
        ->values()
        ->all();

    expect($organSlugs)->toBe(['brain', 'heart', 'lungs']);
});

it('names only structures that exist on the lesson\'s own organ', function (): void {
    // A typo here degrades to a step that highlights nothing rather than to an
    // error, so nothing else would catch it (LessonSeeder note 4).
    $unresolved = [];

    foreach (Lesson::query()->with('organ')->get() as $lesson) {
        $known = AnatomicalStructure::query()
            ->where('organ_id', $lesson->organ_id)
            ->pluck('slug')
            ->all();

        /** @var list<array<string, mixed>> $steps */
        $steps = $lesson->content['steps'];

        foreach ($steps as $step) {
            /** @var list<string> $named */
            $named = $step['payload']['structures'] ?? [];

            foreach ($named as $slug) {
                if (! in_array($slug, $known, true)) {
                    $unresolved[] = "{$lesson->slug} → {$slug}";
                }
            }
        }
    }

    expect($unresolved)->toBe([]);
});

it('gives every seeded lesson a published organ, an objective and an estimate', function (): void {
    foreach (Lesson::query()->with('organ')->get() as $lesson) {
        expect($lesson->organ)->toBeInstanceOf(Organ::class)
            ->and($lesson->objective)->not->toBe('')
            ->and($lesson->estimated_minutes)->toBeGreaterThan(0)
            ->and($lesson->content['steps'])->not->toBeEmpty();
    }
});

it('never puts an answer in a knowledge-check step', function (): void {
    // F07 owns the question and its grading; this lane owns its placement
    // (invariant 4, docs/handovers/06-lessons.md "Out of scope").
    foreach (Lesson::query()->get() as $lesson) {
        /** @var list<array<string, mixed>> $steps */
        $steps = $lesson->content['steps'];

        foreach ($steps as $step) {
            if ($step['type'] !== LessonStepType::KnowledgeCheck->value) {
                continue;
            }

            expect(array_keys($step['payload']))->toBe(['prompt', 'reference']);
        }
    }
});

it('is idempotent', function (): void {
    $before = Lesson::query()->count();

    $this->seed(LessonSeeder::class);

    expect(Lesson::query()->count())->toBe($before);
});
