<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\ModelFormat;
use App\Models\Organ;
use Illuminate\Validation\Rule;

/**
 * `PUT /admin/organs/{organ}`.
 *
 * Status is absent on purpose — publishing goes through its own route and its
 * own policy ability, so an edit form cannot publish content as a side effect
 * of saving a typo fix.
 */
final class UpdateOrganRequest extends AdminRequest
{
    public function authorize(): bool
    {
        $organ = $this->route('organ');

        return $organ instanceof Organ && ($this->user()?->can('update', $organ) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organ = $this->route('organ');

        return [
            'body_system_id' => ['required', 'integer', 'exists:body_systems,id'],
            'slug' => [
                'required', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('organs', 'slug')->ignore($organ instanceof Organ ? $organ->getKey() : null),
            ],
            'name' => ['required', 'string', 'max:255'],
            'scientific_name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'model_path' => ['required', 'string', 'max:255'],
            'model_format' => ['required', Rule::enum(ModelFormat::class)],
            'thumbnail_path' => ['nullable', 'string', 'max:255'],
            'accent_color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }
}
