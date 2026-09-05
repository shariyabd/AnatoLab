# CLAUDE.md

Guidance for Claude Code working in this repository.

> Note: a `CLAUDE.md` also exists one directory up (`/home/shariya/Downloads/CLAUDE.md`).
> It documents an unrelated Laravel 10 training-center app. **This file is authoritative
> for this project; ignore the parent one.**

## Project

Interactive 3D Anatomy Learning Platform — Laravel 11 + Inertia + Vue 3 + MySQL,
with a framework-free Three.js viewer library.

Design and rules live in `docs/`:

- `docs/architecture.md` — what we build (layering, dependency rules, integration contracts)
- `docs/engineering.md` — how we build it (the seven invariants, naming, typing, review gates)
- `docs/project-context.md` — what we found in the upstream repo (load-bearing constraints)
- `docs/feature-plan.md`, `docs/handovers/` — the numbered work lanes
- `docs/asset-sources.md` — which asset sources may be used, in what order, and what each obliges
- `docs/adding-an-organ.md` — how to source, licence, encode and seed a new organ

Two upstream facts constrain the design and are not negotiable without a plan change:
the 3D models are **single-mesh** (no per-structure geometry — see `project-context.md` §2.2)
and the upstream repo has **no licence** (§3).

## Commands

```bash
# Development
composer dev          # server + queue + logs + vite, all at once
php artisan serve     # backend only
npm run dev           # Vite dev server only
npm run build         # production assets

# Tests
composer test         # config:clear + pest (full PHP suite)
./vendor/bin/pest --filter=SomeTest
npm run test          # vitest (viewer + JS units)
npm run test:e2e      # playwright

# Quality
composer lint         # pint
composer analyse      # phpstan
npm run typecheck     # vue-tsc --noEmit
npm run format        # prettier over resources/js, resources/css

# Database
php artisan migrate
php artisan migrate:fresh --seed
```

Two project skills wrap this: `/verify` runs the full gate (Pint, PHPStan, Pest,
Vitest, migrations, boundary audit); `/boundary-audit` sweeps the repo for
dependency-rule violations.

## The invariants

These are load-bearing. Each one backs a decision in `docs/architecture.md`;
breaking one costs real rework, not tidiness. They are no longer enforced by hooks —
follow them.

1. **Services never read `request()` / `auth()` / `session()`.** Pass the `User` and
   validated input in as typed arguments, or the service cannot be queued or unit-tested.
2. **Services depend on `App\Contracts`, never `App\Infrastructure` concretes.**
   Type-hint the interface; let `AppServiceProvider` bind it.
3. **`resources/js/anatomy/` imports no Vue, no Inertia, and never fetches.** It is a
   standalone library. The only bridge is `resources/js/composables/useAnatomyViewer.ts`;
   it receives a fully-formed `OrganDto`.
4. **The client never receives an answer key.** No `is_correct`, `correct_structure_id`,
   `correct_option_id`, or `correct_sequence` reaches `resources/js/` or `resources/views/`.
   API Resources strip them; correctness is decided server-side.
5. **`FIT_SIZE` is `3.8`.** Every `anchor_position` in the database is authored in that
   normalised pivot space. Changing it silently invalidates all of them.
6. **Eloquent only in `app/`**, and `declare(strict_types=1);` on every PHP file.
   No `DB::raw` / `DB::table` / manual joins where a relationship works. Migrations and
   seeders may use `DB::statement` where Eloquent genuinely cannot express it.
7. **Controllers stay thin.** Validate via a FormRequest, delegate to a Service, return
   a Resource. Never pass `$request->all()` onward. Models hold state, not behaviour —
   they must not depend on `App\Services` or `App\Http`.
8. **Templates display, they do not query.** No `::where()`, `->get()`, `->first()`, or
   `DB::` in `resources/views/`, `resources/js/Pages/`, or `resources/js/Components/`.

Full detail, including naming and typing rules, is in `docs/engineering.md` §1–§2.

## Working agreement

- **Implement exactly what I ask.** Don't widen scope, don't add features, docs,
  changelogs, or refactors I didn't request. If you spot a real problem, say so in a
  sentence and keep going.
- **Never `git commit`, `git push`, or otherwise write to git history on your own.**
  I review and commit myself. Only when I explicitly say "commit" or "push" do you do
  it — and then just do it, no confirmation needed.
- Don't hand work back half-done. Finish the task, then report what you did and what
  (if anything) you couldn't do.
- Verify before claiming success. Run the relevant command and show the output rather
  than asserting it passes.
