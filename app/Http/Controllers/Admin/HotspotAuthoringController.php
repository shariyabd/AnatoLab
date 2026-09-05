<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AuthorAnchorRequest;
use App\Http\Requests\Admin\StoreStructureRequest;
use App\Http\Requests\Admin\UpdateStructureRequest;
use App\Http\Resources\Admin\AdminStructureResource;
use App\Http\Resources\Anatomy\OrganResource;
use App\Models\AnatomicalStructure;
use App\Models\Organ;
use App\Services\Anatomy\AnatomyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The hotspot authoring tool (Handover 13's highest-value screen).
 *
 * An admin opens an organ, clicks a point on the model, and the viewer emits
 * `author:point` with a coordinate in FIT_SIZE pivot space. The admin names
 * the structure and saves; the coordinate becomes
 * `anatomical_structures.anchor_position`.
 *
 * This replaces hand-editing coordinates, and it is how an organ grows from
 * four structures to eight without a developer
 * (docs/handovers/13-admin-content.md).
 *
 * The upstream tool did the same raycast behind `?authoring=1` and printed a
 * code literal to copy (docs/project-context.md §2.4). Two things are different
 * here: it is gated by the `admin` middleware **and**
 * AnatomicalStructurePolicy::author(), and it writes to the database.
 *
 * **Coordinates are only meaningful in FIT_SIZE = 3.8 space.** Every anchor in
 * the table is authored there, and the page is given the model fingerprint the
 * anchors were authored against so it can say so when the model has since
 * changed (invariant 5).
 */
final class HotspotAuthoringController extends Controller
{
    public function __construct(private readonly AnatomyService $anatomy) {}

    /**
     * The authoring canvas for one organ.
     */
    public function show(string $organ): Response
    {
        $found = $this->anatomy->findAnyOrganBySlug($organ);

        if ($found === null) {
            throw new NotFoundHttpException('No organ matches that slug.');
        }

        Gate::authorize('update', $found);

        $structures = $this->anatomy->listAllStructures($found);

        /*
         | The viewer takes an OrganDto and OrganResource is the frozen mirror
         | of it (docs/feature-plan.md §7.8), so the admin page uses the same
         | Resource rather than a parallel shape the viewer would have to learn.
         |
         | The relation is filled with *all* structures rather than the
         | published ones the student sees: authoring an unpublished marker is
         | the entire point of this screen, and a draft structure the admin
         | cannot see is one they cannot move.
         */
        $found->setRelation('publishedStructures', $structures);

        return Inertia::render('Admin/Authoring/Show', [
            'organ' => self::payload(new OrganResource($found)),

            'structures' => self::payload(
                AdminStructureResource::collection(
                    $structures->map(fn (AnatomicalStructure $structure): AdminStructureResource => new AdminStructureResource(
                        $structure,
                        $this->anatomy->anchorMatchesCurrentModel($structure),
                    )),
                ),
            ),

            /*
             | The pivot space every coordinate on this page is expressed in,
             | and the identity of the model file they are being authored
             | against. The page shows both, and warns when a structure's
             | recorded provenance does not match the second one — an anchor
             | authored against a re-encoded model may now point at the wrong
             | anatomy, and nothing else in the data would say so.
             */
            'authoring' => [
                'fitSize' => (float) config('anatomy.fit_size'),
                'currentModel' => $this->anatomy->authoringProvenance($found),
            ],
        ]);
    }

    /**
     * Create a structure from an authored click.
     */
    public function store(StoreStructureRequest $request, Organ $organ): RedirectResponse
    {
        $attributes = $request->safe()->except('anchor_position');
        $attributes['anchor_position'] = $request->anchor();

        // createStructure() records the authoring provenance itself, so the
        // anchor and the model it was authored against are written together.
        $structure = $this->anatomy->createStructure($organ, $attributes);

        return back()->with('success', "“{$structure->name}” added as an unpublished hotspot.");
    }

    public function update(UpdateStructureRequest $request, AnatomicalStructure $structure): RedirectResponse
    {
        $this->anatomy->updateStructure($structure, $request->validated());

        return back()->with('success', 'Saved.');
    }

    /**
     * Move an existing marker — the `author:point` write on its own.
     *
     * Returns JSON rather than a redirect: the authoring page updates one
     * marker in place while the model stays loaded, and a full Inertia visit
     * would re-download the GLB to move a dot.
     */
    public function anchor(AuthorAnchorRequest $request, AnatomicalStructure $structure): JsonResponse
    {
        $updated = $this->anatomy->setAnchorPosition($structure, $request->anchor());

        return response()->json([
            'structure' => new AdminStructureResource(
                $updated->loadMissing('organ'),
                $this->anatomy->anchorMatchesCurrentModel($updated),
            ),
        ]);
    }

    public function status(Request $request, AnatomicalStructure $structure): RedirectResponse
    {
        Gate::authorize('publish', $structure);

        $validated = $request->validate(['is_published' => ['required', 'boolean']]);

        $this->anatomy->setStructurePublished($structure, (bool) $validated['is_published']);

        return back()->with('success', $validated['is_published']
            ? "“{$structure->name}” is now visible on the model."
            : "“{$structure->name}” is hidden from students.");
    }

    public function destroy(AnatomicalStructure $structure): RedirectResponse
    {
        Gate::authorize('delete', $structure);

        $this->anatomy->deleteStructure($structure);

        return back()->with('success', "“{$structure->name}” deleted.");
    }

    /**
     * A Resource as the plain array the client should receive.
     *
     * Same helper and same reasoning as Web\LessonController::payload():
     * Inertia resolves a JsonResource prop by calling toResponse() and recurses
     * into nested Resources, so serialising here settles the shape before
     * Inertia sees it and `organ` arrives as the OrganDto the viewer expects
     * rather than `organ.data`.
     *
     * @return array<array-key, mixed>
     */
    private static function payload(mixed $resource): array
    {
        /** @var array<array-key, mixed> $decoded */
        $decoded = json_decode(json_encode($resource, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
