<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Missions\RecordMissionAttemptRequest;
use App\Http\Resources\Missions\MissionResource;
use App\Http\Resources\Missions\MissionResultResource;
use App\Http\Resources\Missions\MissionSummaryResource;
use App\Services\Assessment\MissionDefinition;
use App\Services\Assessment\MissionService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The three mission endpoints (docs/architecture.md §7, §9).
 *
 *   GET  /api/v1/missions                    the picker
 *   GET  /api/v1/missions/{mission}          config with the target sequence stripped
 *   POST /api/v1/missions/{mission}/attempt  score, per-step feedback
 *
 * Thin by construction: validate through a FormRequest, delegate to the
 * service, return a Resource (invariant 7). The scoring, the ownership
 * assignment and the `attempts` writes are all in `MissionService`, where a
 * queued job and a test can reach them and where the target sequence stays.
 *
 * Route model binding is deliberately not used, for the same reason
 * `Api\V1\LessonController` avoids it: binding would query the row again and
 * step around the cached organ payload that pays for this endpoint
 * (docs/architecture.md §11).
 */
final class MissionController extends Controller
{
    public function __construct(private readonly MissionService $missions) {}

    /**
     * @return AnonymousResourceCollection<int, MissionSummaryResource>
     */
    public function index(): AnonymousResourceCollection
    {
        return MissionSummaryResource::collection($this->missions->listPublished());
    }

    public function show(string $mission): MissionResource
    {
        return new MissionResource($this->missionOr404($mission));
    }

    /**
     * Grade a whole run in one request.
     *
     * A step at a time would be the obvious API and is the wrong one: the
     * server would have to say "right" or "wrong" after every click, and a
     * student clicking each structure in turn would read the target sequence
     * out of the responses one 200 at a time
     * (App\Services\Assessment\MissionAttemptData).
     */
    public function attempt(RecordMissionAttemptRequest $request, string $mission): MissionResultResource
    {
        return new MissionResultResource(
            $this->missions->score(
                $request->student(),
                $this->missionOr404($mission),
                $request->toData(),
            ),
        );
    }

    /**
     * An unknown slug, a draft mission, a mission on a draft organ and one
     * whose steps have no published structure behind them are one 404.
     * Distinguishing them would confirm which drafts exist
     * (MissionService::findPublishedBySlug).
     */
    private function missionOr404(string $slug): MissionDefinition
    {
        $found = $this->missions->findPublishedBySlug($slug);

        if ($found === null) {
            throw new NotFoundHttpException('No published mission matches that slug.');
        }

        return $found;
    }
}
