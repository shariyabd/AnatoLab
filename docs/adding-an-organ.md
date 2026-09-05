# Adding an Organ

How to put a new organ into AnatoLab: where to get a model you are allowed to use, what to
collect with it, and the five steps from a downloaded file to a working page.

Nothing here is theoretical — every command below is one this repository runs.

---

## 0. The five steps

| # | Step | Command / file | Takes |
|---|---|---|---|
| 1 | Clear the licence and write the register row | `docs/asset-register.md` | 20 min, mostly reading |
| 2 | Encode the model to budget | `npm run models:encode` | ~1 min per model |
| 3 | Write the organ's content | `database/seeders/AnatomySeeder.php` | the real work |
| 4 | Place the hotspots on the mesh | `/admin/authoring/<slug>` | ~10 min per organ |
| 5 | Verify | `npm run models:verify` + `composer test` | ~2 min |

Steps 1–2 are mechanical. **Step 3 is where the time goes**, because it is anatomy writing,
not code. Step 4 is what makes the markers land on the right anatomy instead of near it.

---

## 1. Where to get a model

### The rule

**A model without a written licence cannot ship.** Not "probably fine", not "it's on
GitHub". `docs/licence-log.md` exists because this repository already learned that the hard
way: the nine upstream models it started from carry no licence at all, which is why
`public/models/manifest.json` still reads `"status": "pending-licence"` and why nothing
deploys publicly until a human signs `docs/asset-register.md` §6.

### Recommended source — NIH 3D

`https://3d.nih.gov/` — specifically the HuBMAP Human Reference Atlas "Organ, Sex" reference
set. `docs/asset-register.md` §5 evaluated three sources properly (archives downloaded and
parsed, not read off a summary page) and this is the recommendation:

- **CC BY**, no share-alike. Your own code stays under whatever licence you choose.
- **Native GLB** with **named per-structure meshes** — a heart with fourteen named parts,
  not one blob. This is the thing the current models cannot do.
- Polycounts 12,886–346,625, already inside `encode-model.mjs`'s decimation range.
- 9/9 coverage of the organs shipped today, across 18 catalogue entries (IDs are listed in
  the register).

Two catches, both real:

- **Licence varies per model across the wider catalogue.** In the Anatomy category, ~19% are
  NC or ND and unusable. `3DPX-000900` "Heart (CT)" is CC BY-NC, for example. Check the
  entry you are downloading, every time. NIH states it does not enforce licence terms on
  contributors' behalf.
- **No bulk download and no supported API.** Harvest entries one at a time by hand. Two
  undocumented endpoints on the current site do work; treat them as a one-time harvest and
  never as a runtime dependency.

### Other sources worth knowing

| Source | Licence | Verdict |
|---|---|---|
| **BodyParts3D / Anatomography** (DBCLS) | Contested: the archive page says CC BY 4.0, the files' own headers say CC BY-SA 2.1 JP | Fallback, only if DBCLS confirms the relicence in writing. OBJ only, no UVs or textures, and its 1,258 elements are shared between concepts rather than partitioning them |
| **Z-Anatomy** | CC BY-SA 4.0, but contaminated | **Rejected.** Its kidney is CC BY-NC by a third party, which cannot legally sit inside a CC BY-SA work — and there is no skin at all |
| Sketchfab / TurboSquid / CGTrader | Per-item | Usable, but read the item licence: "free" routinely means non-commercial or no-redistribution. An educational deployment is still a deployment |
| Tripo / Meshy / other generative AI | Per-tool ToS | The models this project started from are Tripo output. They look convincing and are **anatomically unverified**, single-mesh, and hollow. Fine for layout, not for teaching |

### What disqualifies a model

- No licence file or statement anywhere → **stop**
- `NC` (non-commercial) → stop, unless you are certain the deployment never touches
  commerce, and record who decided that
- `ND` (no derivatives) → stop. Encoding it *is* a derivative
- `SA` (share-alike) → allowed, but it propagates to what you publish. A deliberate choice,
  not an accident
- Licence only in a screenshot or a forum post → get it in the repository or in an email

---

## 2. What to collect

Before you touch the pipeline, gather these. The register row cannot be written without
them, and the register row is what `verify-models.mjs` enforces.

**About the file**

- [ ] The model file itself — `.glb` preferred, `.gltf` accepted
- [ ] Source URL, and the DOI if there is one
- [ ] Creator name, as they spell it
- [ ] Licence, by SPDX identifier or full name — and the URL where you read it
- [ ] The **verbatim attribution string** the licence demands. NIH 3D gives you one per
      model in `attributionInstructions`, including an access date you must set to yours
- [ ] Download date

**About the anatomy** — this is the part people forget, and it is the part that takes time:

- [ ] The list of structures you intend to label, with the **Terminologia Anatomica (TA2)
      Latin term** for each. `Ventriculus sinister`, not "left ventricle". The TA term is the
      canonical identity across locales, and the RAG filter and question bank key off it
- [ ] For each structure: what it is, what it does, where it sits — in your own words,
      correct, written for a 15-year-old
- [ ] A difficulty rating 1–5 per structure
- [ ] Which body system the organ belongs to

**No textures needed.** The pipeline re-encodes them. **No normalisation needed.** The
pipeline bakes it.

---

## 3. Step by step

### Step 1 — register the asset

Add a row to `docs/asset-register.md` §2 with a fresh `MDL-nn` ID. This is not paperwork:
`scripts/verify-models.mjs` **refuses a manifest entry whose register ID has no row**, so an
unregistered model fails the build. That is what "no asset ships without a row" looks like
when a machine enforces it.

Record source, creator, licence, attribution string, and status.

### Step 2 — encode

```bash
npm run models:encode -- /path/to/spleen.glb --organ=spleen --register-id=MDL-10 --textures=webp
```

The encoder decimates to the triangle budget, re-encodes textures, **normalises the mesh
into the `FIT_SIZE = 3.8` cube**, applies `EXT_meshopt_compression`, writes to
`public/models/spleen.glb`, then re-reads the file it just wrote and verifies *that* rather
than its own intent. A model that misses a budget is left on disk for inspection with **no
manifest row** and a non-zero exit code.

Expect output like:

```
  ✓ payload     1.40 MB of 2.00 MB
  ✓ triangles   147,000 of 150,000
  ✓ normalised  longest axis 3.800000, centre offset 0.00e+0
  ✓ spleen.glb recorded in public/models/manifest.json
```

**If it fails on payload**, drop the texture size — this is the usual fix and it is what
pancreas and skin needed:

```bash
npm run models:encode -- ./spleen.glb --organ=spleen --register-id=MDL-10 --textures=webp --texture-size=1024
```

Useful flags: `--dry-run` measures without writing; `--no-manifest` encodes without claiming
the result is shippable; `--textures=ktx2` is better but needs the Khronos `ktx` CLI on
`PATH`, which is not an npm package.

> **Why normalisation matters.** Every `anchor_position` in the database is authored in the
> `FIT_SIZE = 3.8` cube. A model that skips normalisation does not fail visibly — it puts
> every hotspot on that organ in the wrong place, and no migration can repair it, because the
> original authoring scale is not recoverable. Running the encoder twice is a no-op: the
> second pass computes a scale of 1.

### Step 3 — write the organ

Two edits in `database/seeders/AnatomySeeder.php`:

1. If the body system is new, add it to `bodySystems()`.
2. Add a `private function spleen(): array` and list it in `organs()`.

Copy the shape of an existing method — `heart()` is the fullest. Required keys, in order:

```php
'slug', 'name', 'scientific_name', 'body_system', 'accent_color',
'model_path', 'thumbnail_path', 'description', 'structures', 'relations'
```

and per structure:

```php
'slug', 'ta_term', 'name', 'description', 'function', 'location',
'difficulty', 'anchor_position', 'marker_color'
```

Rules the tests enforce:

- **`model_path` is `models/<slug>.glb`** — relative to `public/`, matching `modelPath` in
  the manifest. These two must agree or the viewer resolves a URL for a file that is not
  there.
- **At least 8 published structures.** `AnatomySeederTest` fails an organ with fewer, with
  the message *"too few structures for a credible quiz"* — because the spatial quiz draws its
  targets per organ and four structures means a student sees the same four over and over.
- **Every structure needs a non-empty `ta_term` and a 3-element `anchor_position`.**
- **`model_object_name` stays null** while the models are single-mesh. If you adopt NIH 3D's
  per-structure meshes, this is the field that finally gets a value, and real mesh
  raycasting becomes possible.
- `difficulty` is 1–5. `relations` uses `Adjacent`, `PartOf`, `FlowsInto`, `Counterpart`;
  reserve `FlowsInto` for genuine directional flow — blood, air, bile, urine, chyme. Light
  through an eye is not a flow.

The seeder is idempotent by slug, so re-running it is safe.

```bash
php artisan migrate:fresh --seed
```

### Step 4 — place the hotspots

Coordinates written from reasoning land *near* the right anatomy. Coordinates clicked on the
mesh land *on* it. The difference is visible immediately and it is the difference between a
quiz that teaches and one that frustrates.

1. Sign in as an admin and open `/admin` → the authoring tool.
2. Pick the organ. The viewer loads in `author` mode.
3. Click a point on the model. It raycasts, snaps to the nearest surface vertex, and emits
   the coordinate in `FIT_SIZE` pivot space.
4. Name the structure it belongs to and save.

The tool records **which model file version each coordinate was authored against** and warns
you when the model changes underneath it — because re-encoding a model can move the surface,
and only that fingerprint knows.

### Step 5 — verify

```bash
npm run models:verify     # budgets, hashes, normalisation, register rows, orphan files
composer test             # the seeder assertions
```

`models:verify` re-measures the set **as it stands now**, not as it was when produced. It
also fails on a `.glb` in `public/models/` with no manifest row, so a stray file cannot ride
along unnoticed.

Then look at it: `/explore/<slug>`.

---

## 4. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| **"The 3D model could not be downloaded."** | The URL points somewhere nothing is listening. Usually a disk misconfiguration: `config('anatomy.model_disk')` must be `models` (web root, `public/`), **not** `public` (which is `storage/app/public`, reached through the storage symlink — a different directory the pipeline never writes to) | Check `config/filesystems.php`; confirm the payload's `modelUrl` is `/models/<slug>.glb` and that it 200s |
| **"The 3D model for this organ is not available."** | A genuine 404. The file is not in `public/models/` | Re-run `models:encode`; check `model_path` matches the manifest's `modelPath` |
| **"The 3D model could not be decoded."** | Geometry is `EXT_meshopt_compression` and the decoder is missing, or textures are KTX2 with no Basis transcoder deployed | Encode with `--textures=webp`. `gltfLoader.ts` already wires `MeshoptDecoder` |
| Model loads, **hotspots are in the wrong place** | Anchors authored against a description, or against a different model version | Step 4. The authoring tool tells you which anchors are unverified |
| Model loads **tiny or enormous** | Not normalised — encoded outside the pipeline | Re-run `models:encode`; never hand-place a file in `public/models/` |
| `models:verify` fails on **sha256** | The file changed without re-encoding | Re-encode. Do not hand-edit the manifest |
| `models:verify` fails on **register ID** | No `MDL-nn` row | Step 1 |
| Encoding fails on payload | Textures too large | `--texture-size=1024` |

---

## 5. The honest state of things

Two facts about the models shipped today, both recorded in `docs/project-context.md` §2.2
and `docs/asset-register.md`:

**They are single-mesh.** One node, one mesh, one primitive, one material each — Tripo AI
generative output. There is no `Heart_LeftVentricle` object. Per-structure raycasting,
isolation and layer visibility are impossible against that geometry no matter who owns it.
Selection works by screen-space distance to an authored hotspot, which is a sound answer to
the constraint, and it is why `anchor_position` carries the weight it does.

**They are anatomically unverified, and decimated to 147k triangles on top of that.** Whether
that preserves anything a student should be taught from is an open question, flagged in the
register and not yet answered.

Both problems have the same fix, and it is step 1 of this document: adopt a source with
per-structure meshes. `docs/asset-register.md` §5 recommends one and lists the entry IDs. If
that happens, `model_object_name` stops being null, the viewer gains real raycasting, and
this guide's step 4 gets much shorter.
