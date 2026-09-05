<?php

declare(strict_types=1);

namespace App\Http\Resources\Anatomy;

use App\Models\Organ;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * The organ picker's payload: enough to draw a card, not enough to load a model.
 *
 * Deliberately NOT OrganDto. types.ts describes what the viewer consumes, and
 * the viewer never consumes a list — it is handed one fully-formed OrganDto by
 * the composable. Emitting OrganDto here would mean shipping 25 structures and
 * a model URL per card to render three thumbnails.
 *
 * @mixin Organ
 */
final class OrganSummaryResource extends JsonResource
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
            'accentColor' => $this->accent_color,
            'thumbnailUrl' => self::resolveThumbnailUrl($this->thumbnail_path),
            'bodySystem' => $this->whenLoaded('bodySystem', fn (): array => [
                'slug' => $this->bodySystem->slug,
                'name' => $this->bodySystem->name,
            ]),
            'structureCount' => $this->whenCounted('publishedStructures'),
        ];
    }

    /**
     * Null rather than a broken URL: a card without a thumbnail renders its
     * accent colour, and the Vue side can branch on null but not on a 404.
     */
    private static function resolveThumbnailUrl(?string $thumbnailPath): ?string
    {
        if ($thumbnailPath === null || $thumbnailPath === '') {
            return null;
        }

        if (str_starts_with($thumbnailPath, 'http://') || str_starts_with($thumbnailPath, 'https://')) {
            return $thumbnailPath;
        }

        return Storage::disk((string) config('anatomy.model_disk'))->url($thumbnailPath);
    }
}
