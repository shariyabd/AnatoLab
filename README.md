# AnatoLab

Interactive 3D anatomy learning platform for secondary-school biology students.
Laravel 11 · Inertia 2 · Vue 3 · MySQL 8 · Three.js.

**Read before writing code:** [`PRD.md`](PRD.md) ·
[`docs/project-context.md`](docs/project-context.md) (what the audit found) ·
[`docs/architecture.md`](docs/architecture.md) (the target) ·
[`docs/engineering.md`](docs/engineering.md) (the rules) ·
[`docs/handovers/`](docs/handovers/README.md) (your scope).

---

## Setup

```bash
composer install
npm install

cp .env.example .env          # required — see "Known issues" if this file is missing
php artisan key:generate

php artisan migrate --seed
npm run build                 # or `npm run dev` alongside `php artisan serve`
```

Seeded accounts: `admin@anatolab.test` / `student@anatolab.test`, password `password`.

### Commands

| | |
|---|---|
| `php artisan serve` + `npm run dev` | Development |
| `vendor/bin/pest` | PHP tests |
| `npm run test` | Viewer unit tests (Vitest) |
| `npm run test:e2e` | Browser journey (Playwright; needs a running server) |
| `vendor/bin/pint` | Format PHP · `npm run format` for JS/Vue/CSS |
| `vendor/bin/phpstan analyse` | Static analysis, level 6 |
| `npm run typecheck` | `vue-tsc` |
| `/verify` | The whole gate at once (`docs/engineering.md` §11) |

---

## The four registries

Thirteen features are built in parallel by separate agents. Laravel funnels many
of them into a handful of shared files, and two agents editing one file is the
single largest hazard in that plan. Each of those files is therefore replaced by
**a per-feature file plus a registry** (`docs/feature-plan.md` §7).

**If you are implementing a feature, this section is the part of the README you
need.** All four files below are owned by Handover 01 and are never edited again.

### 1. Routes → `routes/features/<feature>.php`

`routes/web.php` and `routes/api.php` are closed. Drop a file in
`routes/features/` and `routes/features.php` requires it, sorted alphabetically
for a stable `route:list` across machines.

Feature files are required from `web.php`, so they inherit the `web` middleware
group — session and CSRF. That is what lets a feature's `/api/v1` endpoints
authenticate with the Inertia session cookie instead of needing Sanctum. Apply
your own `auth` / `admin` middleware; the loader adds none.

Prefix your route names with your feature (`explore.`, `quiz.`). A duplicate name
silently overrides the earlier one, and `route:list` — which `/verify` runs — is
where you would find out.

Full instructions: [`routes/features/README.md`](routes/features/README.md).

### 2. Seeders → `<Feature>Seeder`, one line in `DatabaseSeeder`

Write your own seeder class; append exactly one `$this->call()` entry to the list
in `database/seeders/DatabaseSeeder.php`. One line is the smallest possible merge
conflict. Order matters where one seeder needs another's rows — add yours after
what it depends on and never reorder someone else's.

Seeders must be idempotent: `db:seed` twice produces the same database, not
doubled rows. The demo dataset is a first-class artefact.

### 3. Container bindings → your own service provider

`AppServiceProvider` is closed. It binds `AIProviderInterface` and
`VectorStoreInterface` to their null implementations as a **baseline**, so the
container always resolves and the whole test suite gets a deterministic,
network-free implementation.

A feature needing real bindings registers its own provider (`AiServiceProvider`,
`RagServiceProvider`) in `bootstrap/providers.php`. Providers register in order,
and a later `bind()` wins — that is how Handover 08 swaps in the real Anthropic
provider without touching a file it does not own.

### 4. Navigation → `config/navigation.php`

The layout renders this array; no feature edits a Vue layout component. Append
your entry with an explicit `order` (leave gaps of 10) and the `roles` that may
see it. Sorting is by `order`, not by position in the file, so appending never
forces you to reposition anyone else's line.

Every `route` you name must exist —
`tests/Feature/Platform/NavigationRegistryTest.php` fails the build otherwise,
rather than letting it throw on whichever page loads first.

### Frozen after Handover 01

`app/Contracts/**`, `resources/js/anatomy/types.ts`,
`resources/js/anatomy/constants.ts`, and `config/anatomy.php` (`FIT_SIZE`).
Changing any of them breaks work in flight in other lanes: human approval, and
every consumer updated in the same commit.

`resources/js/anatomy/types.ts` is one half of a **two-way contract** — it mirrors
the API Resources that Handover 03 owns. Change both or neither; PHP and Vue both
fail silently when they disagree.

---

## Two facts that shape everything

**The models are single-mesh.** Every audited GLB is one node, one mesh, one
material — Tripo AI output. There is no per-structure geometry, so
`model_object_name` cannot work, and isolation, layers, and animation have
reduced semantics *by design*. Structure selection works through
`anchorPosition` in `FIT_SIZE = 3.8` normalised pivot space.
`docs/project-context.md` §2.2, `docs/architecture.md` §5.3.

**The upstream repository has no licence.** The GitHub API reports
`license: null` and there is no `LICENSE` file — all rights reserved by default.
Handover 02 owns resolving it and it gates public deployment. Until it clears,
every reuse of upstream code or assets is provisional. `docs/project-context.md` §3.

---

## Known issues

**Laravel 11 carries unpatched security advisories.** `composer audit` reports
three against `laravel/framework`, including CRLF injection in the default `email`
validation rule (CVE-2026-48019, high). **There is no fixed 11.x release** — the
patches landed in 12.60/12.61.

Mitigated here by using `email:rfc,strict` rather than the bare `email` rule in
every FormRequest, which rejects the folded and obsolete forms that carry CR/LF.
Grep for `'email:rfc,strict'` before adding a new email field.

The durable fix is upgrading to Laravel 12, which is an architecture decision
(`docs/architecture.md` §2 pins 11) and needs human sign-off. CI runs
`composer audit` advisory-only so this stays visible without blocking every build.

**Redis and Horizon are not exercised locally** if you have no `redis-server`.
Set `SESSION_DRIVER=file`, `CACHE_STORE=file`, `QUEUE_CONNECTION=database` for
local work; that is a local-only compromise, never a deployed configuration. CI
runs against real MySQL and Redis services.
