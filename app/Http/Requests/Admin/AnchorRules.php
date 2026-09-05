<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\AnatomicalStructure;
use App\Models\Organ;
use Illuminate\Validation\Validator;

/**
 * The validation rules for an authored anchor, in one place.
 *
 * Shared by the create, update and anchor-only routes rather than repeated:
 * the bounds below are a statement about FIT_SIZE pivot space (invariant 5),
 * and three copies of that statement is three chances for one of them to drift.
 *
 * Not a FormRequest itself — it is rules, not a request.
 */
final class AnchorRules
{
    /**
     * How far outside the normalised cube an anchor may legitimately sit.
     *
     * FIT_SIZE is the size of the cube a model is scaled into, so the surface
     * lives within ±FIT_SIZE/2 of the origin on every axis. The allowance is
     * generous rather than tight: a raycast against a bounding-box corner can
     * land just outside the half-extent, and rejecting a legitimate click is
     * worse than accepting one a little wide, which HotspotLayer's surface
     * snapping pulls back onto the mesh anyway.
     *
     * The point of the bound is to reject a coordinate authored in a
     * *different* space — an un-normalised world coordinate in the hundreds,
     * which would silently place a marker nowhere near the organ.
     */
    private const BOUND_FACTOR = 1.5;

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        $bound = round((float) config('anatomy.fit_size') * self::BOUND_FACTOR, 4);

        return [
            // Exactly three floats. The viewer's `author:point` emits a Vec3
            // and the column round-trips a 3-float array; anything else is not
            // a point in this space (docs/handovers/03-anatomy-domain-api.md).
            'anchor_position' => ['required', 'array', 'size:3'],
            'anchor_position.*' => ['required', 'numeric', "between:-{$bound},{$bound}"],
        ];
    }

    /**
     * `(organ_id, slug)` is unique, and the organ comes from the route.
     */
    public static function requireUniqueSlugWithinOrgan(Validator $validator, AdminRequest $request): void
    {
        $validator->after(static function (Validator $validator) use ($request): void {
            $organ = $request->route('organ');
            $slug = $request->input('slug');

            if (! $organ instanceof Organ || ! is_string($slug)) {
                return;
            }

            $existing = AnatomicalStructure::query()
                ->where('organ_id', $organ->getKey())
                ->where('slug', $slug);

            $structure = $request->route('structure');

            if ($structure instanceof AnatomicalStructure) {
                $existing->whereKeyNot($structure->getKey());
            }

            if ($existing->exists()) {
                $validator->errors()->add('slug', 'This organ already has a structure with that slug.');
            }
        });
    }
}
