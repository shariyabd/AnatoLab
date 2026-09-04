# Handover 01 — Platform Foundation & Shared Contracts

**Feature:** F01 · **Lane:** Platform · **Wave:** 1 · **Branch:** `feat/f01-platform-foundation`

> Read first: `docs/architecture.md` §2, §4, §14 · `docs/engineering.md` §1–§6
> This handover does not repeat those. Where they conflict with this file, they win.

## Objective

Stand up the Laravel application and every convention the other 13 features are measured
against. Nothing domain-specific. When this merges, an agent can start any Wave 2 feature
without inventing a single convention.

## Scope

- Laravel 11 skeleton (PHP 8.3), MySQL 8, Redis (cache + session + queue), Horizon
- Inertia 2 + Vue 3 + Vite + Tailwind 4; `resources/js/app.ts`, base layout, error pages
- Auth: `users` table, register / login / logout, `role` (student|admin), `admin` middleware
- `app/Contracts/**` — the two interfaces and their DTOs
- `resources/js/anatomy/types.ts` — DTO **types only**, no implementation
- `config/anatomy.php` (`FIT_SIZE = 3.8`), `config/ai.php` skeleton, `config/navigation.php`
- The four anti-conflict mechanisms in `docs/feature-plan.md` §7
- Test harness (Pest, Vitest, Playwright), `NullProvider`, `NullVectorStore`, base factories
- Pint, PHPStan level 6, CI workflow, `/verify` pipeline green

## Out of scope

Any organ, structure, lesson, question, mission, simulation, or AI call. If you are writing
domain logic, you have left this handover. The dashboard is an empty shell.

## Dependencies / prerequisites

None — this is the root. But first: **`git init` a fresh repository.** The directory is
currently untracked inside an unrelated repo whose remote is `CWServer21/pos-rabeya.git`
(`docs/project-context.md` §4). Committing before this is done pushes into someone else's
project. Add `.gitignore` including `.claude/settings.local.json`.

## Existing code to reuse

None. This is greenfield. Do not copy anything from the upstream anatomy repository — it is
Next.js/Cloudflare and carries no licence (`docs/project-context.md` §3).

## Ownership boundaries

**You own** everything listed in Scope, plus `routes/web.php`, `routes/api.php`,
`DatabaseSeeder.php`, `AppServiceProvider.php`, and the base layout — **permanently**. No
later feature edits these files; they extend them through the registries you build.

**You must not** create any table other than `users`, `sessions`, `jobs`, `cache`,
`failed_jobs`.

## Required implementation

### Contracts (`app/Contracts/`)

```php
interface AIProviderInterface {
    public function chat(array $messages, array $options = []): AIResponse;
    public function generateEmbedding(string $text): array;      // float[]
    public function generateEmbeddings(array $texts): array;      // float[][] — batched ingest
}

interface VectorStoreInterface {
    public function upsert(array $documents): void;
    public function search(array $queryVector, int $topK = 5, array $filters = []): array;
    public function delete(array $ids): void;
}
```

`search()` takes a **vector, not a string** — embedding belongs to F11's `EmbeddingService`,
so both stores stay interchangeable. Also define the DTOs: `AIResponse`, `RetrievedChunk`,
`LearningContext`, `SimulationState`.

### Anti-conflict mechanisms (`docs/feature-plan.md` §7)

1. **Route loader** — glob `routes/features/*.php` from `routes/web.php`/`api.php`. Features
   add their own file; these two are never edited again.
2. **Seeder registry** — `DatabaseSeeder` calls `<Feature>Seeder` classes; append-only.
3. **Service providers** — features register their own (`AiServiceProvider` etc.).
4. **Navigation** — `config/navigation.php`; the layout renders it, features append to it.

### DTO types (`resources/js/anatomy/types.ts`)

`OrganDto`, `StructureDto`, `ViewerEvent`. Types only — F04 implements against them, F03
mirrors them in its API Resources. **Frozen after this merge** (`docs/engineering.md` §7).

### Error handling

Exception handler per `docs/architecture.md` §14: no provider name, status code, or stack
trace ever reaches a student. Log with a correlation id; return an educational-tone message.

### `/verify` pipeline

Wire the steps in `.claude/skills/verify/SKILL.md` so they run and report. Steps whose tooling
is absent report **skipped**, never passed.

## DB changes

`users` — `name`, `email`, `password`, `role` (student|admin), `education_level`,
`difficulty_preference`, `xp`, `level`, timestamps. Plus the framework tables.

## Frontend changes

Base Inertia layout, nav rendered from config, auth pages, empty dashboard, error pages
(403/404/419/500), loading states. Theme-aware and keyboard navigable from the start.

## Tests

| Test | Asserts |
|---|---|
| Auth feature | register, login, logout, session persistence |
| Role middleware | a student is refused an admin route |
| **FIT_SIZE parity** | `config('anatomy.fit_size')` === the TS constant. **Required** |
| Null implementations | `NullProvider` / `NullVectorStore` satisfy their interfaces |
| Route loader | a file dropped in `routes/features/` is picked up |
| Error handling | a thrown provider exception yields a safe message, not a trace |

## Acceptance criteria

1. `php artisan migrate:fresh` succeeds from empty.
2. A user registers, logs in, sees the dashboard, logs out.
3. `/verify` reports green with no domain code present.
4. `app/Contracts/**` and `types.ts` exist and are documented as frozen.
5. A second agent can add a route, a seeder, and a nav entry without editing a file you own.

## Constraints and guardrails

- `declare(strict_types=1);` on every PHP file. PHPStan level 6 clean.
- No `env()` outside `config/`. No secrets in any Inertia prop or `VITE_*` variable.
- Pin `three` and `gsap` to exact versions — a minor Three.js bump changes renderer behaviour.
- Do not add a Repository layer, a `Domain/` layer, or any abstraction in
  `docs/engineering.md` §12's forbidden list.
- Do not enable the Laravel Boost MCP plugin yet — add `laravel/boost --dev` here, enable the
  plugin once `artisan` exists.

## Definition of Done

`docs/engineering.md` §11, all ten items. Plus: a written one-page note in the PR describing
the four registries, because 13 features depend on understanding them.

## Commit boundary

One branch, several commits: `chore(platform): scaffold`, `feat(platform): auth and roles`,
`feat(platform): shared contracts and DTOs`, `feat(platform): route/seeder/provider/nav
registries`, `test(platform): harness and CI`. Merge as one reviewable unit.
