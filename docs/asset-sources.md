# Asset Sources

**Owner:** Handover 02 (Content lane) · **Established:** 2026-09-05 ·
**Amended:** 2026-09-06 (Amendment A source correction) · **Status:** binding

Where anatomy assets may come from, in what order, and what each source obliges us to
do. `docs/asset-register.md` records what each individual asset *is*; this file records
which wells we are allowed to draw from and what the water costs.

**This file is the gate, not the paperwork afterward.** Handover 02 Amendment A:
*"Do not download a single file before `docs/asset-sources.md` exists and is committed."*
A source that is not tiered below is not a source. An asset from a tiered source still
needs its own register row, written **before** download, carrying the entry ID, the
licence as stated on that entry, and the verbatim attribution string.

---

## 1. The correction this file exists to record

Handover 02 §1 originally described **NIH 3D** as *"mostly public domain / CC0."*
**That was inaccurate and it was load-bearing** — it would have licensed a bulk harvest
under an assumption no one had checked.

Per NIH 3D's Terms and Conditions §4.3, **licensing is chosen and applied per entry by
the uploading user**, and NIH does not enforce licence terms on contributors' behalf.
Entries range from public domain through CC BY to terms we cannot use at all. Verified
example: `3DPX-000906` (Heart, Aorta and Kidney CAD Model) is **CC BY**, requiring
attribution; `3DPX-000900` ("Heart (CT)") is **CC BY-NC**, which we cannot use.

There is no blanket grant. **Every NIH 3D file requires an individual licence check.**

The same inaccurate sentence appeared in `docs/project-context.md` §3 and in
`docs/adding-an-organ.md`; both were corrected against this file on 2026-09-06.
`docs/handovers/17-per-stucture-anotony.md` still carries it — that is a contract
document owned by its author, flagged rather than edited.

---

## 2. Source tiering — this is the decision

**Rationale.** One source that solves mesh identity, structure naming and coverage
together is worth more than a per-organ hunt for the best individual mesh. Mixing
sources costs consistency, and consistency is what makes a product look made rather than
assembled. So the tiering is ordered by *how much of the problem a source solves at
once*, not by mesh quality per organ.

| Tier | Source | Licence | Share-alike | Use it for |
|---|---|---|---|---|
| **Primary** | **Z-Anatomy** | CC BY-SA 4.0 (bundle), **with third-party contamination — see §3.2** | **Yes** | The full-body baseline: per-structure meshes, TA/Latin naming already applied |
| **Secondary** | **BodyParts3D / Anatomography** | **Contested**: CC BY 4.0 or CC BY-SA 2.1 JP — see §3.3 | Unresolved | Organs Z-Anatomy renders poorly or omits |
| **Tertiary** | **NIH 3D** | **Per entry.** No blanket grant | Per entry | Specific gaps only, and CT-derived skeletal models (§4) |

**Primary: Z-Anatomy.** One consistent full-body source, per-structure meshes,
Latin/TA naming already applied, Blender source, CC BY-SA. It is the only candidate that
solves mesh identity, structure naming and coverage in a single move. Its known defects
(§3.2) are real and must be worked around per organ, not treated as disqualifying.

**Secondary: BodyParts3D / Anatomography.** Same lineage — Z-Anatomy is itself a
BodyParts3D derivative — so it fills gaps without breaking scale or topology.

**Tertiary: NIH 3D.** Gaps only. Strongest for CT-derived skeletal models, which is
precisely where Z-Anatomy is weakest and where F16's musculoskeletal budget problem sits.

### Do not mix sources within a single organ

Scale, topology and visual style differ enough across these three that a heart assembled
from two of them will read as assembled. One organ, one source. Crossing sources
*between* organs is acceptable; crossing them *inside* one is not.

---

## 3. Per-source terms

Everything below is recorded as of **2026-09-05/06**. Licences change; re-read the
source before each harvest rather than trusting this table's age.

### 3.1 Adoption status

**No source has been adopted and no rights have been exercised.** The grant-vs-replace
decision in `docs/licence-log.md` §4 is *not yet taken*, and `docs/asset-register.md` §6
is unsigned. This file records what we *may* draw from once that decision lands; it does
not itself constitute adoption. Nothing here is in `public/models/` today.

### 3.2 Z-Anatomy — primary

| Field | Value |
|---|---|
| **Source** | `https://github.com/Z-Anatomy/Models-of-human-anatomy` (`master`) — `Z-Anatomy.zip`, 86.7 MB |
| **Creator** | The Z-Anatomy project. Itself a BodyParts3D derivative |
| **Licence** | **CC BY-SA 4.0**, stated in `License.txt`. GitHub reports `NOASSERTION` only because the licence is not in a filename it recognises — verified by reading the file, not the badge |
| **Share-alike** | **Yes.** It attaches to the models and to derivative models — every asset we publish that derives from this source. It does **not** reach our application code |
| **Attribution string** | Both lines are required, verbatim:<br>`BodyParts3D - The Database Center for Life Science - CC-BY-SA 2.1 Japan`<br>`Z-Anatomy - The libre 3D atlas of anatomy - CC-BY-SA 4.0` |
| **Granularity** | 7,221 distinct named objects, TA2-named with `.l`/`.r` laterality, plus a `TA2.csv` mapping. The best-labelled of the three |
| **Format** | `.blend` only — a 307 MB monolith. A Blender export step is required before `scripts/encode-model.mjs` can take it |
| **Contamination — read before every harvest** | `License.txt` discloses included third-party models under **CC BY-NC 4.0 (Kidney, by Lissie Cowley)** and **CC BY-NC-SA 4.0 (Inner Ear)**. **Neither may be used.** A CC BY-NC component cannot legally sit inside a CC BY-SA 4.0 work; the bundle's own licensing is internally inconsistent, and that inconsistency is ours to route around, not to resolve. Source kidney and inner ear from the secondary or tertiary tier |
| **Coverage gap** | **No skin.** A search of all 7,221 object names for skin, epidermis, dermis, integument and hypodermis returned only `Nail plate` and `Hairs` |
| **Last updated** | May 2023 |

**Why primary despite the contamination.** The two contaminated parts are enumerable and
avoidable; the alternative — no per-structure naming anywhere — is not. `docs/asset-register.md`
§5 CAN-02 previously recorded this source as **rejected** on those same defects. Amendment A
overrules that verdict, and the register has been corrected to match. The defects did not
change; the weighting did, because F17 makes structure identity the thing being bought.

### 3.3 BodyParts3D / Anatomography (DBCLS) — secondary

| Field | Value |
|---|---|
| **Source** | `https://dbarchive.biosciencedbc.jp/en/bodyparts3d/download.html` — `partof_BP3D_4.0_obj_99.zip`, 64,888,505 bytes |
| **Creator** | Database Center for Life Science (DBCLS), ROIS/JST, Tokyo |
| **Licence** | **Contested, and the contest is exactly about share-alike.** The archive licence page (updated 2025-02-27) says **CC BY 4.0**. The Anatomography site §3.4 and the header comment inside every downloaded `.obj` say **CC BY-SA 2.1 JP**. The OBJ header's own pointer URL now resolves to the CC BY 4.0 text, which reads as a deliberate relicence with stale copy elsewhere — but it is not unambiguous |
| **Share-alike** | **Unresolved.** Treat as **yes** until DBCLS confirms otherwise in writing. Assuming the more permissive reading of a contested licence is the exact failure Amendment A exists to prevent |
| **Attribution string** | CC BY 4.0 reading: `BodyParts3D, © The Database Center for Life Science licensed under CC Attribution 4.0 International`<br>CC BY-SA 2.1 JP reading: `BodyParts3D, © The Database Center for Life Science licensed under CC Attribution-Share Alike 2.1 Japan` |
| **Granularity** | 1,258 OBJ elements, mapped FMA concept → element by `partof_element_parts.txt` (17,943 rows). **Elements are shared, not a partition** — `FJ2421` belongs to 26 concepts including *heart*, *right atrium*, *right ventricle* and *tricuspid valve*. A structure is a *union* of elements; the overlaps must be resolved before per-structure picking works |
| **Format** | Wavefront OBJ, positions and normals only. **No UVs, no `.mtl`, no textures, no colour.** 3.14 M triangles total, one whole-body frame in millimetres — which suits `FIT_SIZE` normalisation |
| **Condition of use** | **Get the relicence confirmed in writing before shipping anything from here**, or ship under the CC BY-SA 2.1 JP reading and accept share-alike. Do not pick the convenient reading silently |
| **Caveats** | Single-subject MRI segmentation, frozen at v4.0 (2011–2013). Kidney and skin are one mesh each |

### 3.4 NIH 3D — tertiary, gaps and skeletal only

| Field | Value |
|---|---|
| **Source** | `https://3d.nih.gov/` |
| **Licence** | **Per entry, chosen by the uploader** (Terms and Conditions §4.3). **There is no source-level licence and this row cannot state one.** Of 719 Anatomy-category entries, the catalogue reports 286 Public Domain, 262 CC BY and 30 CC BY-SA — and **~19% NC or ND, which are unusable**. Those proportions are a reason to check, never a reason to assume |
| **Share-alike** | Per entry. CC BY entries: no. CC BY-SA entries: yes. Record it per file |
| **Attribution string** | **Per entry, verbatim**, from that entry's `attributionInstructions`, carrying its own DOI — e.g. `Kristen Browne. 2021. 3D Reference Organ for Skin, Male v1.2. https://doi.org/10.48539/HBM369.SBSP.863. Accessed on <our access date>.` The access date must be ours, not the example's. **Each shipped file needs its own register row carrying its own string** |
| **Format** | GLB native on many entries, with STL/X3D/WRL auto-generated |
| **Harvesting** | No bulk download and **no supported public API** — the legacy REST API is gone (404). Two undocumented endpoints on the current site work (`/api/entries/{id}`, `/api/files/{id}`). Treat them as a one-time manual harvest, **never a runtime dependency** |
| **Provenance disclosure** | The HuBMAP reference-organ set is derived from the NLM Visible Human cadavers. NLM replaced its data licence with plain Terms and Conditions in 2019, so there is no legal restriction — but the Visible Human Male provenance is an ethical disclosure point some educational publishers choose to make. That is a call for a human, not for a register |

#### Selection rules — apply to every candidate before download

1. **Prefer GLB entries over STL.** STL carries no materials, no textures and no named
   parts. An STL import reproduces exactly the single-mesh limitation F17 exists to
   remove. If only STL exists for a needed organ, **it does not qualify** — source it
   elsewhere.
2. **Reject patient-specific and pathological specimens.** Much of NIH 3D is segmented
   from individual patient MRI/CT, and the cardiac collection skews heavily toward
   congenital disease. This product teaches normal anatomy to ages 13–18. One patient's
   diseased heart is the wrong specimen regardless of mesh quality.
3. **Record the entry ID, its stated licence and its required attribution string in
   `docs/asset-register.md` *before* download, not after.** One row per file, no
   exceptions.
4. **Skip the molecular library entirely.** AlphaFold, PDB and EMDB entries are
   proteins, not organs. Out of scope.

---

## 4. Skeletal exception

F16 flags the musculoskeletal system as the one system that cannot make the 2 MB / 150k
triangle budget (`docs/architecture.md` §15.1). NIH 3D's CT-derived bone models are the
strongest candidates for **regional** skeletal assets — skull, thorax, hand, spine —
and this is the one place the tertiary tier is reached for on quality rather than on a
gap. The per-entry licence check in §3.4 applies unchanged: being the best mesh does not
exempt an entry from having its licence read.

---

## 5. Sources that are not sources

| Source | Why not |
|---|---|
| **`thebuggeddev/anatomy` (upstream)** | No licence of any kind. All rights reserved by default. `docs/asset-register.md` §2, `docs/licence-log.md` |
| **Tripo / Meshy / other generative AI** | Rights depend on the generating account's plan, and the output is of **unverified anatomical accuracy**. Fine for layout, not for teaching |
| **Sketchfab / TurboSquid / CGTrader** | Per item, and "free" routinely means non-commercial or no-redistribution. Not barred — but each item is its own licence check with no source-level answer, so it never becomes a tier |
| **NIH 3D molecular library** | Proteins, not anatomy |

### What disqualifies an individual asset, from any tier

- No licence file or statement anywhere → **stop**
- **NC** (non-commercial) → stop, unless a named human records that the deployment never
  touches commerce, and signs that decision
- **ND** (no derivatives) → stop. Encoding to budget *is* a derivative
- **SA** (share-alike) → allowed, but it propagates to what we publish. A deliberate
  choice, recorded per asset — never an accident
- Licence stated only in a screenshot or a forum post → get it in the repository or in
  an email

---

## 6. What this file obliges downstream

- **`docs/asset-register.md`** — one row per file, written before download, carrying the
  entry ID, the licence as stated on that entry, and the verbatim attribution string.
  `scripts/verify-models.mjs` fails if a manifest entry names a `registerId` the register
  does not contain.
- **The attribution page (F14)** — Amendment A specifies that it renders from *this*
  file. Today `app/Services/Content/AssetRegisterDocument.php` renders
  `docs/asset-register.md` instead. **That is an open handoff to F14's owner**, not an
  oversight of this file; handover 02 does not touch `app/`. Until it is closed, a
  reader of the About page sees the register and not the tiering.
- **Share-alike, if a share-alike source is adopted** — the licence notice and both
  attribution lines must ship with the application, and derivative models inherit the
  licence. Our application code does not.
