<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\Anatomy\OrganResource;
use App\Http\Resources\Explore\ExploreOrganResource;
use App\Models\Organ;
use App\Services\Anatomy\AnatomyService;
use Illuminate\Http\Resources\Json\JsonResource;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Explore page: the organ library, the viewer, and the structure panels.
 *
 * Read-only, so there is no FormRequest — nothing arrives from the client but
 * a slug in the path. Route model binding is deliberately not used, for the
 * same reason `Api\V1\AnatomyController` avoids it: binding would query the
 * row a second time and step around the cache that pays for this page
 * (docs/architecture.md §11).
 *
 * Both routes render the same Inertia component, which is what makes organ
 * switching a partial reload rather than a full page load. The client visits
 * `/explore/{slug}` asking for `organ` only; `organs` is not re-serialised,
 * the URL stays deep-linkable, and the viewer instance is never torn down
 * (see `Pages/Explore.vue` — the mounting pattern F06, F07, F09 and F12 copy).
 *
 * Both props are closures. Inertia evaluates a closure prop only when that
 * prop is actually being sent, so a partial reload for `organ` costs one
 * cached organ read and does not touch the library at all.
 *
 * Both props are plain arrays rather than Resource instances — see
 * `payload()` for why, and why it matters to the frozen contract
 * (docs/feature-plan.md §7.8).
 */
final class ExploreController extends Controller
{
    public function __construct(private readonly AnatomyService $anatomy) {}

    /**
     * `/explore` — the nav entry. Opens on the first published organ so the
     * page is never an empty canvas with a picker the student has to discover.
     */
    public function index(): Response
    {
        $first = $this->anatomy->listPublishedOrgans()->first();

        // Re-read by slug rather than rendering the list row directly. The
        // library query loads a body system and a structure count and nothing
        // else — by design, so drawing three cards does not ship every
        // structure of every organ — and `OrganResource` omits `structures`
        // entirely when the relation is not loaded. Handing that row to the
        // viewer produces an organ with no hotspots and an empty structure
        // list. Both calls are cached, so this costs nothing on a warm cache.
        return $this->page(
            $first === null ? null : $this->anatomy->findPublishedOrganBySlug($first->slug),
        );
    }

    /**
     * `/explore/{organ}` — a deep link, and the target of every organ switch.
     */
    public function show(string $organ): Response
    {
        $found = $this->anatomy->findPublishedOrganBySlug($organ);

        if ($found === null) {
            throw new NotFoundHttpException('No published organ matches that slug.');
        }

        return $this->page($found);
    }

    /**
     * Null organ is a real state, not a failure: a fresh database with no
     * published organs renders the page and its empty state rather than a 404
     * on a route the navigation links to.
     */
    private function page(?Organ $organ): Response
    {
        return Inertia::render('Explore', [
            'organs' => fn (): array => self::payload(
                ExploreOrganResource::collection($this->anatomy->listPublishedOrgans()),
            ),
            'organ' => fn (): ?array => $organ === null
                ? null
                : self::payload(new OrganResource($organ)),
        ]);
    }

    /**
     * A Resource as the plain array the client should receive.
     *
     * Inertia resolves a `JsonResource` prop by calling `toResponse()`, and it
     * recurses: `OrganResource`'s nested `StructureResource::collection` gets
     * the same treatment and arrives as `organ.structures.data`. `resolve()`
     * alone does not help — it leaves the nested collection as an object for
     * Inertia to find later.
     *
     * Serialising here settles the shape before Inertia sees it. `jsonSerialize`
     * on a Resource is `resolve()`, and nested Resources never wrap, so this is
     * exactly `OrganDto` — which is the point: the object handed to
     * `useAnatomyViewer` must be the one `types.ts` describes, with no
     * unwrapping step for four downstream lanes to remember.
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
