# Handover 02 — Anatomy Asset Pipeline & Licence Register

**Feature:** F02 · **Lane:** Content/Platform · **Wave:** 1 (parallel with 01) · **Branch:** `feat/f02-asset-pipeline`

> Read first: `docs/project-context.md` §2.2, §2.6, §3 — the audit that produced this work.

## Objective

Produce a set of anatomy models that are **legally shippable** and **inside the performance
budget**, and record the provenance of every asset. This is the release gate for the whole
project: nothing deploys publicly until it closes.

## Scope

- Resolve the licence question and record the decision
- `docs/asset-register.md` — one row per model, texture, and illustration
- A repeatable model encoding pipeline
- The shipped model set + a manifest F03 seeds `organs.model_path` from

## Out of scope

Application code of any kind. You do not touch `app/`, `resources/js/`, or any migration.

## Dependencies / prerequisites

**None — start on day one.** You do not need F01. This runs entirely beside it.

## Existing code to reuse

The upstream repository's nine GLBs are the starting candidates, **conditional on §3 below**.
They are Tripo AI generative output, single-mesh, `EXT_meshopt_compression` +
`KHR_mesh_quantization`, no animation clips.

## Ownership boundaries

**You own** `docs/asset-register.md`, `scripts/encode-model.*`, `public/models/**` (or the S3
bucket), and the licence correspondence log.

**You must not** touch application code. Where an asset decision changes code scope, raise it
with F04's owner — do not implement the change yourself.

## Required implementation

### 1. Licence resolution — do this first

The upstream repo has **no `LICENSE` file** and the GitHub API reports `license: null`. That
is all-rights-reserved by default; 3,158 stars and 837 forks grant nothing.

- Contact `thebuggeddev`. Request an explicit written grant covering (a) source under a
  permissive OSI licence and (b) the models for modification and redistribution. Log the
  request date and any response.
- **Do not block on the answer.** In parallel, evaluate the replacement candidates:
  **BodyParts3D / Anatomography** (CC BY-SA 2.1 JP), **Z-Anatomy** (CC BY-SA, Blender source),
  **NIH 3D Print Exchange** (mostly public domain / CC0).
- All three ship **per-structure meshes**, which would also resolve the single-mesh
  limitation in `docs/project-context.md` §2.2. Treat that as an upside, not a cost.

**Escalate the moment a "no" or a two-week silence looks likely.** The fallback is weeks of
work and must not be discovered in Wave 5.

### 2. Asset register

`docs/asset-register.md`, one row per asset: source · creator · licence · modification
rights · redistribution rights · commercial/public use · attribution string. **No asset ships
without a row.** Attribution surfaces in the app's About page (F14 renders it).

CC BY-SA obligations attach to the models and derivative models, not to our application code
— but the notice and attribution must ship.

### 3. Encoding pipeline

`scripts/encode-model.*`, repeatable and documented:

```
decimate to budget → EXT_meshopt_compression → KTX2/Basis textures (1024² or 2048²)
→ verify normalisation → emit manifest row
```

**Budgets (`docs/architecture.md` §15.1): < 2 MB payload, < 150k triangles per organ.**
Current upstream state, measured: 2.0–5.8 MB and 308–387k triangles — every model needs work.
Three organs (lungs, pancreas, skin) ship raw JPEG at 5–8× their peers' texture weight; those
are the worst offenders.

**Normalisation is a hard contract.** Every model must fit a `FIT_SIZE = 3.8` cube centred on
the origin. Every `anchor_position` in the database is authored in that space — a model that
skips normalisation silently invalidates all of them. Verify per model and fail the pipeline
if it does not hold.

### 4. Cleanup

Delete `public/draco/` (1.06 MB) and `public/basis/` (585 KB) if carried over — the upstream
loader references neither. Reintroduce a KTX2 transcoder **only** if you actually encode to KTX2.

## Tests

Pipeline verification, not unit tests:

- Each shipped model: triangle count, file size, bounding box after normalisation — recorded
  in the manifest and asserted by the script.
- A checked-in script re-verifies the whole set so F14 can confirm the budget at release.

## Acceptance criteria

1. Every shipped model is < 2 MB and < 150k triangles.
2. Every model is normalised to `FIT_SIZE = 3.8`, verified mechanically.
3. Every asset has an asset-register row with a licence permitting public deployment.
4. The grant-vs-replace decision is recorded with its date and rationale.
5. A manifest exists that F03 can seed `organs.model_path` from.
6. No unreferenced decoder payload ships.

## Constraints and guardrails

- **Never assume open source means redistributable.** Code and assets are audited separately.
- Do not ship an asset whose provenance you cannot state.
- Tripo-generated models are of **unverified anatomical accuracy** — flag this to F03's owner,
  who owns the content correctness decision.
- If the licence fails and replacement models are adopted, say so loudly: F04's scope changes
  from *adapt* to *reimplement*.

## Definition of Done

The register is complete, the budget holds for every model, and a named human has signed off
that the asset set may be deployed publicly.

## Commit boundary

`docs(assets): licence register and audit trail`, `feat(assets): model encoding pipeline`,
`chore(assets): encoded model set + manifest`. The licence decision lands as its own commit
so it is easy to find later.
