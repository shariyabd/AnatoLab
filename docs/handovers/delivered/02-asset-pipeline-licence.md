# Handover 02 — Anatomy Asset Pipeline & Licence Register (delivered)

**Branch** `feat/f02-asset-pipeline` · **Contract** [`02-asset-pipeline-licence.md`](../02-asset-pipeline-licence.md) · **Status** delivered

## What shipped

A repeatable model encoding pipeline, a machine-enforced release gate, and the two
documents that record provenance. The licence question itself is **not closed** —
`docs/licence-log.md` records the decision as *not yet taken*, no model ships, and
`public/models/manifest.json` is `"status": "pending-licence"` with an empty `models`
array. The pipeline was measured against local evaluation copies of the nine upstream
GLBs; none of those binaries was committed or deployed.

- Encode a source GLB to budget: `npm run models:encode -- <in.glb> --organ=<slug> --register-id=<id>`
- Re-verify the shipped set at any time: `npm run models:verify`, or `-- --release` for the gate
- A manifest that Handover 03 seeds `organs.model_path` from (`modelPath`, relative to `public/`)
- An asset register with a row per asset, joined to the manifest by `registerId`
- A licence audit trail with a drafted, unsent request and an escalation trigger

## Public surface

### Scripts

| File | Responsibility |
|---|---|
| `scripts/encode-model.mjs` | One source model → decimate, re-texture, normalise, meshopt, verify, write manifest row |
| `scripts/verify-models.mjs` | Re-verify the whole set as it stands now; `--release` adds the licence gate |
| `scripts/lib/config.mjs` | Reads `FIT_SIZE` from `constants.ts` and budgets from `config/anatomy.php` — no literals |
| `scripts/lib/io.mjs` | One `NodeIO` with every extension registered, shared by encoder and verifier |
| `scripts/lib/measure.mjs` | Pure measurement: per-node-instance triangles, bounds, budget and normalisation checks, file hash |
| `scripts/lib/normalise.mjs` | Wraps the scene in a single `anatolab_normalised_pivot` node scaled to `FIT_SIZE` |
| `scripts/lib/textures.mjs` | KTX2 via the Khronos `ktx` CLI (ETC1S for colour, UASTC otherwise); WebP fallback |
| `scripts/lib/manifest.mjs` | Manifest read/write; statuses `pending-licence` \| `cleared` |

### Manifest (`public/models/manifest.json`)

| Field | Value today | Notes |
|---|---|---|
| `version` | `1` | |
| `status` | `pending-licence` | `--release` fails unless `cleared` |
| `fitSize` | `3.8` | Verifier fails if it drifts from `constants.ts` |
| `budgets.maxModelBytes` | `2097152` | From `config/anatomy.php` |
| `budgets.maxTriangles` | `150000` | From `config/anatomy.php` |
| `models` | `[]` | Nothing is cleared to ship |

Per-model rows carry `organSlug`, `registerId`, `modelPath`, `modelFormat`, `bytes`,
`sha256`, `triangles`, `boundingBox`, `normalisation`, `budget`, `source`, `encoder`.

### Documents

| File | What it records |
|---|---|
| `docs/asset-register.md` | One row per asset. MDL-01…09 `BLOCKED`, ILL-01 `BLOCKED`, IMG-01/DEC-01/DEC-02 `EXCLUDED`, DEP-01…03 `CLEARED`, CAN-01…03 `CANDIDATE`. §6 is the unsigned deployment sign-off |
| `docs/licence-log.md` | The finding re-verified against the GitHub API, the drafted request, the correspondence log, and the grant-vs-replace decision record |
| `scripts/README.md` | Pipeline usage, options, the normalisation contract, and the measured run |

## Key decisions

- **No number is written twice.** `FIT_SIZE` is read out of `resources/js/anatomy/constants.ts`
  and the budgets out of `config/anatomy.php` at run time, because "a second literal is a
  second thing that can drift, and `FIT_SIZE` drifting is the one failure the whole
  normalisation contract exists to prevent."
- **Normalisation is baked into the file, not applied at load.** The audited upstream models
  carry a ~0.49 root scale instead, "which leaves the on-disk file un-normalised and the
  guarantee unverifiable." A single pivot node is exact, survives quantization, and re-running
  is a no-op. Tolerance is 0.2 % of `FIT_SIZE` (≈0.0076).
- **A manifest row is a shippability claim.** A model that misses a budget is left on disk for
  inspection with no row and a non-zero exit code; `registerId` must resolve to a register row,
  which is what "no asset ships without a row" looks like when a machine enforces it.
- **Verify the artefact, not the intent.** The encoder writes the file, re-reads it, and
  verifies that — quantization and meshopt happen between the two.
- **The escalation trigger is dated (2026-09-19)** and the replace path is pre-evaluated, so a
  "no" costs a re-encode rather than a re-plan.
- **The request is drafted but deliberately unsent** — it is outbound correspondence in a
  person's own name, so a named human must send it.

## Invariants honoured

- **5 — `FIT_SIZE` is 3.8.** `scripts/lib/config.mjs#readFitSize()` parses it from
  `constants.ts`; `scripts/verify-models.mjs` fails if the manifest's `fitSize` disagrees with
  that file or if any model re-measures outside tolerance. The pipeline is the third checked
  copy alongside `tests/Unit/FitSizeParityTest.php` and `resources/js/anatomy/fitSizeParity.test.ts`.
- **No application code touched.** The phase adds only `scripts/**`, `docs/**` and
  `public/models/manifest.json`, as the ownership boundary required.

## Tests

Pipeline verification rather than a unit suite, as the contract specified.

| Where | What it proves |
|---|---|
| `scripts/encode-model.mjs` (steps 8–9) | The written file — not the in-memory document — is inside both budgets and normalised, or no manifest row is emitted and the run exits non-zero |
| `scripts/verify-models.mjs` | `fitSize` and budgets still match their sources; every file matches its recorded `sha256`; every model re-measures inside budget and normalisation; every `registerId` has a register row; no orphan `.glb`; `public/draco/` and `public/basis/` absent |
| `scripts/verify-models.mjs --release` | Additionally requires `"status": "cleared"` and a non-empty model set — the gate Handover 14 runs before deploy |
| `scripts/README.md` §"Measured results" | All nine upstream models reach budget: seven at 2048², pancreas and skin at 1024². Payload 28.6 MB → ~12.9 MB, geometry 3.12 M → 1.32 M triangles, centre offset at the floating-point floor |

## Known gaps / follow-ups

- **The licence gate is open.** `docs/licence-log.md` §4 records the decision as *not yet
  taken*; §3 row 2 records the request as drafted and **not sent**, pending a named human.
  `docs/asset-register.md` §6 is unsigned. Nothing may deploy publicly until both are filled in.
- **No model ships.** `models` is empty and `.gitignore` excludes `/public/models/*.glb`.
  Handover 03 therefore seeds placeholder `model_path` values (`models/heart.glb`,
  `models/lungs.glb`, `models/brain.glb`); closing the gate is a one-commit swap of those values.
- **The KTX2 branch is implemented but unexercised.** KTX-Software was not installed on the
  measuring machine, so the whole measured run used the WebP fallback — which fixes payload but
  buys no GPU-side compression. Reintroducing a transcoder is Handover 04's call.
- **Two defects survive any licence outcome.** The upstream geometry is single-mesh, so
  per-structure selection is impossible against it regardless of rights; and it is generative
  output of unverified anatomical accuracy, flagged to Handover 03's owner and not decided here.
- **If replacement is chosen** (CAN-03, NIH 3D HuBMAP, is the recommendation), Handover 04's
  scope changes from *adapt* to *reimplement* and Handovers 05/07 gain real mesh raycasting.
  Handover 04 has already defaulted to reimplementation, so that half is absorbed.
- **DEP-02 caveat unresolved.** GSAP ships under a bespoke non-OSI licence and is used by the
  viewer for camera easing; the register asks for a human to confirm the terms before deployment.
