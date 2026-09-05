<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Mission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One mission as an administrator sees it — target sequence included.
 *
 * The second and last sanctioned exception to invariant 4, and it works the
 * same way AdminQuestionResource does: the `admin` middleware guards the area,
 * MissionPolicy::viewTargetSequence() guards the field, and the field is
 * omitted rather than nulled when the ability is denied.
 *
 * `configuration` is the answer key for a mission — it holds the ordered
 * structures a student is meant to trace. MissionService exists to keep it
 * server-side; this is the one place it is legitimately rendered, because the
 * alternative is editing a sequence blind.
 *
 * @mixin Mission
 */
final class AdminMissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = [
            'id' => (string) $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'description' => $this->description,
            'type' => $this->type->value,
            'difficulty' => $this->difficulty,
            'status' => $this->status->value,
            'statusLabel' => $this->status->label(),
            'stepCount' => count($this->configuration['steps'] ?? []),
            'organ' => $this->whenLoaded('organ', fn (): array => [
                'slug' => $this->organ->slug,
                'name' => $this->organ->name,
            ]),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];

        if (! self::maySeeTargetSequence($request, $this->resource)) {
            return $payload;
        }

        return [...$payload, 'configuration' => $this->configuration];
    }

    private static function maySeeTargetSequence(Request $request, Mission $mission): bool
    {
        $user = $request->user();

        return $user instanceof User && $user->can('viewTargetSequence', $mission);
    }
}
