<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\AnatomicalStructure;

/**
 * `PATCH /admin/structures/{structure}/anchor` — the hotspot authoring write.
 *
 * The whole request is one coordinate. It is separate from the structure
 * editor because it is a different act with a different authorization ability
 * (AnatomicalStructurePolicy::author): moving a marker on a model every
 * student sees is not the same decision as fixing a typo in its description.
 *
 * It is also the request that carries a value the client cannot compute and
 * the server cannot re-derive — the coordinate comes from a raycast in the
 * browser — which is exactly why its bounds are validated rather than trusted
 * (AnchorRules).
 */
final class AuthorAnchorRequest extends AdminRequest
{
    public function authorize(): bool
    {
        $structure = $this->route('structure');

        return $structure instanceof AnatomicalStructure
            && ($this->user()?->can('author', $structure) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return AnchorRules::rules();
    }

    /**
     * The authored point as the three floats the column stores.
     *
     * Cast here rather than in the service: JSON numbers arrive as int when
     * the browser sends `0`, and an `[0, -0.75, 0.65]` mixing int and float
     * round-trips differently through the JSON cast than the `[0.0, …]` the
     * viewer expects back.
     *
     * @return array{0: float, 1: float, 2: float}
     */
    public function anchor(): array
    {
        /** @var array{0: numeric, 1: numeric, 2: numeric} $anchor */
        $anchor = $this->validated('anchor_position');

        return [(float) $anchor[0], (float) $anchor[1], (float) $anchor[2]];
    }
}
