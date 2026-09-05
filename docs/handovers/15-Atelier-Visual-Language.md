# Handover 15 — Atelier Visual Language

**Feature:** F15 · **Lane:** Design/Frontend · **Wave:** 8 (post-F14)
**Branch:** `feat/f15-atelier-visual-language`

> Read first: `docs/architecture.md` §5.1, §5.3, §5.4 · `docs/engineering.md` §4, §11
> · handover 05 (Explore Experience) · handover 14 (Demo Polish) §"Existing code to reuse"
> · `docs/project-context.md` §5.2

## Objective

The application works. It does not yet look like a product. Replace the current
generic UI with a coherent visual language — warm paper, serif display type, a
floating tool rail, and a proper information panel — so that a judge opening
`/explore` understands within five seconds that this is a considered piece of
educational software and not a framework demo.

Reference screenshots are in `docs/design/reference/`. They are frames from the
upstream's live deployment (now offline). **Harvest the design language; do not
port the stylesheet or any React.** F14 already licenses this approach; the
constraint is unchanged.

## Scope

- `resources/css/theme.css` — the token layer, Tailwind 4 `@theme` block
- `resources/js/Pages/Explore.vue` and its child components (F05's territory)
- `resources/js/Layouts/**` — header, nav, search
- Callout and hotspot presentation in `resources/js/anatomy/HotspotLayer.ts`
  **(F04's territory — see Ownership below)**
- Renderer material and lighting pass in `resources/js/anatomy/AnatomyViewer.ts`
  **(F04's territory)**

## Out of scope

New features of any kind. New viewer methods. Any change to `app/Contracts/**`,
`types.ts`, or `config/anatomy.php`. Any change to answer-stripping, scoring, or
AI behaviour. If a visual improvement seems to require a schema change, stop and
report it — do not migrate.

## Ownership boundaries — read this before the first commit

This handover deliberately crosses two lanes. Split it into **two branches**:

**Branch A — `feat/f15a-atelier-chrome`** (F05 lane)
Owns: `theme.css`, layouts, `Explore.vue` and its children, all Vue components.

**Branch B — `feat/f15b-viewer-presentation`** (F04 lane)
Owns: `resources/js/anatomy/**` — renderer setup, materials, hotspot rendering.
Adds **no new methods** to the §5.2 interface. Presentation only.

Do not mix them in one commit. Land A first; B is independent and can follow.

## Prerequisites — verify before starting, do not invent

1. **Organ thumbnails.** The left rail shows a rendered thumbnail per organ.
   Check whether F02's manifest emits one. If not, report it — do not generate
   placeholder images and do not screenshot the live canvas at runtime.
2. **Key facts data.** The info panel needs `tagline`, `key_facts` (JSON),
   `did_you_know`, and `medical_importance` on `organs`. Check the F03 schema.
   If absent, build the panel against a typed fixture, mark the section
   `v-if`-guarded, and raise the column request in your PR. **Do not write the
   migration** — F03 owns that table.
3. **Capability reporting.** Confirm the viewer emits `capability:degraded`. The
   tool rail depends on it.

---

## PHASE 0 — Token layer

Everything else reads from here. No hardcoded colour, radius, shadow, or font
value anywhere else in the codebase after this phase.

Tailwind 4 is CSS-first — define these in `@theme`, not a JS config.

```css
@theme {
  /* Paper — the whole product sits on warm off-white, never pure grey */
  --color-paper:        #FBF7F3;
  --color-surface:      #FFFFFF;
  --color-surface-sunk: #F6F0EA;

  /* Ink — warm near-black, never #000 and never blue-grey */
  --color-ink:          #2A2320;
  --color-ink-soft:     #6B5D55;
  --color-ink-muted:    #9C8E85;
  --color-hairline:     #EBE1D8;

  /* Accent */
  --color-accent:       #E3705A;
  --color-accent-soft:  #FCEFEA;
  --color-accent-ink:   #B4services4A38;

  /* Functional */
  --color-hotspot:      #E8722E;
  --color-hotspot-live: #2563EB;
  --color-note:         #FDF4D6;
  --color-note-edge:    #F0E3B0;

  /* Shadows — warm-tinted, never neutral black */
  --shadow-card:  0 1px 2px rgb(42 35 32 / .04),
                  0 8px 24px rgb(42 35 32 / .06);
  --shadow-rail:  0 2px 6px rgb(42 35 32 / .06),
                  0 12px 32px rgb(42 35 32 / .08);

  --radius-card: 1.125rem;
  --radius-tile: 0.75rem;
}
```

**Typography — three faces, self-hosted, subset, `font-display: swap`:**

- Display serif — **Fraunces** (variable). Organ names, page headings, the
  wordmark. Enable optical sizing. This face carries the entire personality.
- Body serif — **Spectral**. Descriptive prose in the info panel and lessons.
  Long-form reading, not UI.
- UI sans — **Inter**. Nav, buttons, labels, form controls, tool rail captions.

Scale: display 44/1.1 · title 28/1.2 · subtitle-italic 18/1.4 ·
body 16/1.7 · ui 14/1.5 · label 11/1.4 uppercase tracking 0.14em.

The letterspaced small-caps label (`ORGAN LIBRARY`, `KEY FACTS`, `THE HEART`) is
a recurring motif. Build it once as a component, use it everywhere.

**Dark theme is not in scope for this handover.** The atelier language is
light-first. Do not half-build a dark variant.

Deliverable: a `/design-tokens` route rendering every token, type step, and
component state on one page. It is your review surface for every later phase.

---

## PHASE 1 — Application shell

- Wordmark in display serif, with the tagline beside it in italic serif at
  `--color-ink-muted`. The tagline is part of the identity, not decoration.
- Nav items: small line icon + label, sans. Active state is a soft pill in
  `--color-accent-soft` with `--color-accent-ink` text — not an underline, not
  a filled blue rectangle.
- Search: full pill, `--color-surface-sunk`, inset leading icon, placeholder in
  `--color-ink-muted`.
- Nav renders from `config/navigation.php` as it already does. **Do not edit
  that file or the base layout's data flow** — F01 owns them. You restyle.

---

## PHASE 2 — Organ library panel

- Panel: white card on paper, `--shadow-card`, `--radius-card`, section label
  header, `View all organs →` footer link.
- Each row: organ thumbnail tile (`--radius-tile`), organ name in display
  serif ~17px, body-system name beneath in sans 12px `--color-ink-muted`.
- Active row: `--color-accent-soft` fill, 1px `--color-accent` border at 30%
  opacity, no heavy focus ring.
- Hover prefetches the organ (F05 already implements this — keep it working;
  do not let a restyle drop the prefetch handler).
- Group by body system with sticky section headers once the organ count passes
  ~12. Below that, a flat list reads better — do not add the grouping early.

---

## PHASE 3 — Canvas frame

This is the phase that does the most visual work.

- The canvas sits in a white card that fills its column. No visible border,
  soft shadow only.
- **Plinth.** The model rests on a soft elliptical contact shadow that fades to
  transparent at its edges. Baked or a gradient-textured plane — **not** a
  shadow map, and **not** the hard grey dome currently rendering. Render-on-
  demand must stay intact; a shadow map would break the idle-frame budget.
- **Tool rail.** Vertical, floating over the canvas on the left, white pill
  column, `--shadow-rail`, ~64px wide. Each tool: 20px line icon above a 10px
  sans caption.

  **Label each tool for what it actually does.** Under single-mesh models the
  honest rail is:

  | Icon | Caption | Behaviour |
  |---|---|---|
  | rotate | Rotate | orbit |
  | zoom | Zoom | dolly |
  | move | Pan | pan — required by the PRD, upstream disabled it |
  | slice | Cross-section | one global clipping plane |
  | grid | Wireframe | wireframe material — **not** "Layers" |
  | target | Focus | dim others to ~35% + fly camera — **not** "Isolate" |
  | undo | Reset | reset view |

  A tool reporting `capability:degraded` is **hidden, not disabled-with-a-
  tooltip**. Do not ship the upstream's "Compare" — it was a 2D drawer wearing
  a 3D tool's icon.

- **Tip note.** Top-right of the canvas: sticky-note card in `--color-note`
  with a `--color-note-edge` border, slight rotation (~0.6deg), body serif.
  Dismissible, and the dismissal persists per user in localStorage.
- **Specimen label.** Bottom-left, tiny letterspaced caps:
  `3D SPECIMEN · {organ name}`.
- **Auto-rotate toggle.** Bottom-right, a real switch with a label — accent
  fill when on. Default **off** when `prefers-reduced-motion` is set, and the
  toggle reflects that rather than silently disagreeing with itself.

---

## PHASE 4 — Hotspots and callout  *(Branch B)*

- Resting marker: filled dot in `--color-hotspot`, 2px white ring, soft drop
  shadow so it reads against both pale and dark tissue.
- Active marker: `--color-hotspot-live` with a wider white ring and one
  ~400ms scale-in. No looping pulse.
- Occluded marker: fades per the existing facing test. Keep that logic — it is
  the reason a dot never punches through to the far side.
- Callout: white card, `--radius-card`, `--shadow-card`. Coloured dot +
  structure name in display serif + close button. One-line function summary
  beneath in `--color-ink-soft`. It has a thin leader line to its marker.
- **Positioning stays imperative, inside the viewer.** A spinning model must
  cost zero Vue re-renders. If you find yourself binding callout coordinates to
  a `ref`, you have taken the wrong approach — F05's PR documents why.
- The `<ul>` screen-reader structure index stays in sync. Do not let a visual
  refactor orphan it.

---

## PHASE 5 — Information panel

Top to bottom:

1. Small-caps label with the system's dot: `THE HEART`
2. Organ name, display serif, 44px
3. Tagline, italic serif, `--color-ink-soft` — "The tireless pump"
4. Small circular organ thumbnail, top-right, aligned to the name
5. Description, body serif 16/1.7
6. `KEY FACTS` label, then rows of `icon · label · value`, label left in
   `--color-ink-soft`, value right in `--color-ink`, hairline between rows
7. Two tinted callout cards — **Medical importance** and **Did you know** —
   each with a leading icon on `--color-surface-sunk`
8. Primary CTA: `View lesson →`, filled `--color-accent`, full width
9. Secondary pair side by side: `Animate` · `Quiz`, outline
10. Tertiary full-width outline: `Compare`

**Honesty rules for the action buttons.** `Animate` must only render when the
organ actually has a scripted step list — the models carry zero GLTF clips, and
a play button that does nothing is worse than no play button. `Compare`
likewise only renders if the comparison view exists. Hide, don't stub.

The panel scrolls independently with the page header pinned.

---

## PHASE 6 — Render quality  *(Branch B)*

The models will not look like the reference until the renderer is set up
properly. This is the highest-payoff phase and the easiest to skip.

- `outputColorSpace = SRGBColorSpace`, `toneMapping = ACESFilmicToneMapping`,
  exposure ~1.05.
- PMREM environment map baked **once** at init (already the pattern per the
  F04 audit — verify it survived).
- `MeshPhysicalMaterial`: roughness ~0.45, clearcoat ~0.25,
  clearcoatRoughness ~0.4. Tissue should read wet, not matte.
- Tint per organ from `organs.accent`, **desaturated**. Real tissue is muted;
  let the lighting create the depth, not the saturation slider.
- `setPixelRatio(Math.min(devicePixelRatio, 2))`.
- **Do not add a post-processing composer.** SSAO and outline passes would
  force a continuous render loop and break the render-on-demand contract and
  the idle-frame budget. If you believe a pass is essential, raise it with a
  measured frame-cost number — do not add it and see.
- Camera transitions on organ or structure change: ~700ms ease-out tween,
  shortened to ~150ms under `prefers-reduced-motion`.

---

## PHASE 7 — Full-body organ coverage  *(scaffold only)*

The eventual target is every major organ across all eleven body systems. That
is **content and asset work**, gated by F02's licence decision and F03's
seeding — it is not a UI task and it does not happen on this branch.

What you do here: make the UI degrade honestly at scale.
- Body-system grouping and sticky headers in the library panel (see Phase 2).
- A `coming soon` row state for a system present in the taxonomy with no
  published organs — visibly inert, not clickable, not a 404.
- Confirm the layout holds at 60+ organs without the panel becoming a scroll pit.

Then stop, and report to F02/F03's owners what the UI is ready to receive.

---

## Tests

- Vitest: a restyled `Explore.vue` still disposes the viewer on unmount
- Vitest: a tool reporting `capability:degraded` is absent from the DOM
- Playwright: WebGL disabled → text fallback renders in the new visual language,
  no broken layout, no error surface
- Playwright: `prefers-reduced-motion` → auto-rotate off, tweens shortened
- Axe: WCAG 2.2 AA clean on `/explore` — pay particular attention to contrast,
  since warm greys on warm paper fail easily. `--color-ink-muted` on
  `--color-paper` must be checked, not assumed.
- Bundle assertion: initial JS excluding the Three.js chunk still under
  200 KB gzip after the font and token additions
- Idle frame cost still ~0 — render-on-demand intact

## Acceptance criteria

1. `/explore` is visually coherent with the reference language: warm paper,
   serif display type, floating rail, plinth, sticky note, three-column layout.
2. Every visual value in the codebase resolves to a token.
3. Every tool in the rail does exactly what its caption says, or is not shown.
4. No performance budget from `docs/architecture.md` §15.1 regressed —
   with recorded before/after numbers, not an impression.
5. No accessibility blocker introduced; contrast verified numerically.
6. `/boundary-audit` and `/verify` clean.

## Constraints

- Do not import the upstream stylesheet, and do not port any React.
- Do not add a feature, a viewer method, a migration, or a dependency beyond
  the three self-hosted font families.
- Do not weaken a test to make a visual change pass.
- Do not touch `app/Contracts/**`, `types.ts`, or `config/anatomy.php`.

## Commit boundary

**Branch A:** `feat(design): token layer and type scale` →
`feat(design): application shell` → `feat(design): organ library panel` →
`feat(design): canvas frame, rail and tip note` →
`feat(design): information panel` → `fix(a11y): contrast and focus states` →
`test(design): visual regression and budget assertions`

**Branch B:** `feat(viewer): renderer colour and tone mapping` →
`feat(viewer): material and plinth pass` →
`feat(viewer): hotspot and callout presentation` →
`perf(viewer): confirm render-on-demand intact`

Start with Phase 0 and the `/design-tokens` route. Show me that page before
touching any other file.