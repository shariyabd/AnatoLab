<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Normalised model size
    |---------------------------------------------------------------------------
    |
    | Every GLB is scaled into a cube of this size and centred on the origin
    | before hotspots are attached, so one set of authored coordinates works
    | for organs of wildly different real-world scale.
    |
    | Every anatomical_structures.anchor_position in the database is expressed
    | in this pivot space. Changing this number silently invalidates all of
    | them — there is no migration that can fix it, because the original
    | authoring scale is not recoverable.
    |
    | The single source of truth is resources/js/anatomy/constants.ts; this
    | mirrors it for the admin authoring tool. Tests\Unit\FitSizeParityTest
    | asserts the two agree and will fail the build if they drift.
    |
    */

    'fit_size' => 3.8,

    /*
    |---------------------------------------------------------------------------
    | Model storage
    |---------------------------------------------------------------------------
    |
    | Where organ models are served from. Local disk in development, an
    | S3-compatible bucket in production (docs/architecture.md §2).
    |
    */

    'model_disk' => env('ANATOMY_MODEL_DISK', 'public'),

    'model_path' => 'models',

    /*
    |---------------------------------------------------------------------------
    | Performance budgets
    |---------------------------------------------------------------------------
    |
    | docs/architecture.md §15.1. Enforced by the asset pipeline (Handover 02),
    | not at runtime — they live here so there is one number to change.
    |
    */

    'budgets' => [
        'max_model_bytes' => 2 * 1024 * 1024,
        'max_triangles' => 150_000,
    ],

];
