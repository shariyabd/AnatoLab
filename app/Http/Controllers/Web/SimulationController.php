<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\Simulations\SimulationResource;
use App\Http\Resources\Simulations\SimulationStepResource;
use App\Http\Resources\Simulations\SimulationSummaryResource;
use App\Models\Simulation;
use App\Models\User;
use App\Services\Simulation\SimulationService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The simulation pages: the picker, and one run.
 *
 * The whole simulation and the student's current step arrive as page props
 * rather than being fetched after mount, for the reason QuizController
 * documents: the page is usable on first paint, and the payload travels through
 * exactly the same Resources the API uses — so what the browser may see is one
 * code path with one set of tests, not two that can drift.
 *
 * Rendering a run reaches no AI provider. The prose for each step was written
 * into the event log when the step was taken and is read back
 * (SimulationService::currentStep), so a reload costs a replay of arithmetic
 * and nothing else.
 *
 * Props are plain arrays rather than Resource instances for the reason
 * `ExploreController::payload()` documents at length: Inertia resolves a
 * JsonResource through its HTTP response and nested collections arrive wrapped
 * in `data`, which would hand `useAnatomyViewer` something that is not an
 * `OrganDto`.
 */
final class SimulationController extends Controller
{
    public function __construct(private readonly SimulationService $simulations) {}

    public function index(): Response
    {
        return Inertia::render('Simulations/Index', [
            // ->resolve() rather than the collection itself: Inertia would
            // otherwise serialise a JsonResource through its HTTP response and
            // hand the page `{ data: [...] }`. Safe here, and not in `show()`,
            // because a summary card nests no other Resource.
            'simulations' => fn (): array => SimulationSummaryResource::collection(
                $this->simulations->listPublished()
            )->resolve(),
        ]);
    }

    public function show(Request $request, string $simulation): Response
    {
        $found = $this->simulations->findPublished($simulation);

        if (! $found instanceof Simulation) {
            throw new NotFoundHttpException('No published simulation matches that slug.');
        }

        $student = $this->student($request);
        $configuration = $this->simulations->configurationFor($found);

        return Inertia::render('Simulations/Show', [
            'simulation' => fn (): array => self::payload(new SimulationResource($found, $configuration)),
            'step' => fn (): array => self::payload(
                new SimulationStepResource($this->simulations->currentStep($student, $found))
            ),
            'events' => fn (): array => $this->simulations->eventLog($student, $found),
        ]);
    }

    /**
     * A Resource as the plain array the client should receive.
     *
     * Same helper, same reasoning as `ExploreController::payload()`: encoding
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

    /**
     * @throws AuthenticationException
     */
    private function student(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
