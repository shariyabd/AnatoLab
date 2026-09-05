<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\AnatomicalStructure;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One structure as the authoring tool and the admin listing see it.
 *
 * Carries no answer key — a structure is not correctness data; the question
 * that *points* at one is (AdminQuestionResource).
 *
 * What it does carry, and the student-facing StructureResource does not, is
 * `anchorPosition` in raw FIT_SIZE pivot space plus its provenance. Both are
 * the authoring tool's whole subject: the coordinate is only meaningful in
 * that space (invariant 5), and `anchoredAgainstCurrentModel` is what lets the
 * UI warn that an anchor predates the model file now on disk rather than
 * silently drawing a marker in the wrong place.
 *
 * @mixin AnatomicalStructure
 */
final class AdminStructureResource extends JsonResource
{
    /**
     * @param  bool  $anchorMatchesModel  from AnatomyService::anchorMatchesCurrentModel()
     */
    public function __construct(
        AnatomicalStructure $resource,
        private readonly bool $anchorMatchesModel = false,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed>|null $authored */
        $authored = $this->metadata['authored'] ?? null;

        return [
            'id' => (string) $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'taTerm' => $this->ta_term,
            'scientificName' => $this->scientific_name,
            'description' => $this->description,
            'function' => $this->function,
            'location' => $this->location,
            'difficulty' => $this->difficulty,
            'markerColor' => $this->marker_color,
            'isPublished' => $this->is_published,

            // The authored coordinate, unrounded. The authoring UI writes back
            // exactly what it reads, so anything lossy here would drift an
            // anchor a little on every save.
            'anchorPosition' => array_map(
                static fn (mixed $axis): float => (float) $axis,
                $this->anchor_position,
            ),

            'authoredAgainst' => $authored === null ? null : [
                'modelPath' => $authored['model_path'] ?? null,
                'fitSize' => $authored['fit_size'] ?? null,
                'authoredAt' => $authored['authored_at'] ?? null,
            ],

            // False for every anchor authored before provenance was recorded,
            // which is the honest answer: unknown is not verified.
            'anchorMatchesCurrentModel' => $this->anchorMatchesModel,
        ];
    }
}
