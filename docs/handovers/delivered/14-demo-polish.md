# Handover 14 — Demo Journey & Competition Polish (delivered)

**Branch** `feat/f14-demo-polish` · **Contract** [`14-demo-polish.md`](../14-demo-polish.md) · **Status** delivered

## What shipped

The all-hands pass: one Playwright test walking PRD §44 end to end, the demo dataset that makes
that walk possible on a cold database, a WCAG 2.2 contrast audit committed as tokens plus a test,
a bundle-budget script, a public attribution page rendering 02's asset register — and one real
bug the journey found and fixed.

- `DemoSeeder` — asserts the journey's spine exists, then seeds the knowledge corpus the demo's
  cited answer is retrieved from, through the real ingest pipeline
- `tests/Browser/journey.spec.ts` — nine `test.step`s from registration to a recommended next
  activity, in one browser, as one new student
- `tests/Browser/accessibility.spec.ts` — the mechanically checkable half of the WCAG audit
- Public `/attribution` page rendering `docs/asset-register.md`, with the release gate read from
  `public/models/manifest.json` rather than from prose
- Landing page, first-run onboarding panel, and auth pages written in what a student *does*
- **CSRF rotation fix** (below) — without it a newly registered student could not use any
  `fetch`-backed feature

## Public surface

### Routes (`routes/features/attribution.php`)

| Method | URI | Name | Controller |
|---|---|---|---|
| GET | `/attribution` | `attribution` | `Web\AttributionController` (invokable) |

Public — no `auth`: "attribution that requires an account is not attribution."

### Services / seeders

| Class | Responsibility |
|---|---|
| `App\Services\Content\AssetRegisterDocument` | Reads `docs/asset-register.md`, converts it (`html_input: strip`, `allow_unsafe_links: false`), demotes headings, reports `assetsCleared` / `modelCount` from the manifest |
| `Database\Seeders\DemoSeeder` | Spine assertions + the knowledge corpus. Registered **last** in `DatabaseSeeder` |

### Shared Inertia props

`HandleInertiaRequests::share()` now emits `csrfToken` alongside `auth.user` (an explicit field
list, not the model), `navigation` (from `config/navigation.php`, filtered by role and sorted by
declared order) and `flash`. `resources/js/types/inertia.d.ts` mirrors it as an augmentation of
Inertia's `PageProps`.

### Schema

None. Polish only, per the contract.

## Key decisions

- **The demo seeder asserts; it does not duplicate.** The heart, the blood-circulation lesson,
  the spatial question on the left ventricle, the Trace the Blood mission and the mitral-valve
  simulation are owned by other lanes' seeders. `DemoSeeder` resolves each one and throws a
  message naming the **PRD §44 step** it protects — "a demo whose spine is broken must fail the
  `migrate:fresh --seed` step of the verify gate, not be discovered on stage." It requires the
  heart plus eight named structures (`right-atrium`, `right-ventricle`, `left-atrium`,
  `left-ventricle`, `aorta`, `pulmonary-trunk`, `superior-vena-cava`, `mitral-valve`).
- **The corpus goes through the real ingest chain** — `KnowledgeService::storeText` →
  `ProcessKnowledgeDocument` → `GenerateEmbeddings` → `SyncVectorStore` — with
  `queue.default` forced to `sync` for the duration and restored in a `finally`, because
  `migrate:fresh --seed` would otherwise finish with every document `pending`. Passages are tagged
  with `organ_id` / `structure_id` / `education_level` because the tutor filters on all three
  exactly, and the left-ventricle material is authored at **all three education levels**. The
  prose is original: the corpus sits under the same licence gate as the 3D assets.
- **A real CSRF-rotation bug, found by walking PRD §44.** `app.blade.php` renders
  `<meta name="csrf-token">` once at document load, but login and registration both call
  `session()->regenerate()` and both are Inertia visits, so the document never reloads and the tag
  keeps the pre-rotation token. Every `fetch` in `resources/js/composables` then sent a stale
  token: a newly registered student "could not ask the tutor, answer a question, run a mission,
  step a simulation, or record a lesson step." Fixed in two halves —
  `HandleInertiaRequests::share()` publishes `csrfToken` (not a secret: the same value is already
  in the page's meta tag and is only useful to the session holding it), and
  `resources/js/app.ts` writes it back into the tag on `initialPage` and on every
  `router.on('success')` — once in `app.ts` rather than in the six composables, because "they are
  right to read [the tag], and the tag was the thing that was wrong." Proved by
  `tests/Feature/Platform/CsrfTokenShareTest.php`: the token is shared on every page, and it is
  the token *after* registration's and login's rotation. The registration case calls
  `forgetGuards()` first, to reproduce the browser's second request rather than asserting against
  an in-process state no deployment ever has.
- **The WCAG audit is committed as numbers, not as a report.** `resources/css/app.css` records the
  ratio each token clears next to it, measured in both themes with the browser doing the
  oklch → sRGB conversion. **Three light-theme values changed:**

  | Token | Before → after | Why |
  |---|---|---|
  | `--color-accent` | `oklch(58% …)` → `oklch(54% 0.16 255)` | label-on-button was **4.20:1**; now 4.94:1 on a button and 4.96:1 as text |
  | `--color-success` | `oklch(62% …)` → `oklch(52% 0.14 155)` | the "Mission complete" green was **3.33:1** and is used at `text-sm`; now 4.97:1 |
  | `--color-border-strong` | **added** — `oklch(62% 0.02 265)` | the boundary of an interactive control, where the border is the only thing saying where the control begins: SC 1.4.11 asks 3:1, this is 3.56:1 on the page and 3.65:1 raised |

  All three "were readable but not compliant… passes for large text and fails for the 12–14px it
  is actually used at." The dark palette already cleared every threshold; nothing there moved
  except gaining the new control-boundary token.
- **The bundle budget lives in config; the script does the careful part.**
  `config/anatomy.php` → `budgets.max_initial_js_gzip_bytes` = `200 * 1024`, read via
  `scripts/lib/config.mjs` so the number exists once. `scripts/verify-bundle.mjs`
  (`npm run bundle:verify`) measures the **static** closure of the Inertia entry plus each page
  chunk — dynamic imports deliberately not followed — gzipped at level 9, **excluding the Three.js
  chunk**, identified by the module that pulls Three.js in (a `useAnatomyViewer` chunk-name regex)
  rather than by picking whatever is biggest. Invariant 3 is what makes one name sufficient.
- **The attribution page cannot claim clearance the release script would refuse.**
  `assetsCleared` is `manifest.status === 'cleared'`, read from the same
  `public/models/manifest.json` that `scripts/verify-models.mjs --release` checks.

## The licence gate — status: OPEN

`docs/licence-log.md` is headed **"Status: OPEN — blocking public deployment"**, and §4's decision
record reads `Decision: not yet taken`, with Date, Decided by and Rationale all blank.
`docs/asset-register.md` §6 has `Asset set signed off: none` and no signature.

The correspondence log records the licence re-verified against the GitHub API on 2026-09-05
(`license: null`, no LICENSE file at head `8c0e6f3` — all rights reserved) and the request drafted
but **not sent**: it is outbound correspondence in a person's own name and requires a named human.
The escalation trigger is **2026-09-19**.

Consequently `public/models/manifest.json` is `{"status": "pending-licence", "models": []}` and
`node scripts/verify-models.mjs --release` fails by design. **Nothing deploys publicly.** The
journey spec states this plainly in its own header — "The models 404… every assertion below is
therefore about the page rather than the model" — and passes without geometry.

## Invariants honoured

- **1** — `AssetRegisterDocument` touches no request, session or user; `AttributionController` is
  one service call and one render (**7**).
- **4** — `HandleInertiaRequests` shares an explicit field list rather than the `User` model, "so
  an accidental new column would otherwise ship to the browser the moment it is added."
- **8** — `Home.vue`, `Attribution.vue` and `FirstRun.vue` query nothing and derive nothing;
  `Attribution.vue`'s `v-html` renders server-sanitised output of a repository file, proved by
  `AttributionPageTest` ("strips any raw HTML the register might one day quote").

## Tests

| File | What it proves |
|---|---|
| `tests/Browser/journey.spec.ts` | **The release gate.** One new student, nine steps: register → open the heart and interact → select the left ventricle → ask the tutor and get a cited answer → start the blood-circulation lesson → answer the heart round including spatial questions → run the mitral valve simulation → complete Trace the Blood → see mastery update and a recommended next activity. Requires a cold seeded DB, a running server and a **queue worker** (mastery is written by `RecalculateMastery`, never during a request), with `AI_RETRIEVAL_MIN_SCORE=-1` because `NullProvider` embeddings are a hash and cosine similarity between hashes is meaningless — the metadata filter is what keeps retrieval topical |
| `tests/Browser/accessibility.spec.ts` | Contrast pairs that actually occur, per theme, at 4.5 for text and 3.0 for control boundaries (SC 1.4.11); skip link first with focus landing in content; 24×24 minimum target size; reduced motion honoured without removing feedback; attribution page landmarks and heading order |
| `tests/Feature/Platform/CsrfTokenShareTest.php` | The token is shared on every page and is the one **after** registration's and login's session rotation |
| `tests/Feature/Demo/DemoSeederTest.php` | The corpus answers the demo question; the left-ventricle material is tagged at every education level; every passage scoped to the heart; idempotent; **nothing left on the queue** for a worker that may never run; fails loudly when a spine step is missing |
| `tests/Feature/Demo/AttributionPageTest.php` | Readable without an account; renders the register itself; reports the gate from the manifest, not from prose; strips raw HTML; reachable from the footer |
| `.github/workflows/ci.yml` | Secret scan over `public/build` for `sk-ant-` / 32-char `sk-` / `AKIA…` prefixes after `npm run build` |

## Known gaps / follow-ups

- **The licence decision is open** and is the hard gate: `docs/licence-log.md` §4 records
  *not yet taken*, `docs/asset-register.md` §6 is unsigned, the escalation trigger (2026-09-19)
  has not been acted on because sending the request needs a named human. Nothing deploys publicly
  until it is signed.
- **The 3D model manifest is a licence-gated placeholder.** `public/models/manifest.json` is
  `"status": "pending-licence"` with an empty `models` array and `generatedAt: null`; no GLB
  ships. The journey and the accessibility suite are written to pass without geometry, so what
  they prove is that the product is complete and teachable *in text* — not that the 3D path works
  end to end in a deployed environment. That verification is still owed (see 02, 04).
- **Only one performance budget is actually enforced.** `scripts/verify-bundle.mjs` covers initial
  JS gzip; the contract's other budgets — first organ interactive < 3 s, per-organ payload /
  triangles (blocked on there being a model), idle frame cost, cached anatomy endpoints < 100 ms,
  tutor round-trip < 6 s — have no assertion and **no recorded numbers anywhere in the repo**. The
  contract asked for measured figures; they are not written down.
- `npm run bundle:verify` is **not wired into `.github/workflows/ci.yml`**, so the budget it
  enforces is only enforced when someone runs it by hand.
- `docs/asset-register.md` §6 still has "Attribution strings surfaced in the About page (F14)"
  unticked (☐) even though this handover shipped that page — the checklist was not updated.
- Playwright's journey is documented as needing a hand-assembled environment (`migrate:fresh
  --seed`, `php artisan serve`, `queue:work`); there is no npm script or CI job that stands that
  up, so "passes on staging, not just locally" (acceptance criterion 2) is unverified here.
