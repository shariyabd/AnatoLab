# Organ Taxonomy

**Owner:** Handover 16 (Content/Asset lane) · **Established:** 2026-09-06 · **Status:** binding
**Seeded by:** `database/seeders/BodyTaxonomySeeder.php`

The eleven body systems and every organ the platform intends to teach, with the
representation decision behind each one. It is the roadmap of record: a row exists here
and in the database **before** a model does, so coverage is visible and honest rather
than implied by whatever happens to have been encoded.

Read `docs/mesh-strategy.md` first — it decides *what kind of geometry* every row below
eventually gets. This file decides *what the rows are*.

---

## 1. Rules the taxonomy follows

1. **A taxonomy row is a draft organ.** Every organ that has no cleared, budget-verified
   model is seeded with `status = draft`. Drafts are invisible to students by
   construction: `AnatomyService::listPublishedOrgans()` and
   `findPublishedOrganBySlug()` both filter on `published`.
2. **`model_path` is `models/pending/<slug>.glb` until a real file exists.** The column is
   `NOT NULL` because `types.ts` types `OrganDto.modelUrl` as a plain string
   (`2026_09_05_100010_create_organs_table.php`), so a draft still needs a value. The
   `pending/` segment is the tell: no file is there, no manifest row names it, and it
   never reaches a student because the row is not published.
3. **No placeholder structures.** Handover 16: *"Do not seed placeholder structures to
   satisfy a count."* A draft organ has zero structures until they are authored through
   F13's hotspot tool against a real model.
4. **Nothing already represented as a structure is duplicated as an organ.** See §4.
5. **Tier is recorded here, not in the database.** `organs` has no tier column and F16
   does not own that schema (F03 does). Tier is a planning attribute of the roadmap, not
   a property a student ever sees.
6. **Accent colour is per system for a draft row.** The nine published organs carry
   hand-picked accents; a draft carries its system's, and gets its own when a model and
   a colour pass exist.

## 2. The eleven systems

Seven were established by F03. Four are added here. The eleventh-system count is reached
with **musculoskeletal as one system** rather than skeletal and muscular as two, because
this platform already carries a **sensory** system that the conventional eleven folds
into the nervous system. Splitting bone from muscle *and* keeping sensory separate would
make twelve; merging sensory into nervous would make an eleven that contradicts data F03
already seeded and F10 already reports mastery against.

| # | Slug | Name | Established by |
|---|---|---|---|
| 1 | `cardiovascular` | Cardiovascular System | F03 |
| 2 | `respiratory` | Respiratory System | F03 |
| 3 | `nervous` | Nervous System | F03 |
| 4 | `digestive` | Digestive System | F03 |
| 5 | `urinary` | Urinary System | F03 |
| 6 | `sensory` | Sensory System | F03 |
| 7 | `integumentary` | Integumentary System | F03 |
| 8 | `musculoskeletal` | Musculoskeletal System | **F16** |
| 9 | `endocrine` | Endocrine System | **F16** |
| 10 | `lymphatic` | Lymphatic and Immune System | **F16** |
| 11 | `reproductive` | Reproductive System | **F16** |

## 3. The four entries that are not single organs

Handover 16 §"Taxonomy first, models second" requires each of these to have a documented
representation before seeding. These are the decisions.

### 3.1 Blood vessels / arteries / veins / capillaries → one `vascular-system` organ

**Decision: one organ, regional structures.** Not folded into the heart.

The heart already carries nine structures, four of which are great vessels at their root
(aorta, pulmonary trunk, superior vena cava). Adding the systemic and pulmonary
circulations on top would push one organ past the point where a structure list is
scannable, and it would misrepresent the anatomy: the circulation is taught as a circuit,
not as an appendage of the pump. A dedicated `vascular-system` organ takes regional
structures — aortic arch, common carotid, coronary arteries, hepatic portal vein, femoral
artery, inferior vena cava, a capillary bed — and leaves the heart's own great-vessel
roots where they are. Overlap of *name* between `heart.aorta` and a vascular-system
structure is acceptable; overlap of *slug within an organ* is not possible, since slugs
are unique per organ by constraint.

### 3.2 Bones / skeletal muscles / tendons / ligaments → regional models, never a whole skeleton

**Decision: regional models only. A full articulated skeleton is excluded.**

A whole skeleton is one asset far over the 2 MB / 150k-triangle budget
(`docs/architecture.md` §15.1), and handover 16 and 17 both forbid raising a budget to
accommodate an asset. Bone, muscle, tendon and ligament are tissue classes rather than
organs, so they are represented as **regions** a student is actually examined on:

`skull`, `rib-cage`, `vertebral-column`, `pelvis`, `hand`, `knee-joint`, `biceps-brachii`.

Tendons and ligaments become *structures* on `knee-joint` and `hand` — which is where a
curriculum introduces them and the only place they are legible at region scale. This is
also the one place `docs/asset-sources.md` §4 permits reaching for the tertiary tier on
quality: NIH 3D's CT-derived bone models are the strongest candidates and Z-Anatomy is
weakest here.

### 3.3 Lymph nodes → structures on a `lymphatic-system` organ

**Decision: not an organ. A distributed-network organ carries them as structures.**

Lymph nodes are hundreds of small distributed structures; one of them is not a teaching
unit and all of them are not an organ. `lymphatic-system` follows the same pattern as
`vascular-system`: one network model whose structures are the named regional groups —
cervical, axillary, inguinal and mesenteric nodes, the thoracic duct, the cisterna chyli.
The genuinely discrete lymphoid organs — `spleen`, `thymus`, `tonsils` — stay organs in
their own right.

### 3.4 Hair / nails / sweat glands / sebaceous glands → structures on `skin`

**Decision: not organs. Structures on the skin model. Already realised.**

These are microscopic appendages and belong on a cross-section of skin. F03 has already
implemented this: the published `skin` organ carries `follicle`, `sweat-gland` and
`sebaceous-gland` among its eight structures. The one gap is the **nail plate**, which is
not present. It is not seeded as a placeholder (rule 3); it is added when the skin model
is re-authored, and Z-Anatomy's object list does contain `Nail plate` even though it
contains no skin (`docs/asset-sources.md` §3.2 coverage gap).

## 4. Entries deliberately not duplicated as organs

Handover 16's tier lists name several entries that F03 already seeded as **structures**.
Creating an organ row for them as well would produce two teachable entities with the same
name, two mastery topics, and two places for a question bank to point.

| Handover 16 entry | Already exists as | Decision |
|---|---|---|
| Trachea (Tier 3) | `lungs.trachea` structure | Not duplicated. The airway is taught with the lungs |
| Gallbladder (Tier 3) | `liver.gallbladder` structure | Not duplicated. Revisit when per-structure geometry makes an organ-level gallbladder (fundus, body, neck, cystic duct) worth its own model |
| Small intestine, Large intestine (Tier 2) | one `intestine` organ carrying `duodenum`, `jejunum`, `ileum`, `caecum`, `appendix`, `colon`, `sigmoid-colon`, `rectum` | Not split. Both intestines are already fully covered by one published organ with eight structures; splitting would re-author sixteen coordinates to teach the same content |
| Eye (Tier 2) | published as `eyeball` | Already covered. The slug stays `eyeball`, which is what the upstream data model, the seeded structures and F13's authored coordinates all use |
| Liver, kidneys, pancreas, skin (Tier 2) | published organs | Already covered |

## 5. The taxonomy

`P` = published today · `D` = draft (taxonomy row, no model)

| System | Slug | Name | Tier | State |
|---|---|---|---|---|
| cardiovascular | `heart` | Heart | 1 | **P** |
| cardiovascular | `vascular-system` | Vascular System | 3 | D |
| respiratory | `lungs` | Lungs | 1 | **P** |
| respiratory | `larynx` | Larynx | 3 | D |
| respiratory | `diaphragm` | Diaphragm | 4 | D |
| nervous | `brain` | Brain | 1 | **P** |
| nervous | `spinal-cord` | Spinal Cord | 2 | D |
| nervous | `peripheral-nerves` | Peripheral Nerves | 4 | D |
| sensory | `eyeball` | Eyeball | 2 | **P** |
| sensory | `ear` | Ear | 2 | D |
| sensory | `nose` | Nose | 4 | D |
| sensory | `tongue` | Tongue | 4 | D |
| digestive | `liver` | Liver | 2 | **P** |
| digestive | `pancreas` | Pancreas | 2 | **P** |
| digestive | `intestine` | Intestine | 2 | **P** |
| digestive | `stomach` | Stomach | 2 | D |
| digestive | `esophagus` | Oesophagus | 3 | D |
| digestive | `salivary-glands` | Salivary Glands | 4 | D |
| urinary | `kidneys` | Kidneys | 2 | **P** |
| urinary | `urinary-bladder` | Urinary Bladder | 3 | D |
| integumentary | `skin` | Skin | 2 | **P** |
| endocrine | `thyroid-gland` | Thyroid Gland | 3 | D |
| endocrine | `adrenal-glands` | Adrenal Glands | 3 | D |
| endocrine | `pituitary-gland` | Pituitary Gland | 4 | D |
| endocrine | `parathyroid-glands` | Parathyroid Glands | 4 | D |
| endocrine | `pineal-gland` | Pineal Gland | 4 | D |
| lymphatic | `spleen` | Spleen | 3 | D |
| lymphatic | `thymus` | Thymus | 3 | D |
| lymphatic | `tonsils` | Tonsils | 3 | D |
| lymphatic | `lymphatic-system` | Lymphatic System | 4 | D |
| musculoskeletal | `skull` | Skull | 4 | D |
| musculoskeletal | `rib-cage` | Rib Cage | 4 | D |
| musculoskeletal | `vertebral-column` | Vertebral Column | 4 | D |
| musculoskeletal | `pelvis` | Pelvis | 4 | D |
| musculoskeletal | `hand` | Hand | 4 | D |
| musculoskeletal | `knee-joint` | Knee Joint | 4 | D |
| musculoskeletal | `biceps-brachii` | Biceps Brachii | 4 | D |
| reproductive | `uterus` | Uterus | 3 | D |
| reproductive | `ovaries` | Ovaries | 3 | D |
| reproductive | `uterine-tubes` | Uterine Tubes | 4 | D |
| reproductive | `vagina` | Vagina | 4 | D |
| reproductive | `testes` | Testes | 3 | D |
| reproductive | `prostate-gland` | Prostate Gland | 3 | D |
| reproductive | `penis` | Penis | 4 | D |

**44 organs across 11 systems: 9 published, 35 draft.**

### On the count

Handover 16 budgets for *"60 organs × ~8 structures ≈ 480 authored coordinates"*. This
taxonomy is 44, not 60, and the difference is deliberate rather than an omission. It comes
from the five decisions above: no whole skeleton, no per-bone organs, one intestine rather
than two, four entries collapsed into two network organs, and no organ row for anything
already taught as a structure. Padding the list to reach 60 would be inventing content to
match an estimate. The corrected effort figure is **~350 authored coordinates**, and it is
still weeks of content work, not a sprint task.

### Reproductive organs

In scope, per handover 16 and the PRD's 13–18 audience. Treated exactly like every other
system: clinical framing, TA terms, the same panel layout, no euphemism, no special-casing
in the UI, and gated behind the same `is_published` flag as everything else.

## 6. Tiering — the order content lands in

Handover 16: *"Do not begin a tier until the previous one is fully published with
structures. A half-finished tier is worse than an unstarted one."*

| Tier | Contents | State |
|---|---|---|
| **1** | heart, lungs, brain | Published. Models are budget-verified but **licence-blocked** (`docs/licence-log.md` §4) |
| **2** | liver, kidneys, pancreas, intestine, eyeball, skin (published) + stomach, ear, spinal-cord (draft) | **Blocked.** No cleared asset source has been adopted; see §7 |
| **3** | vascular-system, larynx, esophagus, urinary-bladder, thyroid-gland, adrenal-glands, spleen, thymus, tonsils, uterus, ovaries, testes, prostate-gland | Not started |
| **4** | everything remaining | Not started |

Note that "Tier 1 published" is true of the database and not of the deployment. All nine
published organs point at models that may not be redistributed. Publishing state and
shipping state are different questions and this table only answers the first.

## 7. What blocks the next step

Three things must clear before a single Tier 2 coordinate can be authored. None of them
is content work and none is in F16's gift.

1. **A source must be adopted.** `docs/asset-sources.md` §3.1: *"No source has been
   adopted and no rights have been exercised."* The tiering exists; the adoption does not.
2. **A named human must sign.** `docs/asset-register.md` §6 is unsigned and
   `docs/licence-log.md` §4 reads *not yet taken*. Until both are filled in,
   `public/models/manifest.json` stays `"status": "pending-licence"` and
   `node scripts/verify-models.mjs --release` fails by design.
3. **Structures are authored through F13's hotspot tool against a real model**
   (handover 16, per-organ definition of done §5). A coordinate authored against a model
   that is later replaced is a coordinate authored twice.

One smaller dependency, reported to its owner rather than worked around:

- **`organs` has no `tagline`, `key_facts` or `medical_importance` column.** The per-organ
  definition of done §4 requires a tagline and key-facts values, and
  `resources/js/Components/Explore/InfoPanel.vue` already renders them from
  `ExploreOrganEditorial`. `resources/js/types/explore.ts` records the request to F03 for
  four columns. F16 does not own that schema and has not added them, and neither does
  F17 — handover 17 acceptance criterion 5 is *"no migration was created"*.

## 8. How the taxonomy reaches the screen

F15 Phase 7 scoped the row state — *"a `coming soon` row state for a system present in the
taxonomy with no published organs — visibly inert, not clickable, not a 404"* — and left
it unbuilt. F16 built it, because a roadmap nobody can see is not a roadmap.

| Piece | What it does |
|---|---|
| `AnatomyService::listUpcomingOrgans()` | Draft organs with their body system, cached under `anatomy:organs:upcoming`. `forgetOrgan()` drops it too, because publishing moves a row between the two lists |
| `AnatomyService::findUpcomingOrganBySlug()` | Resolved out of that list, so the deep link costs no second query and no second cache key |
| `UpcomingOrganResource` | The row's public surface: id, slug, name, scientific name, description, accent, body system. **No `modelUrl`, no `thumbnailUrl`, no `structureCount`** — a draft's `model_path` is a placeholder today and may be a licence-blocked asset tomorrow, and neither belongs in a page prop |
| `ExploreController::show()` | Published organ → the viewer. Taxonomy row → the page with `comingSoon` set and `organ` null. Neither → still a 404, because a typo should not look like content |
| `OrganLibrary.vue` | Renders the taxonomy under the same system headings, as a `<span>` in a `<li>` — never a `<button>`. Groups on the combined count, so nine published organs still get headings once the taxonomy is seeded |
| `ComingSoonPanel.vue` | What a deep link to `/explore/stomach` lands on. Says what the organ is, that it is coming, and offers no control |

Two consequences worth knowing before touching this:

- **`comingSoon` is in the client's partial-reload list** alongside `organ`
  (`Pages/Explore.vue`, `openOrgan`). Adding a page prop here without adding it there is
  how a stale banner survives a navigation.
- **Recession is by colour token, never by opacity on the row.** An opacity wrapper
  multiplies through the text underneath it and would put a caption that measures 4.5:1 in
  `design/palette.ts` somewhere under 3:1 on screen. The swatch carries the fade; it is
  `aria-hidden` decoration with no ratio to lose.

## 9. What F17 inherits

`docs/handovers/17-per-stucture-anotony.md` is the next lane, and it executes the decision
in `docs/mesh-strategy.md`. Three things in this file are directly its input:

1. **The export list and its order** is §5 and §6. Tier by tier, and handover 16's rule
   stands: do not begin a tier until the previous one is fully published.
2. **The four representation decisions in §3 survive the move to per-structure geometry.**
   `vascular-system` and `lymphatic-system` are network models whose regional structures
   become mesh nodes; the musculoskeletal regions stay regions. Per-structure meshes do not
   make a whole-body skeleton fit the budget.
3. **§4's no-duplicate rule is worth revisiting exactly once**, for the gallbladder. Handover
   17 gives structures their own addressable geometry, which is the condition under which an
   organ-level gallbladder (fundus, body, neck, cystic duct) would earn its own model. That
   is a content decision for F17's owner, not a defect in this file.

One correction F17 must carry, because its own text has it wrong: handover 17's source
section describes **NIH 3D** as *"largely public domain"*. It is not. Licensing there is
per entry, chosen by the uploading user, and roughly 19% of the Anatomy category is NC or
ND and unusable. `docs/asset-sources.md` §1 records this as a flagged-not-edited error in
that handover — it is a contract document owned by its author. Every NIH 3D file needs its
own licence check and its own register row, written before download.
