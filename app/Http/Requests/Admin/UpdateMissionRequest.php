<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Mission;
use Illuminate\Validation\Rule;

/**
 * `PUT /admin/missions/{mission}`.
 */
final class UpdateMissionRequest extends AdminRequest
{
    public function authorize(): bool
    {
        $mission = $this->route('mission');

        return $mission instanceof Mission && ($this->user()?->can('update', $mission) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $mission = $this->route('mission');

        return [
            'organ_id' => ['required', 'integer', 'exists:organs,id'],
            'slug' => [
                'required', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('missions', 'slug')->ignore($mission instanceof Mission ? $mission->getKey() : null),
            ],
            ...StoreMissionRequest::sharedRules(),
        ];
    }
}
