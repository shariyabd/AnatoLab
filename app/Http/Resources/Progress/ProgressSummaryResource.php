<?php

declare(strict_types=1);

namespace App\Http\Resources\Progress;

use App\Services\Progress\ProgressSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The whole dashboard payload (PRD §18).
 *
 * Mirrors `ProgressDto` in resources/js/types/progress.ts. One Resource for
 * the page prop and the API endpoint alike, so the two cannot drift — the same
 * arrangement Handover 07 uses for the quiz payload.
 *
 * Nothing here is another student's data and nothing here is cached
 * (docs/architecture.md §11).
 *
 * @property-read ProgressSummary $resource
 */
final class ProgressSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $gamification = $this->resource->gamification;

        return [
            'overallScore' => $this->resource->overallScore,

            'systems' => SystemMasteryResource::collection($this->resource->systems)->resolve(),

            'strongest' => $this->resource->strongest === null
                ? null
                : (new SystemMasteryResource($this->resource->strongest))->resolve(),

            'needsPractice' => $this->resource->needsPractice === null
                ? null
                : (new SystemMasteryResource($this->resource->needsPractice))->resolve(),

            'lessonsCompleted' => $this->resource->lessonsCompleted,

            'quiz' => [
                'attempts' => $this->resource->attempts,
                'correctAttempts' => $this->resource->correctAttempts,
                'accuracyPercent' => $this->resource->accuracyPercent(),
            ],

            'gamification' => [
                'xp' => $gamification->xp,
                'level' => $gamification->level,
                'xpIntoLevel' => $gamification->xpIntoLevel,
                'xpForLevel' => $gamification->xpForLevel,
                'streakDays' => $gamification->streakDays,
                'achievements' => AchievementResource::collection($gamification->earned)->resolve(),
            ],

            'recentActivity' => ActivityEntryResource::collection($this->resource->recentActivity)->resolve(),

            'recommendation' => $this->resource->recommendation === null
                ? null
                : (new RecommendationResource($this->resource->recommendation))->resolve(),
        ];
    }
}
