# Project Context — Discovered Facts & Reuse Decisions

**Status:** Phase 0 deliverable (Technical Audit / Reuse Report)
**Date of audit:** 2026-09-04
**Audited commit:** `8c0e6f321a47f895ae58ce098028b92774733ee9` (`main`, pushed 2026-08-09)
**Audit method:** full source clone + GLB binary inspection + GitHub metadata. Live
deployments were **not** reachable (see §1.3), so all behavioural claims below are
derived from source, not from observed runtime.

This document records what actually exists. `docs/architecture.md` records what we
will build. Where the PRD assumes something that turned out to be false, it is called
out here explicitly rather than silently designed around.

---

## 1. The existing Anatomy repository

### 1.1 Identity

| Field | Value |
|---|---|
| Repository | `thebuggeddev/anatomy` |
| Description | "An interactive 3D human anatomy explorer built using threejs with GPT 5.6 Sol" |
| Created | 2026-08-02 |
| Last push | 2026-08-09 |
| Commits on `main` | 9 |
| Stars | ~3,158 |
| **License** | **None declared** — no `LICENSE` file, GitHub API reports `license: null` |
| Product name in code | "Anatomy Atelier" |
| Repo `package.json` name | `site-creator-vinext-starter` |

The project is a **generated application**: it began life as OpenAI's
`site-creator-vinext-starter` template (the `.openai/hosting.json` project id and the
untouched template `README.md` are still in the tree) and the anatomy product was
built on top of it across 9 commits. This matters for two reasons: the surrounding
scaffolding is template boilerplate with no product value to us, and the repository
has never been curated for redistribution.

### 1.2 Technology stack (existing)

```
Next.js 16.2.6 (App Router, RSC)   React 19.2.6      TypeScript 5.9 (strict)
Three.js 0.185.1                    GSAP 3.15         lucide-react
Tailwind 4.2 (+ 37 KB hand-written globals.css)
Runtime: Cloudflare Workers via `vinext` 0.0.50 + wrangler 4.92
Data:    Drizzle ORM 0.45 wired to Cloudflare D1 — schema is EMPTY
Deploy:  Cloudflare (primary) + vercel.json fallback to `next build`
Tests:   one node:test file asserting the *template's* loading skeleton HTML
```

**There is no backend.** `db/schema.ts` is `export {};` with a comment saying
"Intentionally empty by default." There are no API routes, no user accounts, no
persistence, no analytics, no server-side state of any kind. Every piece of content is
a TypeScript literal compiled into the client bundle. The entire application is one
route: `app/[locale]/page.tsx`.

### 1.3 Live application

Both deployment URLs returned **HTTP 503** during the audit:

- `https://anatomy-livid.vercel.app/en` (URL given in the PRD) — 503
- `https://anatomyatelier.vercel.app` (URL in the repo's GitHub homepage field) — 503

The PRD's Phase 0 item "audit live application" could therefore not be completed.
This is not a blocker: the source is complete and self-explanatory, and nothing in the
reuse decisions below depends on runtime observation. It should be re-attempted before
any claim is made about the original product's behaviour in a demo or write-up.

---

## 2. The 3D layer — what is actually there

### 2.1 File map

| File | Lines/size | What it is |
|---|---|---|
| `app/lib/three/viewer.ts` | 25.7 KB | The whole 3D application: scene, lights, env map, camera, OrbitControls, render-on-demand loop, pointer/keyboard input, tools, callout positioning, disposal |
| `app/lib/three/hotspots.ts` | 14.6 KB | Sprite-based structure markers, surface snapping, occlusion fade, screen-space picking, quiz flash feedback |
| `app/lib/three/loaders.ts` | 7.8 KB | `AnatomyAssetManager` — GLTF+meshopt loading, normalisation, material conditioning, LRU cache, prefetch, disposal |
| `app/lib/three/dispose.ts` | 486 B | Recursive geometry/material/texture disposal |
| `app/lib/three/tsl-materials.ts` | 368 B | One unused TSL rim-light node (aspirational WebGPU hook; nothing imports it) |
| `app/lib/anatomy-data.ts` | 6.1 KB | The structural data model — 9 organs, 37 hotspots |
| `app/components/OrganViewer.tsx` | 14.7 KB | React wrapper + tool bar + callout + the labelling quiz |
| `app/components/AnatomyApp.tsx` | 18.1 KB | Page shell: nav, organ library, info panel, compare drawer, modals |
| `app/i18n/**` | ~180 KB | 12 locales × (UI dictionary + organ prose dictionary) |

The 3D code is **genuinely good**. It is not throwaway demo code, and the reuse case
rests on that: render-on-demand (draws only when something moved), correct resource
disposal on every path, an LRU model cache with in-flight de-duplication, a depth
prepass so a cross-fading closed mesh resolves to one surface, anisotropy applied to
every sampled map rather than just base colour, `IntersectionObserver` +
`visibilitychange` gating, and a fixed pixel-ratio decision with a comment explaining
why the dynamic version was removed. These are the things that take a long time to get
right and they are already right.

### 2.2 THE CENTRAL FINDING — there are no anatomical sub-meshes

Every one of the nine GLB files contains **exactly one node, one mesh, one primitive,
one material**:

```
brain.glb      1 node  1 mesh  1 prim  node name: tripo_node_938185c2-…  mesh: meshes[0]
heart.glb      1 node  1 mesh  1 prim  node name: tripo_node_9c16954f-…  mesh: tripo_mesh_9c16954f-…
lungs.glb      1 node  1 mesh  1 prim  node name: tripo_node_a2c7b654-…  mesh: meshes[0]
…and identically for eyeball, intestine, kidneys, liver, pancreas, skin
```

The `tripo_*` prefix identifies these as **Tripo AI generative 3D output**, later
processed by `glTF-Transform v4.4.2`. The consequences are absolute:

- **There is no `Heart_LeftVentricle` mesh.** The PRD's `model_object_name:
  Heart_LeftVentricle` (§7) describes a capability these assets do not have and cannot
  be made to have without remodelling.
- **Per-structure selection by raycast is impossible.** A raycast against the heart
  returns "the heart", never "the left ventricle".
- **Per-structure isolation, hide/show, and layer visibility are impossible.** There is
  no second object to hide.
- **Cross-section is whole-organ only.** A clipping plane cuts the single mesh; it
  cannot cut "just the ventricle".
- **There are no animation clips.** `gltf.animations.length` is 0 for all nine models.
  `AnatomyAssetManager` has full `AnimationMixer` support that never fires.
- The models are **surface shells with a single baked colour/normal/roughness set**.
  Interiors are not modelled. The heart has no chambers; the "cross-section" reveals
  the inside of a hollow shell, not anatomy.

How the existing app solves this: **hand-authored hotspots**. Each structure is a
3D coordinate in `anatomy-data.ts`, snapped onto the mesh surface at load time and
drawn as a billboard sprite. Selection is **screen-space distance to a sprite**, not a
mesh raycast:

```ts
// hotspots.ts — "Screen-space picking: six projections, no mesh raycast."
pick(x, y, camera, width, height, radius = 24)
```

This is a sound engineering answer to the constraint, and it is directly reusable. It
is also the reason the platform's structure identity model must be redesigned (§5.1).

### 2.3 The existing data model

`app/lib/anatomy-data.ts` — structure only, deliberately free of prose:

```ts
type HotspotStructure = {
  id: string;                          // "left-ventricle" — stable, kebab-case
  ta: string;                          // "Ventriculus sinister" — Terminologia Anatomica
  position: [number, number, number];  // pivot space, normalised to FIT_SIZE = 3.8
  color: string;
};
type OrganStructure = {
  id: OrganId;  model: string;  icon: string;  accent: string;
  illustrated: boolean;  scientificName: string;  hotspots: HotspotStructure[];
};
```

**Stable structure IDs already exist**, and they are better than the PRD assumed. Each
hotspot carries its **Terminologia Anatomica (TA2) Latin term** as the canonical,
locale-independent identity — the i18n layer translates *against* the TA term and falls
back to it when a locale lacks a translation. This is exactly the anchor a RAG filter
and a question bank need, and we should keep it.

Full inventory — **9 organs, 37 structures**:

| Organ | TA | Structures |
|---|---|---|
| heart | Cor | aorta, left-atrium, right-atrium, left-ventricle, right-ventricle, mitral (6) |
| brain | Encephalon | frontal, parietal, temporal, cerebellum (4) |
| lungs | Pulmones | trachea, right-lung, left-lung, bronchus, base (5) |
| liver | Hepar | right-lobe, left-lobe, portal (3) |
| kidneys | Renes | cortex, medulla, ureter (3) |
| eyeball | Oculus | cornea, iris, optic (3) |
| intestine | Intestinum | duodenum, jejunum, colon (3) |
| pancreas | Pancreas | head, body, tail, duct (4) |
| skin | Integumentum | epidermis, dermis, hypodermis, follicle (4) |

Coordinates are meaningful across models because every organ is uniformly scaled into a
`FIT_SIZE = 3.8` cube and centred (`loaders.ts`). Hotspot authoring is therefore
model-independent — a real design win we inherit for free.

### 2.4 Capabilities that already exist and are directly useful

| Existing capability | Where | Value to us |
|---|---|---|
| **Quiz mode in the viewer** | `viewer.setQuizMode()`, `onPick`, `flash(id, correct)` | The 3D spatial quiz (PRD §12) is ~70% built. Every press reports a hotspot id, no sticky selection, green/red ring feedback, and a wrong answer *also* flashes the correct structure green. |
| **Hotspot authoring tool** | `viewer.setAuthoring()`, `captureAuthorPoint()`, `?authoring=1` | Click the model → raycast → pivot-space coordinate → copyable data literal. This is the admin tool for adding structures. Reuse as-is. |
| **Occlusion-aware markers** | `hotspots.update()` | Facing test + depth buffer, no per-frame mesh raycast. Dots on the far side fade out. |
| **Surface snapping** | `snapToSurface()` | Authored point → nearest surface vertex, filtered by direction cone first so a dot never punches through to the far side. One linear pass per organ load. |
| **Render-on-demand loop** | `viewer.animate()` | `dirty` flag + `busyUntil` window. Idle scenes cost nothing. Essential for running 3D next to an AI panel. |
| **Asset lifecycle** | `AnatomyAssetManager` | LRU (limit 3), in-flight de-dup, low-priority prefetch, full disposal. Directly satisfies PRD §25. |
| **Screen-anchored callout** | `attachCallout()`, `hotspotScreenY()` | DOM element tracked to a 3D point imperatively, zero React re-renders while the model spins. |
| **Accessibility fallback** | `.hotspot-index` list | The dots are mirrored as a real `<ul>` for screen readers. Satisfies PRD §31's "3D interaction must have equivalent textual content". |

### 2.5 Capabilities that are named but do not do what they say

These matter because the PRD's feature list reads them at face value:

| Tool | Label | What it actually does |
|---|---|---|
| `toggleIsolate()` | "Isolate" | Fades the **plinth and contact shadow**, not any anatomical structure. Cosmetic only. |
| `toggleLayers()` | "Layers" | Toggles **wireframe** on the single material. Not anatomical layers. |
| `toggleCrossSection()` | "Section" | One global X-axis clipping plane across the whole organ. Reveals a hollow shell interior. |
| Compare | "Compare" | A **2D side-by-side text/image drawer**. No second model is loaded into the scene. |
| Animation | "Play animation" | Opens a modal with a **static image and a play badge**. No animation exists. |
| Lessons / Systems / Library / Notes nav | — | Non-functional buttons; only "Lessons" opens a static modal. |

### 2.6 Performance characteristics (measured)

| Model | Triangles | Vertices | File | Texture payload | Texture format |
|---|---|---|---|---|---|
| heart | 386,597 | 293,072 | 3.32 MB | 385 KB | WebP |
| brain | 377,692 | 205,701 | 2.57 MB | 405 KB | WebP |
| skin | 356,333 | 225,834 | **5.79 MB** | **3.32 MB** | JPEG |
| lungs | 350,772 | 190,977 | 4.46 MB | 2.47 MB | JPEG |
| pancreas | 346,596 | 186,467 | **4.85 MB** | **2.83 MB** | JPEG |
| liver | 345,526 | 214,401 | 2.66 MB | 410 KB | WebP |
| kidneys | 327,452 | 182,069 | 2.19 MB | 367 KB | WebP |
| eyeball | 320,250 | 178,052 | 2.20 MB | 390 KB | WebP |
| intestine | 308,748 | 175,528 | 1.98 MB | 146 KB | WebP |
| **Total** | **3.12 M** | | **30.0 MB** | | |

Findings:

- **Models are ~3× heavier than the code believes.** `viewer.ts` documents the depth
  prepass as costing "one depth-only pass over ~120k triangles". The real number is
  308k–387k. The prepass is ~3× its assumed cost, and every organ is a dense mesh for
  what is visually a smooth blob.
- **Compression is inconsistent.** Geometry uses `EXT_meshopt_compression` +
  `KHR_mesh_quantization` (good). Textures are split: six organs use
  `EXT_texture_webp`, three (lungs, pancreas, skin) ship raw JPEG and are 5–8× the
  texture weight of their peers. No KTX2/Basis supercompression anywhere.
- **1.6 MB of dead decoder payload is shipped.** `public/draco/` (1.06 MB) and
  `public/basis/` (585 KB) are in the deploy tree. Nothing references them — the loader
  configures **only** `MeshoptDecoder`. Pure waste.
- **No LOD, no progressive/streamed loading.** An organ is one all-or-nothing fetch;
  the loading UI is deliberately suppressed for the first 900 ms.
- Mitigations already in place and worth keeping: `frustumCulled = false` (single
  always-centred mesh — culling can only ever be wrong here), shadow maps off in favour
  of a baked contact shadow, PMREM env map baked once from a 16×32 gradient, pixel
  ratio capped at 1.5 on low-power devices.

### 2.7 Browser / device support

- WebGL only (`WebGLRenderer`). The TSL file hints at a future WebGPU path; nothing
  uses it. No WebGL-unavailable fallback exists — a device without WebGL gets a blank
  canvas and no message.
- Low-power heuristic: `max-width: 780px` **or** `hardwareConcurrency < 6` → antialias
  off, pixel ratio capped at 1.5.
- Pointer Events throughout, so touch works.
- **Pan is disabled** (`controls.enablePan = false`). The PRD requires pan (§6/5.1).
- Keyboard: arrow keys rotate, `+`/`-` zoom, `Escape` deselects, canvas is
  `tabIndex = 0` with an `aria-label`. A reasonable start, not complete.
- No `prefers-reduced-motion` handling anywhere, despite constant GSAP animation and a
  default auto-rotate. PRD §31 requires it.

### 2.8 Tests

One file, `tests/rendered-html.test.mjs`, and it tests the **starter template's**
loading skeleton (`<title>Your site is taking shape</title>`), not the anatomy app.
Effective test coverage of the 3D layer and the product: **zero**.

---

## 3. Licensing — the release gate

**Status: BLOCKED.** This is the single highest-priority open item and it gates
public deployment.

### 3.1 Code

The repository declares **no license**. There is no `LICENSE` file and the GitHub API
returns `license: null`. Under default copyright, "no license" means **all rights
reserved** — public availability of source is not a grant of rights to copy, modify, or
redistribute it. Its 3,158 stars change nothing legally.

Copying `viewer.ts` / `hotspots.ts` / `loaders.ts` into our product and deploying it
publicly would be redistribution of someone else's copyrighted work without permission.

### 3.2 Models

All nine GLBs are **Tripo AI generated** (`tripo_node_*` / `tripo_mesh_*` naming). No
GLB carries an `asset.copyright` field or any `extras` metadata. Rights in Tripo output
depend on the generating account's plan and terms; those rights sit with the repository
owner, not with us, and — again — no grant has been made to downstream users.

Because they are generative output rather than derived from scanned or dissected
anatomy, they are also of **unverified anatomical accuracy**. For an educational
product aimed at students this is a content-quality risk independent of the legal one.

### 3.3 Other assets

- 45 `.webp` illustrations under `public/anatomy/` — provenance undocumented; same
  "no license" status as everything else in the repo.
- `og.jpg`, icons — same.
- `public/draco/`, `public/basis/` — these are the Apache-2.0 Google Draco and Binomial
  Basis transcoders, which *are* redistributable. They are also unused and should be
  deleted regardless.
- npm dependencies (three, gsap, lucide-react, React, Next) are all permissively
  licensed and are ours to use directly from npm. GSAP is now Apache-2.0-equivalent for
  standard usage; verify at pin time if we keep it.

### 3.4 Required actions (blocking public deployment)

1. **Contact the repository owner** (`thebuggeddev`) and request an explicit written
   license grant covering (a) the source under a permissive OSI license and (b) the
   models for modification and redistribution. Record the response.
2. **In parallel, do not depend on the answer.** Architecture is designed so the 3D
   implementation sits behind an interface we own (see `architecture.md` §5). If the
   grant does not arrive:
   - **Code:** the *techniques* documented in §2.4 are not copyrightable — surface
     snapping, screen-space sprite picking, render-on-demand, depth prepass. We
     reimplement them against Three.js from our own notes. Budget this as real work,
     not a rename.
   - **Models:** replace with openly-licensed anatomy. Verified candidates:
     **BodyParts3D / Anatomography** (CC BY-SA 2.1 JP, per-structure meshes — which
     would *also* solve §2.2), **Z-Anatomy** (CC BY-SA, Blender source, per-structure),
     **NIH 3D Print Exchange** (mostly public domain / CC0). All three give us real
     sub-meshes and verified anatomy.
3. **Maintain an asset register** — `docs/asset-register.md`, one row per model,
   texture, and illustration: source, creator, license, modification rights,
   redistribution rights, commercial use, attribution string. No asset ships without a
   row. Attribution surfaces in the app's About page.
4. **Treat the CC BY-SA option honestly.** BodyParts3D and Z-Anatomy are share-alike.
   That obligation attaches to the models and derivative models, not to our application
   code, but the attribution and license notice must ship.

---

## 4. The target project — current state

`/home/shariya/Downloads/antony` contains **only `PRD.md`**. There is no Laravel
application, no `composer.json`, no `artisan`, no database.

Two things need correcting before work starts:

1. **It is not its own repository.** The directory sits untracked inside
   `/home/shariya/Downloads`, which is a git repo whose remote is
   `https://github.com/CWServer21/pos-rabeya.git` — an unrelated POS project whose
   working tree is currently showing hundreds of staged deletions. Committing here
   would push anatomy work into someone else's POS repository. **`git init` a fresh
   repository in `antony/` (or move the directory outside `Downloads/`) before the
   first commit.**
2. **The inherited `CLAUDE.md` is for a different project.**
   `/home/shariya/Downloads/CLAUDE.md` documents a "Laravel 10 training center
   management system" (Lead → FollowUp → Student pipeline, `admins` table,
   `Shiftbatch` model). None of it applies here. Its *general* rules are sound and
   worth carrying over — propagate renames to every usage site, no logic in Blade/
   templates, Eloquent over raw SQL, type every signature, thin controllers — but the
   architecture, commands, and domain sections must be rewritten for this project. A
   fresh `CLAUDE.md` should be authored in `antony/` once the skeleton exists.

---

## 5. Reuse decisions

Classification per PRD §1.3: `REUSE` (minimal change) · `ADAPT` (keep capability,
change implementation) · `REPLACE` (rebuild) · `BLOCKED` (legal/technical) ·
`UNKNOWN` (needs investigation).

Every decision below is **conditional on §3 clearing**. Where a license grant is not
obtained, `REUSE`/`ADAPT` on repo code becomes "reimplement the technique".

### 5.1 3D layer

| Component | Decision | Reason |
|---|---|---|
| `lib/three/viewer.ts` | **ADAPT** | The scene, lighting, loop, and input handling are the highest-value asset in the repo. Extract into a framework-agnostic ES module we own. Changes: enable pan; add programmatic `selectStructure`/`focusStructure`; redefine `isolate`/`layers` honestly (§2.5); add `prefers-reduced-motion`; add a WebGL-unavailable fallback; replace the `onSelect`-only callback set with a small typed event emitter; strip the unused TSL import path. |
| `lib/three/hotspots.ts` | **REUSE** | Surface snapping, occlusion fade, screen-space picking, and flash feedback are correct and hard-won. Only change: accept structures from the API rather than a static import, and take colour from server data. |
| `lib/three/loaders.ts` | **REUSE** | `AnatomyAssetManager` already satisfies the PRD's 3D performance requirements. Only change: model URLs come from the API; add a KTX2 loader alongside Meshopt if we re-encode textures. |
| `lib/three/dispose.ts` | **REUSE** | 20 lines, correct. |
| `lib/three/tsl-materials.ts` | **REPLACE** *(delete)* | Dead code. Nothing imports it. WebGPU is out of MVP scope. |
| Quiz mode in the viewer | **ADAPT** | The interaction is right; the state is wrong. Currently client-only and ephemeral. Keep `setQuizMode`/`onPick`/`flash`; route answers to Laravel for validation and persistence — the client must never hold the answer key. |
| Authoring mode (`?authoring=1`) | **REUSE** | Becomes the admin structure-authoring tool, gated behind admin authorization instead of a query string, writing to the DB instead of the clipboard. |
| Hotspot data model (`id` + `ta` + `position`) | **ADAPT** | The concept is correct and better than the PRD's. Moves from a TS literal into MySQL. `ta` becomes the canonical cross-locale/RAG key. See §5.5. |
| **The nine GLB models** | **BLOCKED** | No license (§3.2), unverified anatomy, and — decisively — single-mesh (§2.2). Usable for the MVP demo *if* a grant arrives; must be replaced with per-structure meshes for the product to mean what the PRD says it means. |
| `public/draco/`, `public/basis/` | **REPLACE** *(delete)* | 1.6 MB of unreferenced payload. Reintroduce a KTX2 transcoder only if we actually re-encode to KTX2. |

### 5.2 Application shell

| Component | Decision | Reason |
|---|---|---|
| `AnatomyApp.tsx` | **REPLACE** | React/Next component with hardcoded content, non-functional nav, and no data layer. The learning platform's shell is a different product. Keep it open as a **visual reference** — the layout and information density are good. |
| `OrganViewer.tsx` | **ADAPT** | Rewrite as a Vue component, but the imperative-ref discipline (viewer owns its own lifecycle, callbacks read through refs, callout positioned outside the render cycle) transfers directly and must be preserved in Vue. |
| `LabelQuiz` (in `OrganViewer.tsx`) | **ADAPT** | Interaction design is genuinely good — reveal the correct structure on a miss, position feedback away from the dot it points at, longer dwell on a wrong answer. Reproduce the UX; move scoring server-side. |
| `globals.css` (37 KB) | **ADAPT** | Hand-written, cohesive, and the reason the app looks like a product. Harvest the design language (palette, plinth/atelier framing, callout, tool rail, quiz bar). Do not import wholesale into a Tailwind build. |
| `i18n/` (12 locales) | **REPLACE** | 180 KB of client-bundled TS dictionaries. Multi-language is **not in the PRD** for the competition MVP. The *pattern* (structure separated from prose, TA term as translation anchor) is excellent and is preserved in the DB schema; the implementation is dropped. English only for MVP; the schema leaves room. |

### 5.3 Backend / infrastructure

| Component | Decision | Reason |
|---|---|---|
| Next.js App Router | **REPLACE** | Target is Laravel + Inertia + Vue. |
| Cloudflare Workers / `vinext` / `wrangler` | **REPLACE** | Conflicts with a Laravel/PHP deployment. |
| Drizzle + D1 | **REPLACE** | Empty schema; nothing to migrate. Target is MySQL + Eloquent. |
| `worker/index.ts` (image optimisation) | **REPLACE** | Cloudflare-specific. Laravel/Vite handles assets. |
| `.openai/`, `build/sites-vite-plugin.ts`, `examples/d1/`, template `README.md` | **REPLACE** *(discard)* | Generator scaffolding with no product value. |
| `tests/rendered-html.test.mjs` | **REPLACE** | Tests the template, not the product. |
| `chatgpt-auth.ts` | **REPLACE** | Laravel session auth (§ architecture). |

### 5.4 Content

| Component | Decision | Reason |
|---|---|---|
| Organ prose (`i18n/organs/en.ts`) | **ADAPT** | ~11 KB of per-organ copy — description, function, location, blood supply, size, weight, tissue, fun fact, conditions, plus a label + detail per hotspot. Real, usable seed content, **but AI-generated and unverified**. Migrate into `organs`/`anatomical_structures`, then **fact-check against a curriculum source before it becomes canonical or enters the RAG index.** Its licensing status is the same as the rest of the repo (§3.1). |
| 45 organ illustrations | **UNKNOWN** | Attractive and useful for lesson cards, but provenance is undocumented and they are not needed for MVP. Defer; exclude until §3 resolves. |

### 5.5 Consequences for the PRD's data model

The PRD's `structure.model_object_name: Heart_LeftVentricle` (§7) **cannot be
implemented against the existing assets.** The platform's structure identity must be:

```
structure
  id                 left-ventricle          # stable slug, PRD-compatible
  organ_id           heart
  ta_term            Ventriculus sinister    # canonical anatomical identity  (from audit)
  display_name       Left Ventricle
  anchor_position    [0.70, -0.75, 0.65]     # pivot space, FIT_SIZE = 3.8    (from audit)
  model_object_name  NULL                    # reserved for per-structure meshes
```

`anchor_position` is the **primary** selection mechanism and is what the MVP ships on.
`model_object_name` is nullable and unused today; if the models are replaced with
per-structure geometry (BodyParts3D / Z-Anatomy), it becomes populated and the viewer
gains a mesh-raycast picking path **without any schema change or API change**. This
keeps the licensing decision (§3.4) from rippling into the data model.

The coordinate space is a hard contract: **every model must be normalised to
`FIT_SIZE = 3.8` and centred**, or every authored coordinate in the database becomes
meaningless. This constraint is inherited from `loaders.ts` and is documented in
`architecture.md` §5.4 as an integration rule.

---

## 6. Open questions

| # | Question | Owner | Blocks |
|---|---|---|---|
| 1 | Will `thebuggeddev` grant a license for code and models? | Project lead | Public deployment |
| 2 | If not — do we reimplement the viewer, or adopt BodyParts3D/Z-Anatomy from the start? | Project lead + 3D | Phase 1 exit |
| 3 | Is the existing organ prose accurate enough to seed lessons and the RAG index? | Content reviewer | Phase 3, Phase 5 |
| 4 | Which curriculum source grounds the RAG index? (needs to be redistributable) | Content reviewer | Phase 5 |
| 5 | Which 3 organs for the MVP? Recommended: **heart** (6 structures, strongest demo story), **lungs** (5), **brain** (4). | Product | Phase 3 |
| 6 | Are 3–6 hotspots per organ enough for a credible quiz? Likely no for lungs/brain — plan authoring time to reach ~8 per MVP organ using the existing tool. | Product + content | Phase 3 |
| 7 | LLM provider and budget for the competition demo. | Project lead | Phase 4 |

## 7. What this means for the plan

The audit changes the shape of the work in three ways:

1. **The 3D layer is a real head start, but not the one the PRD assumed.** We inherit an
   excellent viewer and a sound hotspot-based interaction model. We do *not* inherit
   per-structure meshes, so "isolate the left ventricle", "hide a layer", and "animate
   the valve" must be reinterpreted as things a single-mesh model can honestly do —
   marker emphasis, camera focus, whole-organ clipping, overlay tinting — or wait for
   replacement models. `architecture.md` §5.3 defines exactly what each interface method
   does under the current assets.
2. **The entire backend is greenfield.** Nothing to migrate, nothing to preserve. That
   is good news for schedule certainty.
3. **Licensing is on the critical path, not the polish phase.** It should be actioned in
   week one, in parallel with Phase 1, because the fallback (reimplement + resource new
   models) is weeks of work, not days — and the fallback models would make the product
   *better*.
