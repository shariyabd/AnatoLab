# Handover 17 — Per-Structure Anatomy & True Structure Interaction

**Feature:** F17 · **Lane:** Asset + Viewer + Anatomy · **Wave:** 9
**Branches:** three, see Ownership

> Read first: handover 02 §1 · handover 03 §"Structure identity" ·
> handover 04 §"Reduced semantics are the contract" ·
> `docs/project-context.md` §2.2, §3

## Objective

Replace single-mesh models with per-structure geometry, and turn on the
interactions that were impossible without it: **hover a structure and see its
name**, isolate it for real, and peel layers.

This is the handover that removes the "reduced semantics" clause from F04. It
is not a polish pass — it changes what the product can do.

## Why this is possible now

F04's reduced semantics were correct *for single-mesh Tripo models*: one node,
one mesh, one material, so there was nothing per-structure to address.
`model_object_name` was left nullable in F03 precisely so that per-structure
models could later be adopted **with no schema and no API change**. This
handover cashes that in.

## Source decision — do this first and record it

**Primary: Z-Anatomy** (CC BY-SA, Blender source, full labelled body,
per-structure meshes, Latin/TA naming already applied).
**Fallback: BodyParts3D / Anatomography** (CC BY-SA 2.1 JP) for anything
Z-Anatomy lacks.
**Fallback: NIH 3D** (largely public domain) for isolated specimens.

Pick one primary and stay on it. Mixing sources per-organ produces
inconsistent scale, topology, and naming, and the product looks assembled
rather than made.

Before any export work, record in `docs/asset-register.md`:
- the chosen source and its licence text
- the attribution string that F14's page will render
- an explicit note that CC BY-SA share-alike attaches to the **models and
  derivative models**, not to this application's source code
- the date and rationale

If the project's distribution model cannot accept share-alike on the models,
**stop and escalate now** — not after 60 organs are exported.

## Ownership — three branches, land in this order

**Branch A — `feat/f17a-per-structure-assets`** (F02 lane)
Owns `scripts/`, `public/models/**`, `docs/asset-register.md`.

**Branch B — `feat/f17b-mesh-identity`** (F03 lane)
Owns the anatomy seeder and `AnatomyService`. Populates `model_object_name`.
**Creates no migration** — the column already exists and is nullable.

**Branch C — `feat/f17c-structure-interaction`** (F04 + F05 lanes)
Owns `resources/js/anatomy/**` and the Explore page's hover presentation.

C cannot start until A publishes one exported organ and B publishes its
`model_object_name` values. Use the heart as the pilot for all three.

---

## BRANCH A — Asset export

Export from Blender per organ, through the existing F02 encoding pipeline:

- **One node per anatomical structure**, named deterministically. Establish a
  naming convention in the first commit and never deviate:
  `<organ-slug>__<structure-slug>` → `heart__left-ventricle`.
  Lowercase, kebab, double underscore separator. No spaces, no Latin
  diacritics in node names — the TA term lives in the database, not the mesh.
- Structures grouped under one organ root node so the whole organ transforms
  as a unit.
- **`FIT_SIZE = 3.8` normalisation applies to the organ root**, not per
  structure. Verify mechanically and fail the pipeline if it does not hold.
- Existing budget stands: **< 2 MB, < 150k triangles per organ.** Per-structure
  meshes add node overhead — decimate accordingly. If an organ cannot make
  budget, split it or reduce structure count; do not raise the budget.
- Materials: one shared material per organ by default, with per-structure
  material slots only where the source already provides them. Sixty materials
  per organ will destroy the draw-call budget.
- Emit a manifest row per organ listing **every structure node name it
  contains**. Branch B seeds from this file; it is the contract between you.

Deliverable for the pilot: the heart, exported, budget-verified, with its node
list published in the PR.

---

## BRANCH B — Mesh identity

For every structure, populate `model_object_name` with the node name from A's
manifest. `slug`, `ta_term`, and `anchor_position` all stay — nothing is
removed.

**`anchor_position` remains the fallback, permanently.** A structure whose mesh
node is missing from a model must still work as a dot. Mixed-mode organs are
expected during migration and are not a bug.

Add to `StructureResource`: `model_object_name` (nullable, already in
`types.ts` — do not change that file).

Validation command, checked in and run in CI:
`php artisan anatomy:verify-mesh-identity` — for every published structure with
a non-null `model_object_name`, assert that node exists in the organ's GLB.
A rename in Blender that silently orphans 40 structures is the single most
likely failure mode in this handover.

---

## BRANCH C — Structure interaction

This is the part the user actually sees.

### Picking

Replace screen-space dot picking with **raycast against structure meshes**,
falling back to dot picking when `model_object_name` is null.

Raycasting every frame is not acceptable — it breaks render-on-demand.
Raycast on `pointermove` **throttled to ~60ms**, and only when the pointer is
over the canvas. Use a `Layers` mask so only structure meshes are tested.

### Hover

- Hovered mesh: emissive lift and a slight tint toward the organ accent.
  Subtle — this is a highlight, not a selection.
- Cursor becomes `pointer`.
- A small label chip follows the cursor with the structure's **common name**,
  positioned imperatively by the viewer. **Zero Vue re-renders on hover** — the
  same rule as the callout. If you bind hover state to a `ref`, a mouse sweep
  across the heart will re-render the page a hundred times.
- Hover marks the frame dirty, renders once, and stops. It must not start a
  continuous loop.
- Emit `structure:hovered { structureId | null }` — the event already exists in
  the §5.2 interface. Do not add a method.
- Debounce out: a 120ms delay before clearing, so sweeping between adjacent
  structures does not flicker the chip.

### Touch

There is no hover on touch. On coarse pointers, first tap selects and shows the
callout; the hover chip never renders. Detect with
`matchMedia('(hover: hover)')`, not user-agent sniffing.

### Isolate — now real

`isolateStructure(id)` hides or fades non-target meshes and flies the camera.
This is the behaviour the method always promised. **Stop emitting
`capability:degraded` for it** on per-structure organs — and keep emitting it
for any organ still on a single mesh.

### Layers — now real

`setLayer()` gains meaningful values beyond `wireframe` where the source
supports it (e.g. `superficial` / `deep`). Drive this from data in the organ
manifest, not from hardcoded structure lists in TypeScript.

### F15's tool rail

Focus and Wireframe stop being hidden on per-structure organs. The rail is
already capability-driven, so this requires **no rail code change** — verify
that, and if it does require one, the rail was built wrong.

### Accessibility

Every hover-reachable structure stays keyboard-reachable through the existing
`<ul>` structure index, with the same highlight on focus. Hover is an
enhancement, never the only path.

---

## Tests

- Vitest: raycast picking returns the correct `structureId` for a known node
- Vitest: a structure with null `model_object_name` still selects via its dot
- Vitest: `capability:degraded` fires for a single-mesh organ and **does not**
  fire for a per-structure organ
- Vitest: hover renders exactly one frame and does not start a loop
- Vitest: hover triggers no Vue component re-render (spy the render function)
- Pest: `anatomy:verify-mesh-identity` fails when a node name is orphaned
- Playwright: hover a structure → its name appears; move away → it clears
- Playwright: coarse pointer → no hover chip, tap selects
- Playwright: keyboard focus through the structure index highlights the mesh
- Budget: per-organ payload and triangle count still inside §15.1, recorded
- Idle frame cost still ~0 after a hover sweep

## Acceptance criteria

1. Hovering the heart's left ventricle shows "Left ventricle" and highlights
   that geometry — not a dot near it.
2. Isolate hides other structures for real, on a per-structure organ.
3. A single-mesh organ still works, still shows dots, still reports degraded.
4. `types.ts`, `app/Contracts/**`, and `config/anatomy.php` unchanged.
5. No migration was created.
6. Every budget holds, with recorded before/after numbers.
7. Attribution renders for every adopted source.

## Constraints

- **Do not change `types.ts`.** `model_object_name` is already in the DTO shape.
- Do not add a viewer method. Every capability here is an existing §5.2 method
  finally doing what its name says.
- Do not remove `anchor_position` or dot rendering — they remain the fallback.
- Do not raise a performance budget to accommodate an asset. Fix the asset.
- Do not adopt a second primary source mid-way.

## Commit boundary

**A:** `docs(assets): per-structure source decision and licence` →
`feat(assets): blender export convention` →
`chore(assets): heart pilot export + manifest` → `chore(assets): tier 2 exports`

**B:** `feat(anatomy): seed model_object_name from manifest` →
`feat(anatomy): mesh identity verification command` →
`test(anatomy): identity coverage`

**C:** `feat(viewer): mesh raycast picking with dot fallback` →
`feat(viewer): hover highlight and label chip` →
`feat(viewer): real isolate and layer semantics` →
`feat(explore): hover presentation and touch handling` →
`test(viewer): interaction and performance coverage`

Do the heart pilot end to end across all three branches before exporting a
second organ. If the pilot reveals the naming convention or the