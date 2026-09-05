<?php

declare(strict_types=1);

namespace App\Http\Resources\Anatomy;

use App\Models\Organ;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Mirrors OrganDto in resources/js/anatomy/types.ts — exactly.
 *
 * This is the single most-consumed contract in the project: Handovers 04, 05,
 * 07, 08, 09, 12 and 13 all resolve structures through it. types.ts is frozen;
 * changing either side alone is a silent runtime break
 * (docs/feature-plan.md §7.8).
 *
 * `modelUrl` is fully resolved here and nowhere else. The viewer loads the URL
 * it is given and never constructs one (docs/architecture.md §5.4 rule 3), so
 * moving models from a local disk to a bucket is a config change with no
 * client work.
 *
 * @mixin Organ
 */
final class OrganResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'scientificName' => $this->scientific_name,
            'description' => $this->description,
            'modelUrl' => self::resolveModelUrl($this->model_path),
            'modelFormat' => $this->model_format->value,
            'accentColor' => $this->accent_color,
            'structures' => StructureResource::collection(
                $this->whenLoaded('publishedStructures'),
            ),
        ];
    }

    /**
     * Turn a disk-relative model path into something the browser can fetch.
     *
     * An absolute URL is passed through untouched so a CDN-hosted model can be
     * recorded verbatim without a second column.
     */
    public static function resolveModelUrl(string $modelPath): string
    {
        if (str_starts_with($modelPath, 'http://') || str_starts_with($modelPath, 'https://')) {
            return $modelPath;
        }

        return Storage::disk((string) config('anatomy.model_disk'))->url($modelPath);
    }
}
