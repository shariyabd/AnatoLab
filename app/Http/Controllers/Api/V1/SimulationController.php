<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Simulations\SimulationEventRequest;
use App\Http\Resources\Simulations\SimulationStepResource;
use App\Models\Simulation;
use App\Models\User;
use App\Services\Simulation\SimulationService;
use App\Services\Simulation\SimulationStep;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Running a simulation (docs/architecture.md §12).
 *
 *   POST /api/v1/simulations/{simulation}/event  apply one action
 *   POST /api/v1/simulations/{simulation}/reset  back to the starting state
 *
 * Thin by construction: validate through a FormRequest, delegate to the
 * service, return a Resource (invariant 7). Every number in the response was
 * computed by SimulationEngine from the simulation's own configuration —
 * nothing the client sent contributes to one, which is the same rule that
 * keeps `is_correct` out of a quiz payload and is enforced the same way.
 *
 * Route model binding is not used, matching AnatomyController and
 * QuizController: the service resolves a *published* simulation by slug, and
 * binding would load the row first and make the published check a thing to
 * remember rather than the only way to get it.
 *
 * `{simulation}` is a slug. An unknown slug and a draft are one 404;
 * distinguishing them would confirm which drafts exist.
 */
final class SimulationController extends Controller
{
    public function __construct(private readonly SimulationService $simulations) {}

    /**
     * Apply one action, and explain the result it produced.
     *
     * The action id travels in the body rather than the path for the reason
     * QuizController's `attempt` documents: an action outside the simulation
     * that declares it is not addressable on its own, so a
     * `/actions/{action}` URL would claim a resource that does not exist.
     */
    public function event(SimulationEventRequest $request, string $simulation): SimulationStepResource
    {
        $step = $this->simulations->applyAction(
            $request->student(),
            $this->publishedOr404($simulation),
            $request->actionId(),
        );

        if (! $step instanceof SimulationStep) {
            throw new NotFoundHttpException('This simulation declares no such action.');
        }

        return new SimulationStepResource($step);
    }

    /**
     * Start the run again from the top.
     *
     * Read-shaped but a write, so it is a POST. There is nothing to validate —
     * the slug is constrained by the router and the owner comes from the
     * session — so there is no FormRequest, matching ConversationController.
     */
    public function reset(Request $request, string $simulation): SimulationStepResource
    {
        return new SimulationStepResource(
            $this->simulations->reset($this->student($request), $this->publishedOr404($simulation))
        );
    }

    private function publishedOr404(string $slug): Simulation
    {
        $found = $this->simulations->findPublished($slug);

        if (! $found instanceof Simulation) {
            throw new NotFoundHttpException('No published simulation matches that slug.');
        }

        return $found;
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
