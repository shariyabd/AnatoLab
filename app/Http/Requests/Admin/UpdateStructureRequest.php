<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\AnatomicalStructure;
use Illuminate\Validation\Validator;

/**
 * `PUT /admin/structures/{structure}`.
 *
 * Carries the anchor too, because the editor and the authoring tool are the
 * same screen: an admin adjusts a name and nudges the marker in one save.
 */
final class UpdateStructureRequest extends AdminRequest
{
    public function authorize(): bool
    {
        $structure = $this->route('structure');

        return $structure instanceof AnatomicalStructure
            && ($this->user()?->can('update', $structure) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'ta_term' => ['nullable', 'string', 'max:255'],
            'scientific_name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'function' => ['nullable', 'string', 'max:5000'],
            'location' => ['nullable', 'string', 'max:255'],
            'difficulty' => ['required', 'integer', 'between:1,5'],
            'marker_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        AnchorRules::requireUniqueSlugWithinOrgan($validator, $this);
    }
}
