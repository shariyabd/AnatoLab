<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\Missions\MissionResource;
use App\Http\Resources\Missions\MissionSummaryResource;
use App\Services\Assessment\MissionDefinition;
use App\Services\Assessment\MissionService;
use Illuminate\Http\Resources\Json\JsonResource;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The mission pages: the picker, and one mission's run.
 *
 * The whole mission arrives as a page prop rather than being fetched after
 * mount. Two reasons, and the second is the one that matters: the run is
 * playable on first paint, and the steps travel through exactly the same
 * `MissionStepResource` the API uses — so the target-sequence stripping is one
 * code path with one set of tests, not two that can drift
 * (docs/architecture.md §7: response shaping is the Resource's job, and only
 * the Resource's job). Page props are embedded in the HTML, where "view source"
 * is the network tab, which is why the absence test asserts them too.
 *
 * Props are plain arrays rather than Resource instances for the reason
 * `QuizController::payload()` documents: Inertia resolves a JsonResource
 * through its HTTP response and nested collections arrive wrapped in `data`,
 * which would hand `useAnatomyViewer` something that is not an `OrganDto`.
 *
 * Read-only, so there is no FormRequest — nothing arrives from the client but a
 * slug in the path. Submitting a run is a POST to `Api\V1\MissionController`.
 */
final class MissionController extends Controller
{
    public function __construct(private readonly MissionService $missions) {}

    public function index(): Response
    {
        return Inertia::render('Missions/Index', [
            // Encoded card by card rather than with the collection's
            // ->resolve(), which QuizController can use because its card nests
            // no other Resource. This one nests `OrganSummaryResource`, and
            // ->resolve() leaves a nested Resource as an object rather than
            // resolving it — the card would reach the page with an `organ` key
            // holding something the `MissionCard` type does not describe.
            'missions' => fn (): array => $this->missions
                ->listPublished()
                ->map(static fn (MissionDefinition $definition): array => self::payload(
                    new MissionSummaryResource($definition)
                ))
                ->all(),
        ]);
    }

    public function show(string $mission): Response
    {
        $found = $this->missions->findPublishedBySlug($mission);

        if ($found === null) {
            throw new NotFoundHttpException('No published mission matches that slug.');
        }

        return Inertia::render('Missions/Show', [
            'mission' => fn (): array => self::payload(new MissionResource($found)),
        ]);
    }

    /**
     * A Resource as the plain array the client should receive.
     *
     * Same helper, same reasoning as `QuizController::payload()`: encoding
     * settles the nested `OrganResource` before Inertia gets a chance to wrap
     * it, so the `organ` key really is the `OrganDto` that
     * `resources/js/anatomy/types.ts` describes.
     *
     * @return array<string, mixed>
     */
    private static function payload(JsonResource $resource): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(json_encode($resource, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
