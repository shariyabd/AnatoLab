<?php

declare(strict_types=1);

use App\Enums\AchievementCriterion;
use App\Models\Achievement;
use Database\Seeders\AchievementSeeder;

/*
| The badge catalogue is content, and content is seeded, so it gets the same
| treatment every other seeder in this repo gets: it must be idempotent, and
| every criterion it writes must be one the evaluator understands
| (docs/engineering.md §6).
*/

beforeEach(function (): void {
    $this->seed(AchievementSeeder::class);
});

it('seeds the badge catalogue', function (): void {
    expect(Achievement::query()->count())->toBeGreaterThanOrEqual(2)
        ->and(Achievement::query()->where('slug', 'heart-explorer')->exists())->toBeTrue()
        ->and(Achievement::query()->where('slug', 'structure-hunter')->exists())->toBeTrue();
});

it('writes only criteria the evaluator understands', function (): void {
    // A seeded criterion outside App\Enums\AchievementCriterion is inert: the
    // badge would exist and be unwinnable, which is worse than absent because
    // nothing reports it.
    foreach (Achievement::query()->get() as $achievement) {
        expect(AchievementCriterion::tryFromCriteria($achievement->criteria))
            ->not->toBeNull("{$achievement->slug} has an unrecognised criterion");
    }
});

it('covers every criterion type, so no branch of the evaluator is untested data', function (): void {
    $types = Achievement::query()
        ->get()
        ->map(static fn (Achievement $achievement): ?AchievementCriterion => AchievementCriterion::tryFromCriteria(
            $achievement->criteria
        ))
        ->filter()
        ->unique()
        ->values();

    expect($types)->toHaveCount(count(AchievementCriterion::cases()));
});

it('is idempotent', function (): void {
    $count = Achievement::query()->count();

    $this->seed(AchievementSeeder::class);

    expect(Achievement::query()->count())->toBe($count);
});

it('gives every badge something a student can read', function (): void {
    foreach (Achievement::query()->get() as $achievement) {
        expect($achievement->name)->not->toBe('')
            ->and($achievement->description)->not->toBe('')
            ->and($achievement->icon)->not->toBe('');
    }
});
