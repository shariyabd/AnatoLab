<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\ModelFormat;
use App\Enums\OrganStatus;
use App\Models\Organ;
use Illuminate\Validation\Rule;

/**
 * `POST /admin/organs`.
 *
 * The validated array is handed to AnatomyService::createOrgan() whole, which
 * is safe precisely because this class enumerates the keys — `$request->all()`
 * never reaches a model (invariant 7).
 */
final class StoreOrganRequest extends AdminRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Organ::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body_system_id' => ['required', 'integer', 'exists:body_systems,id'],
            // Lowercase kebab only: the slug is the URL and the cache key, and
            // AnatomyService keys `anatomy:organ:{slug}` off it.
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/', 'unique:organs,slug'],
            'name' => ['required', 'string', 'max:255'],
            'scientific_name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'model_path' => ['required', 'string', 'max:255'],
            'model_format' => ['required', Rule::enum(ModelFormat::class)],
            'thumbnail_path' => ['nullable', 'string', 'max:255'],
            'accent_color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            // Absent means draft: the migration default. Content is invisible
            // until someone publishes it deliberately (docs/architecture.md §6).
            'status' => ['sometimes', Rule::enum(OrganStatus::class)],
        ];
    }
}
