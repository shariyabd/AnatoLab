<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\Anatomy\OrganSummaryResource;
use App\Services\Anatomy\AnatomyService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tutor panel on its own route.
 *
 * Handover 05 owns Explore.vue and is building it in parallel, so the panel
 * ships as a self-contained component mounted here rather than wired into a
 * page this lane does not own (docs/handovers/parallel-execution-plan.md D3).
 * Handover 05 leaves a named slot; the wiring lands in its lane or in Handover
 * 14, and `resources/js/Components/Tutor/TutorPanel.vue` drops straight into it.
 *
 * The organ list is passed as a prop rather than fetched, so the page is useful
 * on first paint and so the panel can be demonstrated end to end before Explore
 * exists. Nothing secret goes into an Inertia prop (docs/engineering.md §10) —
 * these are published organ names and slugs.
 */
final class TutorPanelController extends Controller
{
    public function __construct(private readonly AnatomyService $anatomy) {}

    public function __invoke(): Response
    {
        return Inertia::render('Tutor/Index', [
            // ->resolve() rather than the collection itself: Inertia would
            // otherwise serialise a JsonResource through its HTTP response and
            // hand the page `{ data: [...] }`, which every consumer would then
            // have to unwrap. The Resource still does the shaping.
            'organs' => OrganSummaryResource::collection($this->anatomy->listPublishedOrgans())
                ->resolve(),
        ]);
    }
}
