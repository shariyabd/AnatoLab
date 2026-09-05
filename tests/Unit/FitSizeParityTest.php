<?php

declare(strict_types=1);

/*
| REQUIRED TEST (docs/handovers/01-platform-foundation.md, engineering.md §9).
|
| FIT_SIZE is defined in TypeScript and mirrored in PHP. If the two ever drift,
| every anchor_position authored through the admin tool lands in the wrong place
| in the viewer — silently, with no error anywhere, and unrecoverably, because
| the authoring scale is not stored alongside the coordinate.
|
| This test is the only thing standing between that and a shipped build.
*/

function fitSizeFromTypeScript(): float
{
    $path = base_path('resources/js/anatomy/constants.ts');

    expect(file_exists($path))->toBeTrue(
        'resources/js/anatomy/constants.ts is missing; it is the source of truth for FIT_SIZE.'
    );

    $source = (string) file_get_contents($path);

    $matched = preg_match('/export\s+const\s+FIT_SIZE\s*=\s*([0-9]*\.?[0-9]+)/', $source, $matches);

    expect($matched)->toBe(1, 'Could not find "export const FIT_SIZE = <number>" in constants.ts.');

    return (float) $matches[1];
}

it('keeps the PHP config and the TypeScript constant in agreement', function (): void {
    expect(fitSizeFromTypeScript())->toBe((float) config('anatomy.fit_size'));
});

it('still equals 3.8', function (): void {
    // Pinned literally as well as compared. Changing both sides together would
    // otherwise pass the parity test while invalidating every coordinate in
    // the database (docs/architecture.md §5.4 rule 1).
    expect((float) config('anatomy.fit_size'))->toBe(3.8)
        ->and(fitSizeFromTypeScript())->toBe(3.8);
});
