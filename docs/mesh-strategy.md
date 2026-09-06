# Mesh Strategy — Decision Record

**Owner:** Handover 16 (Content/Asset lane) · **Decided:** 2026-09-06 · **Status:** taken
**Answers:** `docs/handovers/16-full-body-coverage.md` §"Hard gate"

Handover 16 forbids authoring a single coordinate until this question is answered:
**are we on single-mesh models or per-structure models?** This file is the answer, its
date, and the reasoning behind it. It exists so that in three months nobody has to
reconstruct why 350 hotspot coordinates were — or were not — hand-authored.

---

## 1. Decision

| Field | Value |
|---|---|
| **Decision** | **Per-structure models.** The platform targets addressable per-structure geometry; single-mesh assets are a transitional state, not the destination |
| **Date** | 2026-09-06 |
| **Taken by** | Handover 16, on the evidence below |
| **Executed by** | Handover 17 (`docs/handovers/17-per-stucture-anotony.md`), which already exists as the lane for exactly this |
| **Source tiering** | Unchanged — `docs/asset-sources.md` §2. Primary Z-Anatomy, secondary BodyParts3D, tertiary NIH 3D |
| **Still gated on a human** | **Yes.** This decision picks a *geometry model*, not an *asset set*. Adopting a source still needs the signature in `docs/asset-register.md` §6 and the decision in `docs/licence-log.md` §4 |

## 2. Why

**The single-mesh path is not actually available.** This is the argument that settles it,
and it is a licence argument rather than a 3D one. Every single-mesh asset we hold is
`thebuggeddev/anatomy`'s nine Tripo GLBs, and those are `BLOCKED` — no licence, all rights
reserved (`docs/asset-register.md` §2, `docs/licence-log.md` §1). Choosing "stay on
single-mesh" would therefore not mean *keep what we have*; it would mean *source 40+ new
single-mesh models from somewhere else*. None of the three tiered sources in
`docs/asset-sources.md` is single-mesh. So the comparison the hard gate poses — cheap
familiar path versus expensive better path — does not exist. Both paths re-source the
whole library. Only one of them also buys structure identity.

**The authoring arithmetic.** The taxonomy in `docs/organ-taxonomy.md` is 44 organs. At
the per-organ definition of done's ≥ 6 published structures (target 8) that is roughly
**350 hand-authored coordinates**, each of which must be re-authored from scratch if the
model behind it is ever swapped, because a coordinate is only meaningful in one model's
pivot space. Per-structure geometry replaces most of that labour with a node name lifted
from the export manifest.

**The schema already anticipated it.** `anatomical_structures.model_object_name` has been
nullable and unused since F03, precisely so this decision could be taken later without a
migration (`docs/project-context.md` §5.5). Taking it now costs no schema change, no API
change, and no change to `resources/js/anatomy/types.ts`.

**Z-Anatomy already carries the naming we would otherwise author.** 7,221 TA2-named
objects with `.l`/`.r` laterality and a `TA2.csv` mapping (`docs/asset-sources.md` §3.2).
The `ta_term` column that the question bank, the RAG filter and the AI context all address
a structure by is a lookup rather than a typing exercise.

**What it costs.** CC BY-SA share-alike attaches to the models and to derivative models
(not to this application's code), a Blender export step sits in front of
`scripts/encode-model.mjs`, per-structure nodes add file overhead against a 2 MB budget
that does not move, and two Z-Anatomy components — the kidney (CC BY-NC) and the inner ear
(CC BY-NC-SA) — are contaminated and must be sourced from another tier. All four are known,
enumerated, and worked around per organ. None is a reason to prefer geometry we cannot
legally ship.

## 3. Consequences to raise

Handover 16 requires both of these to be raised, not merely noted. They are raised here
and reported to their owners.

| Lane | What changes |
|---|---|
| **F04 — viewer core** | The "reduced semantics" clause is on a countdown. `isolateStructure` and `setLayer` stop being cosmetic on a per-structure organ, and `capability:degraded` must keep firing for any organ still on a single mesh. Handover 17 Branch C owns the work; F04's owner owns the contract wording |
| **F15 Phase 6 — tool rail** | Focus and Wireframe are hidden today because they cannot honestly do what their captions say. On a per-structure organ they can. The rail is capability-driven, so this should need **no rail code change** — handover 17 says to verify that, and if it needs one, the rail was built wrong |

## 4. What F17 picks up

`docs/handovers/17-per-stucture-anotony.md` is the lane that executes this. Four notes it
needs before Branch A starts, three of which are corrections to its own text:

1. **NIH 3D is not "largely public domain."** Handover 17's source section says it is.
   Licensing there is chosen per entry by the uploading user; ~19% of the Anatomy category
   is NC or ND and unusable, and there is no source-level grant. `docs/asset-sources.md`
   §1 records this as a flagged-not-edited error in that handover, since it is a contract
   document owned by its author. **Every NIH 3D file needs its own licence check and its
   own register row, written before download.**
2. **Z-Anatomy carries two components that may not be used at all** — the kidney
   (CC BY-NC 4.0) and the inner ear (CC BY-NC-SA 4.0), both disclosed in its own
   `License.txt`. Source those two from the secondary or tertiary tier.
   Z-Anatomy also has **no skin** (`docs/asset-sources.md` §3.2).
3. **The export list and its order** is `docs/organ-taxonomy.md` §5–§6, not the handover's
   "60 organs". It is 44, and the difference is five documented representation decisions.
4. **The budget is already met and must stay met.** The nine current models measure
   1.13–1.89 MB and 146,990–147,000 triangles against a 2 MB / 150k budget, with the
   longest axis at 3.8000 on every one. Per-structure nodes add overhead into roughly
   110 KB of headroom on the largest organ, so decimate on export rather than discovering
   it at verify time.

## 5. What this decision does *not* do

- It does not adopt Z-Anatomy. `docs/asset-sources.md` §3.1 still reads *"No source has
  been adopted and no rights have been exercised."* That remains true.
- It does not unblock deployment. `docs/licence-log.md` §4 is still *not yet taken* and
  `public/models/manifest.json` still reads `"status": "pending-licence"`.
- It does not remove `anchor_position`. Handover 17 is explicit that the dot stays as the
  permanent fallback, and mixed-mode organs are expected rather than a defect.
- It does not authorise a single download. `docs/asset-sources.md` is the gate, and it
  requires a register row written *before* the file arrives.
