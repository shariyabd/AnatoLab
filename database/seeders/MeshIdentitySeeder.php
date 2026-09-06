<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Organ;
use App\Services\Anatomy\MeshIdentityVerifier;
use Illuminate\Database\Seeder;
use JsonException;

/**
 * Populates `model_object_name` from the model manifest — handover 17 Branch B.
 *
 * The manifest is the contract between Branch A and this file: A exports an
 * organ with one node per structure and records every node name it wrote; this
 * joins those names onto the rows by slug. Nothing is invented here — a
 * structure gets a node name only if the manifest says the model contains one.
 *
 * **It is a no-op today, and that is the correct behaviour.** No manifest row
 * carries `structureNodes`, because no per-structure organ has been exported:
 * `docs/asset-sources.md` §3.1 records that no source has been adopted. The
 * seeder exists now so that when A lands an export, seeding is a re-run rather
 * than a piece of code someone has to write under time pressure — and so the
 * path is exercised by a test against the generated fixture instead of first
 * running against an asset that cost money.
 *
 * `anchor_position` is never touched. Handover 17 keeps the dot as the
 * permanent fallback, and a structure whose node is missing from a model must
 * still work — so this only ever writes a name, and only one the model has.
 *
 * Idempotent: re-running writes the same names, and clears a name whose node
 * has disappeared from the manifest rather than leaving a stale claim behind.
 * A stale claim is exactly what `anatomy:verify-mesh-identity` would then flag
 * as an orphan, which would be this seeder creating the failure it is meant to
 * prevent.
 */
final class MeshIdentitySeeder extends Seeder
{
    /**
     * The manifest to seed from, or null for the shipped one.
     *
     * A nullable constructor argument with a default rather than a config key:
     * `config/anatomy.php` must stay unchanged (handover 17 acceptance
     * criterion 4), and the container fills a primitive that has a default, so
     * `db:seed` still resolves this class with no binding. It exists so a test
     * can point the seeder at a manifest it wrote, instead of at the real
     * `public/models/manifest.json` — which a test must never edit.
     */
    public function __construct(private readonly ?string $manifestPath = null) {}

    public function run(): void
    {
        foreach ($this->manifestNodesByOrgan() as $organSlug => $nodes) {
            $organ = Organ::query()->where('slug', $organSlug)->first();

            if (! $organ instanceof Organ) {
                continue;
            }

            $this->applyTo($organ, $nodes);
        }
    }

    /**
     * Every structure node the manifest declares, keyed by organ slug.
     *
     * A missing or unreadable manifest is not an error. It is the state of a
     * fresh clone — `.gitignore` excludes the GLBs, and the manifest describes
     * files that may not be present — and a seeder that threw there would make
     * `migrate:fresh --seed` depend on an asset download.
     *
     * @return array<string, list<string>>
     */
    private function manifestNodesByOrgan(): array
    {
        $path = $this->manifestPath ?? public_path('models/manifest.json');

        if (! is_readable($path)) {
            return [];
        }

        try {
            /** @var array<string, mixed> $manifest */
            $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        /** @var list<array<string, mixed>> $models */
        $models = is_array($manifest['models'] ?? null) ? $manifest['models'] : [];

        $byOrgan = [];

        foreach ($models as $model) {
            $slug = $model['organSlug'] ?? null;
            $nodes = $model['structureNodes'] ?? null;

            // A row without `structureNodes` is a single-mesh organ. Skipped
            // rather than treated as "no nodes", so it never clears an identity
            // a later manifest format might carry elsewhere.
            if (! is_string($slug) || ! is_array($nodes)) {
                continue;
            }

            $byOrgan[$slug] = array_values(array_filter($nodes, 'is_string'));
        }

        return $byOrgan;
    }

    /**
     * @param  list<string>  $nodes
     */
    private function applyTo(Organ $organ, array $nodes): void
    {
        $present = array_flip($nodes);

        foreach ($organ->structures()->get() as $structure) {
            $expected = MeshIdentityVerifier::nodeNameFor($organ->slug, $structure->slug);
            $resolved = isset($present[$expected]) ? $expected : null;

            if ($structure->model_object_name === $resolved) {
                continue;
            }

            $structure->model_object_name = $resolved;
            $structure->save();
        }
    }
}
