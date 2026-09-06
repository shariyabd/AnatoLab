<?php

declare(strict_types=1);

namespace App\Services\Anatomy;

use App\Models\AnatomicalStructure;
use App\Models\Organ;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Does every structure that claims a mesh node actually have one?
 *
 * Handover 17 Branch B. `model_object_name` is a string in one system naming a
 * node in another, with nothing in the database able to enforce the join — so
 * the enforcement is this, run in CI.
 *
 * Resolves the model disk at call time from `config('anatomy.model_disk')`, the
 * same way `AnatomyService` does, so `Storage::fake('models')` swaps it in a
 * test and a production deployment can point it at a bucket without a second
 * code path. It reads models; it never writes one, and it never touches a
 * request, a session, or the authenticated user (invariant 1).
 */
final class MeshIdentityVerifier
{
    public function __construct(private readonly GlbNodeReader $reader) {}

    /**
     * Every organ, converted or not.
     *
     * Drafts included: an organ is verified before it is published, which is
     * the only order in which the check is worth anything.
     *
     * @return list<MeshIdentityReport>
     */
    public function verifyAll(): array
    {
        return Organ::query()
            ->with('publishedStructures')
            ->orderBy('slug')
            ->get()
            ->map(fn (Organ $organ): MeshIdentityReport => $this->verifyOrgan($organ))
            ->all();
    }

    public function verifyOrgan(Organ $organ): MeshIdentityReport
    {
        /** @var list<AnatomicalStructure> $structures */
        $structures = $organ->publishedStructures()->get()->all();

        $claimed = [];
        $dotOnly = [];

        foreach ($structures as $structure) {
            $node = $structure->model_object_name;

            if (is_string($node) && $node !== '') {
                $claimed[$structure->slug] = $node;
            } else {
                $dotOnly[] = $structure->slug;
            }
        }

        $binary = $this->readModel($organ->model_path);

        if ($binary === null) {
            // Unreadable model, and every structure claiming a node inside it is
            // therefore unverifiable. Reported as orphaned rather than passed:
            // "we could not check" and "it is fine" are different answers.
            return new MeshIdentityReport(
                organSlug: $organ->slug,
                modelPath: $organ->model_path,
                modelIsReadable: false,
                isSingleMesh: false,
                orphans: array_keys($claimed),
                dotOnly: $dotOnly,
            );
        }

        $nodes = $this->reader->readNodeNames($binary);
        $present = array_flip($nodes);

        $orphans = [];
        $matched = [];

        foreach ($claimed as $slug => $node) {
            if (isset($present[$node])) {
                $matched[] = $slug;
            } else {
                $orphans[] = $slug;
            }
        }

        return new MeshIdentityReport(
            organSlug: $organ->slug,
            modelPath: $organ->model_path,
            modelIsReadable: true,
            isSingleMesh: $this->reader->countMeshes($binary) <= 1,
            orphans: $orphans,
            matched: $matched,
            dotOnly: $dotOnly,
            unclaimedNodes: $this->unclaimedNodes($organ->slug, $nodes, $claimed),
        );
    }

    /**
     * Structure-shaped nodes in the model that no row points at.
     *
     * A warning, never a failure. It usually means an export gained a structure
     * the seeder has not caught up with — which is content work, not a broken
     * join — but it is exactly the signal that says the export and the database
     * are drifting, and it is invisible from the orphan check alone.
     *
     * Only nodes matching this organ's own prefix count. A node named for
     * another organ is a different problem and belongs to the export audit in
     * scripts/lib/structureNodes.mjs.
     *
     * @param  list<string>  $nodes
     * @param  array<string, string>  $claimed
     * @return list<string>
     */
    private function unclaimedNodes(string $organSlug, array $nodes, array $claimed): array
    {
        $prefix = $organSlug.'__';
        $taken = array_flip(array_values($claimed));

        return array_values(array_filter(
            $nodes,
            static fn (string $node): bool => str_starts_with($node, $prefix) && ! isset($taken[$node]),
        ));
    }

    private function readModel(string $path): ?string
    {
        // An absolute URL is a CDN-hosted model this command cannot fetch, and
        // fetching over the network in a CI check is not something to add
        // quietly. Treated as unreadable, which is what it is from here.
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return null;
        }

        $disk = Storage::disk((string) config('anatomy.model_disk'));

        try {
            if (! $disk->exists($path)) {
                return null;
            }

            $contents = $disk->get($path);
        } catch (Throwable) {
            return null;
        }

        return is_string($contents) && $contents !== '' ? $contents : null;
    }

    /**
     * Node name for a structure, mirroring scripts/lib/structureNodes.mjs.
     *
     * The one place PHP needs the convention: seeding `model_object_name` by
     * joining a manifest's node list to a structure row. Kept as a method here
     * rather than a constant elsewhere so `MeshIdentitySeeder` and this
     * verifier's `unclaimedNodes` cannot disagree about the separator.
     */
    public static function nodeNameFor(string $organSlug, string $structureSlug): string
    {
        if ($organSlug === '' || $structureSlug === '') {
            throw new RuntimeException('A mesh node name needs both an organ and a structure slug.');
        }

        return "{$organSlug}__{$structureSlug}";
    }
}
