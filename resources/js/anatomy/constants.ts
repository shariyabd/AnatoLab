/**
 * Constants shared by the whole 3D layer.
 *
 * FROZEN after Handover 01 (docs/feature-plan.md §7.5). Changing anything here
 * requires human approval and updating every consumer in the same commit.
 */

/**
 * Every model is uniformly scaled so its longest axis measures FIT_SIZE = 3.8
 * world units, then centred on the origin, before any hotspot is attached.
 *
 * This is what makes one camera rig, one lighting setup, and one set of
 * authored coordinates work for a heart and a skeleton alike.
 *
 * Every `anchorPosition` in the database is expressed in this pivot space.
 * Changing this number invalidates all of them, and no migration can repair it
 * — the original authoring scale is not recoverable from the stored values.
 *
 * Mirrored in PHP as config('anatomy.fit_size') for the admin authoring tool.
 * tests/Unit/FitSizeParityTest.php asserts the two agree and fails the build
 * if they drift (docs/architecture.md §5.4 rule 1).
 */
export const FIT_SIZE = 3.8

/**
 * Ceiling on how far the camera may dolly out, expressed in FIT_SIZE units so
 * it tracks the normalisation rather than a hard-coded distance.
 */
export const MAX_CAMERA_DISTANCE_FACTOR = 4

/**
 * How far a hotspot marker floats off the surface it snapped to, so it reads
 * as a label rather than z-fighting with the mesh.
 */
export const HOTSPOT_SURFACE_OFFSET = 0.02
