<?php

declare(strict_types=1);

namespace App\Http\Resources\Progress;

use App\Models\Achievement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One earned badge.
 *
 * `criteria` is deliberately absent. It is the rule the server evaluates, and
 * a client that could read "mastery on the respiratory system ≥ 70" would be
 * reading the marking scheme for a badge it has not earned yet — the same
 * instinct behind invariant 4, applied to gamification.
 *
 * @mixin Achievement
 */
final class AchievementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'icon' => $this->icon,
        ];
    }
}
