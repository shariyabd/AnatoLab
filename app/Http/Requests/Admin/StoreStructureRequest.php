<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\AnatomicalStructure;
use Illuminate\Validation\Validator;

/**
 * `POST /admin/organs/{organ}/structures` — the authoring tool's create.
 *
 * The admin clicks the model, the viewer emits `author:point`, and the form
 * posts that coordinate together with the name and TA term. `organ_id` is
 * absent by design: the controller passes the route's Organ to the service,
 * so a payload cannot file a structure under a different organ.
 */
final class StoreStructureRequest extends AdminRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AnatomicalStructure::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/'],
            'name' => ['required', 'string', 'max:255'],
            // Nullable because the tool creates a hotspot from a click before
            // the Terminologia Anatomica term is confirmed — the reason the
            // column is nullable (see the migration).
            'ta_term' => ['nullable', 'string', 'max:255'],
            'scientific_name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'function' => ['nullable', 'string', 'max:5000'],
            'location' => ['nullable', 'string', 'max:255'],
            'difficulty' => ['required', 'integer', 'between:1,5'],
            'marker_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            ...AnchorRules::rules(),
        ];
    }

    /**
     * Slugs are unique per organ, not globally — `apex` means one thing in the
     * heart and another in the lungs — so uniqueness cannot be a rule string
     * and is scoped to the route's organ here.
     */
    public function withValidator(Validator $validator): void
    {
        AnchorRules::requireUniqueSlugWithinOrgan($validator, $this);
    }

    /**
     * The authored point as three floats — see AuthorAnchorRequest::anchor()
     * for why the cast is here and not in the service.
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
