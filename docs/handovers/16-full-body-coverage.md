# Handover 16 — Full Body Coverage

**Feature:** F16 · **Lane:** Content/Asset · **Wave:** 9
**Branch:** `feat/f16-full-body-coverage`

> Read first: handover 02 (asset pipeline & licence) · handover 03 (anatomy
> domain) · handover 13 §"Hotspot authoring" · `docs/project-context.md` §2.2, §3

## Objective

Expand from 3 MVP organs to full-body coverage across eleven systems, without
breaking the performance budget, the licence register, or content accuracy.

This is content work. Very little code is written here.

## Hard gate — do not start Phase 1 until this is answered

**Are we on single-mesh models or per-structure models?**

Single-mesh (current): structures are dots only. No hover highlight, no true
isolation, no layer peeling. Every structure needs a hand-authored coordinate.

Per-structure (BodyParts3D / Z-Anatomy): structures are addressable meshes.
Mesh hover, real isolation, and layer views all become possible, and
`model_object_name` — nullable and unused since F03 — finally carries identity
with no schema change.

At 60 organs the second path is cheaper *and* better. Record the decision with
its date and rationale before authoring a single coordinate. If per-structure
models are adopted, F04's scope changes and F15's Phase 6 tool rail regains
capabilities it currently hides — raise both.

## Taxonomy first, models second

Seed all eleven body systems and the full organ taxonomy as **unpublished**
rows. The UI already renders a "coming soon" state (F15 Phase 7). This gives
you a visible, honest roadmap on day one and lets content land organ by organ
without a code change.

Four entries in the target list are **not single organs** and must not be
modelled as such — decide their representation explicitly:

| Entry | Reality |
|---|---|
| Blood vessels / arteries / veins / capillaries | A network, not an organ. Either one "Vascular system" organ with regional structures, or fold into the heart. Pick one. |
| Bones / skeletal muscles / tendons / ligaments | Tissue classes. A full skeleton is one large asset well over budget — plan regional models (skull, thorax, hand) or exclude from MVP. |
| Lymph nodes | Distributed. Represent as structures on a lymphatic-system model, not as an organ. |
| Hair / nails / sweat glands / sebaceous glands | Microscopic. These belong as structures on the skin model with cross-section views, not standalone organs. |

Resolve these four before seeding, or the taxonomy will fight the data model.

## Tiering — ship in waves, not all at once

**Tier 1 (already done):** heart, lungs, brain
**Tier 2 — highest teaching value, complete these next:**
  stomach, liver, kidneys, small intestine, large intestine, eye, ear,
  spinal cord, pancreas, skin
**Tier 3:** trachea, larynx, esophagus, gallbladder, bladder, spleen, thyroid,
  adrenal glands, thymus, tonsils, uterus, ovaries, testes, prostate
**Tier 4:** everything remaining

Do not begin a tier until the previous one is fully published with structures.
A half-finished tier is worse than an unstarted one.

## Per-organ definition of done

An organ is publishable only when **all** of these hold:

1. Model < 2 MB, < 150k triangles, normalised to `FIT_SIZE = 3.8`, verified
   mechanically by the F02 pipeline script — not by eye.
2. An asset-register row with a licence permitting public deployment, plus the
   attribution string that F14's page renders.
3. A rendered thumbnail for the organ library panel.
4. `tagline`, description, and key-facts values.
5. ≥ 6 published structures (target 8), each with `name`, `ta_term`,
   `anchor_position`, description, and function.
6. Anatomical accuracy checked against a real source. Upstream prose is
   AI-generated and unverified; Tripo models are of unverified accuracy. Neither
   is promoted to canonical without a check.

**Structures are authored through F13's hotspot tool, not by hand-editing JSON.**
That tool exists precisely so this scales past a developer.

## Reproductive systems

They stay in scope — the PRD's audience is 13–18 and these are curriculum
content. Treat them exactly like every other system: clinical framing, TA
terms, same panel layout, no euphemism and no special-casing in the UI. Gate
them behind the same `is_published` flag as everything else.

## Effort — say this out loud before committing

60 organs × ~8 structures ≈ 480 authored coordinates, each needing a name, a TA
term, a description, and a function. Plus 60 models through the encoding
pipeline with 60 register rows. This is weeks of content work, not a sprint
task. **Tier 2 alone is a credible, demonstrable product.** Do not let the full
list become the definition of done.

## Tests

- Pipeline script re-verifies budget and normalisation across the whole set
- Seeder is idempotent; re-running does not duplicate structures
- Every published organ has ≥ 6 published structures (feature test)
- Every published organ has an asset-register row (script assertion)
- Library panel renders 60+ organs without layout or scroll regression
- Unpublished taxonomy rows render the inert "coming soon" state, never a 404

## Acceptance criteria

1. The mesh-strategy decision is recorded with date and rationale.
2. The four non-organ entries have a documented representation.
3. All eleven systems and the full taxonomy are seeded.
4. Tier 2 is fully published to the per-organ definition of done.
5. No performance budget regressed, with recorded numbers.
6. Attribution page lists every shipped asset.

## Constraints

- No asset ships whose provenance you cannot state.
- Never assume open source means redistributable.
- Do not seed placeholder structures to satisfy a count.
- Do not alter `organs` or `anatomical_structures` schema — F03 owns them.

## Commit boundary

`docs(assets): mesh strategy decision` → `feat(content): body system and organ
taxonomy seed` → `chore(assets): tier 2 encoded models + register rows` →
`feat(content): tier 2 organ metadata` → `feat(content): tier 2 authored
structures` → `test(content): coverage and budget assertions`