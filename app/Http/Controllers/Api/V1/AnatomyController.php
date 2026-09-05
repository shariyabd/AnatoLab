<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Anatomy\OrganResource;
use App\Http\Resources\Anatomy\OrganSummaryResource;
use App\Http\Resources\Anatomy\StructureDetailResource;
use App\Services\Anatomy\AnatomyService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The read surface every other feature resolves structures through.
 *
 * Read-only, so there is no FormRequest here — there is nothing to validate.
 * The organ slug and the structure id come from the route, and route model
 * binding is deliberately NOT used: binding would query the row a second time
 * and bypass the cache that makes this endpoint's budget (docs/architecture.md
 * §11, §15.1).
 */
final class AnatomyController extends Controller
{
    public function __construct(private readonly AnatomyService $anatomy) {}

    /**
     * @return AnonymousResourceCollection<int, OrganSummaryResource>
     */
    public function index(): AnonymousResourceCollection
    {
        return OrganSummaryResource::collection($this->anatomy->listPublishedOrgans());
    }

    public function show(string $organ): OrganResource
    {
        $found = $this->anatomy->findPublishedOrganBySlug($organ);

        if ($found === null) {
            throw new NotFoundHttpException('No published organ matches that slug.');
        }

        return new OrganResource($found);
    }

    public function structure(int $structure): StructureDetailResource
    {
        $found = $this->anatomy->findPublishedStructure($structure);

        if ($found === null) {
            throw new NotFoundHttpException('No published structure matches that id.');
        }

        return new StructureDetailResource($found);
    }
}
