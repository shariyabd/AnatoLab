<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\MissionType;
use App\Models\Mission;
use Illuminate\Validation\Rule;

/**
 * `POST /admin/missions`.
 *
 * `configuration.steps[].structure_id` is the target sequence — the mission's
 * answer key. It is authored here as a *slug*, not an id, because that is what
 * MissionStep::fromArray() reads and because slugs survive a reseed while
 * auto-increment ids do not.
 *
 * The slugs are not checked against the organ at validation time. Publishing
 * is where that is enforced (MissionService::setStatus refuses a configuration
 * that will not resolve), so an admin can save a half-authored mission and
 * come back to it.
 */
final class StoreMissionRequest extends AdminRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Mission::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'organ_id' => ['required', 'integer', 'exists:organs,id'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/', 'unique:missions,slug'],
            ...self::sharedRules(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function sharedRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'type' => ['required', Rule::enum(MissionType::class)],
            'difficulty' => ['required', 'integer', 'between:1,5'],

            'configuration' => ['required', 'array'],
            'configuration.steps' => ['required', 'array', 'min:1', 'max:20'],
            'configuration.steps.*.structure_id' => ['required', 'string', 'max:255'],
            'configuration.steps.*.prompt' => ['required', 'string', 'max:1000'],
            'configuration.steps.*.hint' => ['nullable', 'string', 'max:1000'],
            'configuration.steps.*.explanation' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
