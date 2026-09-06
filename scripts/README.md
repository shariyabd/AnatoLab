# Asset pipeline

Handover 02 §3. Turns a source anatomy model into one that is inside the performance
budget, normalised to the pivot space every `anchor_position` is authored in, and recorded
in a manifest that F03 seeds `organs.model_path` from.

```
decimate to budget → EXT_meshopt_compression → KTX2/Basis textures
→ verify normalisation to FIT_SIZE → emit manifest row
```

Nothing here touches application code. The pipeline runs on a developer's machine or in a
release step; it is not part of the request path.

---

## Commands

```bash
npm run models:encode -- <input.glb> --organ=heart --register-id=MDL-03
npm run models:verify              # re-verify the shipped set
npm run models:verify -- --release # additionally require the licence gate closed
npm run models:fixture             # regenerate the per-structure test fixture
npm run models:fixture:check       # fail if the committed fixture has drifted
```

Or directly:

```bash
node scripts/encode-model.mjs <input.glb> --organ=<slug> --register-id=<id>
node scripts/verify-models.mjs [--release]
```

`node scripts/encode-model.mjs --help` lists every option.

### Options that matter

| Option                        | Default              | Why you would change it                                                                                     |
| ----------------------------- | -------------------- | ----------------------------------------------------------------------------------------------------------- |
| `--textures=ktx2\|webp\|keep` | `ktx2`               | `webp` when KTX-Software is not installed; `keep` to isolate a geometry problem                             |
| `--texture-size=<px>`         | `2048`               | `1024` is the difference between passing and failing the payload budget on texture-heavy organs — see below |
| `--triangle-budget=<n>`       | `config/anatomy.php` | Only to explore headroom. Shipping uses the config value                                                    |
| `--dry-run`                   | off                  | Measure a source model without writing anything                                                             |
| `--no-manifest`               | off                  | Encode and verify without claiming the result is shippable                                                  |

### Prerequisite for KTX2

`--textures=ktx2` shells out to the Khronos `ktx` CLI, which is not an npm package.
Install [KTX-Software](https://github.com/KhronosGroup/KTX-Software/releases) so that `ktx`
is on `PATH`. Without it the encoder stops and says so rather than silently producing
something other than what was asked for.

Colour maps are encoded as ETC1S (`basis-lz`) and everything else as UASTC. That split is
not a preference: ETC1S is a two-endpoint format and it mangles normal maps and packed ORM
channels. The pipeline decides per texture from its glTF colour space.

WebP is the documented fallback. It fixes the three JPEG outliers on payload — which is
most of the win — but buys no GPU-side compression, so VRAM and upload cost stay where they
were.

---

## What the encoder actually does

1. **Read** with every extension registered, so nothing is silently dropped.
2. **Measure the source** — triangles counted per node instance, because a mesh drawn three
   times costs three times as much.
3. **`dedup`, `prune`, `weld`** — remove duplicate accessors and materials, drop unused
   properties, merge coincident vertices so simplification has something to work with.
4. **Decimate** to the triangle budget. Meshoptimizer treats `error` as a ceiling it will
   not exceed, so a single aggressive ratio silently under-decimates instead of failing.
   The encoder escalates through an error ladder (0.0005 → 0.05), recomputing the ratio each
   pass, and fails loudly if the loosest setting still misses. Most organs land on the first
   rung.
5. **Textures** — resize to a square target and re-encode (KTX2 or WebP).
6. **Normalise** — scale and centre the scene so its longest axis measures `FIT_SIZE`.
7. **`EXT_meshopt_compression`** on the geometry.
8. **Write, then re-read the file that was written** and verify _that_. Verifying the
   in-memory document would verify our intent; this verifies the artefact.
9. **Record a manifest row** — but only if every check passed. A manifest row is the claim
   that a file may ship, so a model that misses a budget is left on disk for inspection with
   no row and a non-zero exit code.

### Normalisation is a contract, not a nicety

Every model must fit a `FIT_SIZE = 3.8` cube centred on the origin. Every
`anchor_position` in the database is authored in that space, so a model that skips it does
not fail visibly — it puts every hotspot in the wrong place, and no migration can repair it
because the original authoring scale is not recoverable.

The audited upstream models normalise at _load time_ instead (their root node carries a
scale of ~0.49), which leaves the on-disk file un-normalised and the guarantee unverifiable.
This pipeline bakes it into the file as a single pivot node, so it can be checked
mechanically here and again at release. Running it twice is a no-op: the second pass
computes a scale of 1.

Tolerance is 0.2% of `FIT_SIZE` (≈0.0076 world units). That absorbs the ~0.00023 per axis
that 14-bit quantization introduces, and is still three orders of magnitude tighter than any
genuinely un-normalised model. In practice the measured error is at the floating-point
floor — see below.

### No number is written twice

`FIT_SIZE` is read from `resources/js/anatomy/constants.ts` at run time; the budgets are
read from `config/anatomy.php`. Neither is copied into these scripts. Change the config and
the pipeline changes with it — a second literal is a second thing that can drift, and
`FIT_SIZE` drifting is the one failure the whole normalisation contract exists to prevent.

---

## The manifest

`public/models/manifest.json`. F03 seeds `organs.model_path` from `modelPath`.

```jsonc
{
  "version": 1,
  "status": "pending-licence", // or "cleared" — the release gate
  "generatedAt": "2026-09-05T…",
  "fitSize": 3.8, // must match constants.ts
  "budgets": { "maxModelBytes": 2097152, "maxTriangles": 150000 },
  "models": [
    {
      "organSlug": "heart",
      "registerId": "MDL-03", // must exist in docs/asset-register.md
      "fileName": "heart.glb",
      "modelPath": "models/heart.glb", // relative to public/ — this is model_path
      "modelFormat": "glb",
      "bytes": 1471260,
      "sha256": "ad46005e…", // the verifier fails if the file changes without re-encoding
      "triangles": 147000,
      "vertices": 90131,
      "meshes": 1,
      "materials": 1,
      "extensions": ["EXT_meshopt_compression", "EXT_texture_webp", "KHR_mesh_quantization"],
      "textures": { "count": 3, "bytes": 512406, "maxDimension": 2048, "codecs": ["image/webp"] },
      "boundingBox": {
        "min": [-1.156, -1.9, -1.133],
        "max": [1.156, 1.9, 1.133],
        "size": [],
        "centre": [0, 0, 0],
      },
      "normalisation": {
        "fitSize": 3.8,
        "longestAxis": 3.8,
        "maxCentreOffset": 0,
        "tolerance": 0.0076,
        "ok": true,
      },
      "budget": { "bytesOk": true, "trianglesOk": true, "ok": true },
      "source": {
        "fileName": "heart.glb",
        "bytes": 3321148,
        "triangles": 386597,
        "textureCodecs": ["image/webp"],
      },
      "encodedAt": "…",
      "encoder": {
        "pipeline": "scripts/encode-model.mjs@1.0.0",
        "textures": "webp",
        "textureSize": 2048,
        "simplifyError": 0.0005,
      },
    },
  ],
}
```

`registerId` is the join to `docs/asset-register.md`. `verify-models.mjs` refuses a manifest
entry whose ID has no register row — that is what _"no asset ships without a row"_ looks
like when a machine enforces it.

`modelPath` is always relative to `public/`. Encoding to somewhere else is legitimate — that
is what an evaluation run does — but the encoder refuses to record a row for a file with no
servable path, so those runs use `--no-manifest`.

---

## What the verifier checks

`scripts/verify-models.mjs` re-verifies the set as it stands now, not as it was when it was
produced. Files get replaced by hand, budgets get tightened, manifest rows get edited.

- `fitSize` and the budgets in the manifest still match `constants.ts` and `config/anatomy.php`
- every model on disk matches its recorded `sha256`
- every model re-measures inside the payload and triangle budgets
- every model is still normalised to `FIT_SIZE`, centred, within tolerance
- every per-structure model still contains exactly the structure nodes its row promises, under
  the organ root its row names
- every `registerId` has a row in `docs/asset-register.md`
- every `.glb` in `public/models/` has a manifest row — an orphan file fails the run
- `public/draco/` and `public/basis/` do not exist (handover 02 §4: 1.6 MB of decoder
  payload that nothing referenced)

`--release` additionally requires `"status": "cleared"` and a non-empty model set. F14 runs
that form before the competition deploy.

---

## Measured results

Run 2026-09-05 against local evaluation copies of the nine upstream models, WebP textures
(KTX-Software was not installed on the machine, so the KTX2 branch is implemented but has
not been exercised end to end). **No upstream binary was committed to this repository or
deployed** — the licence question in `docs/licence-log.md` is unresolved and these numbers
exist to prove the pipeline reaches budget, not to ship anything.

Budgets: **< 2 MB payload, < 150,000 triangles**, `FIT_SIZE = 3.8`.

| Organ     |  Source | Source △ | Encoded @2048² | △ after | Longest axis | Verdict   |
| --------- | ------: | -------: | -------------: | ------: | -----------: | --------- |
| heart     | 3.17 MB |  386,597 |    **1.40 MB** | 147,000 |     3.800000 | ✓         |
| brain     | 2.45 MB |  377,692 |    **1.42 MB** | 147,000 |     3.800000 | ✓         |
| lungs     | 4.25 MB |  350,772 |    **1.89 MB** | 146,994 |     3.800000 | ✓         |
| liver     | 2.54 MB |  345,526 |    **1.54 MB** | 146,998 |     3.800000 | ✓         |
| kidneys   | 2.09 MB |  327,452 |    **1.33 MB** | 147,000 |     3.800000 | ✓         |
| eyeball   | 2.10 MB |  320,250 |    **1.38 MB** | 147,000 |     3.800000 | ✓         |
| intestine | 1.89 MB |  308,748 |    **1.13 MB** | 146,990 |     3.800000 | ✓         |
| pancreas  | 4.62 MB |  346,596 |        2.08 MB | 147,000 |     3.800000 | ✗ payload |
| skin      | 5.52 MB |  356,333 |        2.53 MB | 146,995 |     3.800000 | ✗ payload |

The two failures are two of the three JPEG outliers the audit identified
(`docs/project-context.md` §2.6). Re-run at `--texture-size=1024`:

| Organ    | Encoded @1024² | △ after | Verdict |
| -------- | -------------: | ------: | ------- |
| pancreas |    **1.31 MB** | 147,000 | ✓       |
| skin     |    **1.56 MB** | 146,995 | ✓       |

**All nine reach budget.** Seven at 2048², two at 1024². Total payload falls from 28.6 MB
to roughly 12.9 MB and total geometry from 3.12 M triangles to 1.32 M.

Measured centre offset after normalisation was `0` or `2.22e-16` — the floating-point floor
— on every model, against a tolerance of 0.0076. Skin needed a second rung of the error
ladder (0.001); every other organ decimated on the first.

Two things this run does **not** establish: whether these models may legally ship
(`docs/licence-log.md`), and whether decimating a single-mesh generative model to 147k
triangles preserves anything a student should be taught from (`docs/asset-register.md` §2,
flagged to F03).

---

## The per-structure test fixture

`scripts/make-structure-fixture.mjs` writes `tests/Fixtures/models/heart-per-structure.glb`
and a manifest beside it. It exists because handover 17 orders its branches A → B → C —
B needs A's node names, C needs both — and that serialisation is real for shipped assets
but entirely avoidable for tests. What B and C actually need from A is one GLB with
per-structure nodes, and nothing requires it to be anatomically sourced.

**It is not anatomy.** It is nine spheres at the heart's authored anchor positions, and
nothing should ever present it to a student. What makes it useful is that everything
around the geometry is real: the `<organ-slug>__<structure-slug>` node convention, the
organ-root grouping, FIT_SIZE normalisation, the budget check, and the manifest shape
Branch B seeds `model_object_name` from. Tests written against it keep passing when a
licensed model replaces it.

It also exercises no rights. `docs/asset-sources.md` §3.1 records that no source has been
adopted; this generator takes from none, because the vertices are generated here.

| Property        | Value                                                                              |
| --------------- | ---------------------------------------------------------------------------------- |
| Structure nodes | 9, matching `AnatomySeeder`'s heart slugs                                          |
| Root node       | `heart`, with the pipeline's `anatolab_normalised_pivot` above it                  |
| Size            | ~41 KB, 1,728 triangles — three orders of magnitude inside budget                  |
| Normalisation   | longest axis exactly 3.8, centred on the origin                                    |
| Materials       | one, shared across the organ                                                       |
| Anchors         | in each node's translation, not baked into vertices — as a Blender export produces |

Two things keep it honest. `--check` regenerates and compares bytes, so a hand-edited GLB
or an un-rerun generator fails; and `resources/js/anatomy/testing/perStructureParity.test.ts`
holds the in-memory Three.js fixture to the same manifest, so the Vitest half and the Pest
half cannot describe different organs.

### The naming convention

`scripts/lib/structureNodes.mjs` is the one implementation of
`<organ-slug>__<structure-slug>`, because it is the contract three branches join on: A
names the nodes, B seeds from them, C raycasts against them. It refuses a slug rather than
sanitising one — a slug that needs cleaning up is a slug that does not match the database
row it addresses, and repairing it quietly produces a model whose nodes look right and
join to nothing.

`auditStructureNodes()` reports the three failures an export actually produces — a node
named for a different organ, the same structure named twice, and a mesh node following no
convention at all — together rather than one at a time, because each round trip is a trip
back to Blender.

The viewer does not import any of this. It receives `modelObjectName` in the DTO and hands
it to `getObjectByName`, so the string stays opaque on that side and one copy is enough.

### What the encoder enforces on a per-structure export

`describeStructureExport()` decides whether a file is a per-structure export and then judges
it. The encoder runs it twice: once on the source before any expensive work, so a naming
mistake costs seconds rather than a decimation run, and once on the file that was actually
written, which is the authoritative pass.

**The trigger is not a flag.** A file is judged as per-structure when it either names
structures _or_ carries more than one mesh node. That second clause is the point: an export
whose object names were all mangled in Blender claims no structures at all, so a naming-only
audit would find nothing to report and ship a model where every structure is unselectable. A
genuine single-mesh organ — one mesh node, claiming nothing — stays exempt, which is what
keeps the nine Tripo models encodable while the conversion is in progress.

It fails a run for:

- a node named for a different organ than the one being encoded
- the same structure named twice, which makes `getObjectByName` a coin toss
- a mesh node following no convention, which is a structure nobody can ever select
- structures that share no common ancestor, so the organ has no root to transform as a unit
- structures parented straight onto the normalisation pivot, which is the same failure
  wearing the pipeline's own node as a disguise
- an unnamed organ root, which the manifest has no way to record

Grouping deeper is fine. `heart` → `heart-valves` → the valve meshes is a reasonable
outliner, and the check is a nearest common _ancestor_, not a shared parent.

More material slots than structures is a **warning**, not a failure. §15.1 states no budget
for materials, so inventing one here would be inventing a gate; but per-structure slots
cannot outnumber the structures, and each one is a draw call.

### `rootNode` and `structureNodes` — the contract with Branch B

A per-structure manifest row carries two extra keys:

```jsonc
{
  "organSlug": "heart",
  "rootNode": "heart",
  "structureNodes": ["heart__left-ventricle", "heart__aorta", …],
}
```

`Database\Seeders\MeshIdentitySeeder` reads exactly these and joins them onto
`anatomical_structures.model_object_name` by slug. Nothing else populates that column, so
the manifest is the contract, and both sides police it: `verify-models.mjs` fails when a
file has lost a node the manifest promises (every one is a structure whose
`model_object_name` now points at nothing) or gained one it does not list (a structure the
model could teach and the database will never know about), and
`php artisan anatomy:verify-mesh-identity` catches the same drift from the database side
after seeding.

**A single-mesh organ gets neither key**, rather than an empty list. The seeder reads an
empty `structureNodes` as "this model has no structures" and would clear every claim on it.

One thing the pipeline does that is worth knowing before reading a manifest: `dedup` merges
byte-identical meshes, so a nine-structure organ built from one repeated shape reports
`"meshes": 1` with nine structure nodes. Identity lives on the **node**, not the mesh, and
survives the whole encode — dedup, prune, weld, decimation and meshopt — intact. The fixture
encodes end to end from 41.5 KB to 4.6 KB with all nine node names present.
