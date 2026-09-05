# Handover 12 — Simulations (delivered)

**Branch** `feat/f12-simulations` · **Contract** [`12-simulations.md`](../12-simulations.md) · **Status** delivered

## What shipped

"What happens if…" runs: a pure PHP state engine, a closed five-verb visual vocabulary that
drives the viewer from 04, and an AI explanation layer that captions a result it did not
produce. The engine is a *replay*, not a transition — `SimulationService` appends an action id
to the run's ordered log and replays the whole log through `SimulationEngine`, so the response
is a function of (configuration, ordered action ids) and nothing else.

- Pick a published simulation, run it against the same `OrganDto` Explore and the quiz mount
- Apply an action, see clamped readouts, fired thresholds, an outcome label and a 3D response
- Reset a run to its untouched starting state without losing the tutor thread
- Resume a run across page loads from the stored event log, prose included
- Two seeded, fully curated simulations: `mitral-valve-closure` (heart) and
  `airway-obstruction` (lungs) — neither reaches an AI provider on any step

## Public surface

### Routes (`routes/features/simulations.php`)

| Method | URI | Name | Controller |
|---|---|---|---|
| POST | `/api/v1/simulations/{simulation}/event` | `api.v1.simulations.event` | `Api\V1\SimulationController@event` |
| POST | `/api/v1/simulations/{simulation}/reset` | `api.v1.simulations.reset` | `Api\V1\SimulationController@reset` |
| GET | `/simulations` | `simulations.index` | `Web\SimulationController@index` |
| GET | `/simulations/{simulation}` | `simulations.show` | `Web\SimulationController@show` |

All four are inside `auth`. `{simulation}` is a slug constrained to `[a-z0-9-]+`. `event`
carries `throttle:ai` (it is the only path that can reach a provider); `reset` carries
`throttle:api`. `simulations.index` is registered in `config/navigation.php`.

### Services / DTOs (`app/Services/Simulation/`)

| Class | Responsibility |
|---|---|
| `SimulationEngine` | Pure replay: effects → clamp/round → evaluate thresholds → merge directives. Reads no DB, clock, request or random source |
| `SimulationService` | Persists what the engine decides; owns sessions, scoped by a passed-in `User` |
| `SimulationConfiguration` | Parses `simulations.configuration`; throws on malformed content rather than degrading |
| `SimulationAction` | One student action — a *delta*, not a destination |
| `SimulationVariable` | One readout: label, min, max, precision, unit |
| `SimulationThreshold` | One comparison, parsed by regex, evaluated by `match` |
| `VisualDirectives` | The closed vocabulary; subtractive parse, slug→id resolution, merge |
| `SimulationExplainer` | Curated prose first, tutor second, deterministic summary on provider failure |
| `SimulationStep` / `SimulationExplanation` | Value objects for one computed step and its prose |

### Schema

| Table | Key columns | Notable |
|---|---|---|
| `simulations` | `organ_id` (FK, `restrictOnDelete`), `slug` (unique), `title`, `description`, `premise` (not null), `configuration` (json), `status` | index `(status, organ_id)` |
| `simulation_sessions` | `user_id`, `simulation_id` (both cascade), `conversation_id` (nullable, `nullOnDelete`), `state` (json), `events` (json), `result` (json, nullable) | unique `(user_id, simulation_id)`; index `(user_id, updated_at)` |

`events` is the run; `state` and `result` are caches of replaying it. `conversation_id` is a
delta from PRD §22, added so one run threads its generated explanations into one tutor
conversation rather than one per step.

### Client contract (`resources/js/types/simulations.ts`)

`SimulationDto` (`id, slug, title, description, premise, organ: OrganDto, actions[], readouts[],
notice`), `SimulationStep` (`sequence, actionId, actionLabel, stateKey, label, isTerminal,
variables, affectedStructureIds, outcomes[], visualDirectives, explanation, explanationSource,
notice`), `SimulationCard`, `SimulationLogEntry` (snake_case — the stored column verbatim).
`VisualDirectives` and `SimulationState` are re-exported from `@/anatomy/simulation`, so the
object the API sends is handed to `applySimulationState()` with no transform.

## Key decisions

- **Replay, not transition.** "The API is a replay… `SimulationService` never asks for the next
  state given this one" — because a service mutating stored state could only satisfy the
  determinism criterion by discipline.
- **Clamp before evaluating, round inside the clamp.** A threshold fires on what the student can
  see, and rounding to the declared precision is what lets a run survive the JSON round-trip
  through `simulation_sessions.state` and come back *equal* rather than nearly equal.
- **The last threshold to fire names the state.** Configurations declare thresholds mildest
  first, so the run is called by the most severe thing currently true.
- **Five directives, enforced subtractively.** `highlight`, `tint`, `pulseRate`, `focus`,
  `crossSection` (authored snake_case, emitted camelCase). An unknown key is dropped, and so is
  a known key with a value the viewer could not use — a malformed colour, a non-numeric pulse
  rate, an axis that is not x/y/z. `pulseRate` clamps to 4.0; `crossSection.offset` clamps to
  `FIT_SIZE / 2`. Chosen because "a single-mesh model can actually perform them"; extending the
  set needs a plan change, not a commit.
- **Absent ≠ null.** `null` clears a directive, absence leaves it standing; directives accumulate
  across a run via `merge()` so the payload always describes the complete current view.
- **Structures are authored as slugs.** Resolved to opaque ids per action; an unresolvable slug is
  *dropped*, not nulled, so a content error cannot clear a highlight the student can still see.
- **The explainer could be deleted without changing a number.** Curated content is tried first
  (`explain_key` → outcome names → state key), sequence 0 never generates, and an
  `AIProviderException` falls back to a deterministic summary of the run's own numbers.

## Invariants honoured

- **1 — services take a typed `User`.** `SimulationService` reads no `request()`/`auth()`;
  `SimulationEventRequest::student()` is the seam. `SessionOwnershipTest` proves a run is filed
  against the logged-in student, not a user named in the payload.
- **4 applied to simulations — the mechanism never reaches the client.** No `effects`, no
  `thresholds`, no `explanations` in any Resource; `SimulationEndpointTest` ("never ships the
  effects, thresholds or curated prose to the browser") proves it.
- **5 — `FIT_SIZE` 3.8.** `crossSection.offset` is clamped to half of `config('anatomy.fit_size')`;
  `VisualDirectiveTest` ("clamps a cross-section plane to the normalised model extent").
- **6 — Eloquent only, `strict_types` everywhere.** No raw SQL in the lane.
- **7 — thin controllers.** Validate in `SimulationEventRequest`, delegate, return a Resource;
  the API controller has no branch beyond a 404.
- **8 — templates display.** `useSimulation.ts` holds all HTTP and computes no state;
  `useSimulation.test.ts` ("renders the server state verbatim rather than computing one").

## Tests

| File / dir | What it proves |
|---|---|
| `tests/Feature/Simulation/DeterminismTest.php` | **The determinism test.** Identical final state and identical step-by-step output for the same sequence; reproduces through the endpoint after a reset; survives the DB round-trip; never trusts stored `state`, only the stored action log |
| `tests/Feature/Simulation/ThresholdTest.php` | Boundaries do not fire, one step past does; every operator; floor/ceiling clamping; exact return to start when an action is undone; precision rounding; grammar and variable-existence rejection |
| `tests/Feature/Simulation/VisualDirectiveTest.php` | Only the five keys ever emitted; the set matches `VISUAL_DIRECTIVE_KEYS` in `resources/js/anatomy/simulation.ts`; slug→id resolution; drop-not-null; pulse and cross-section clamps; accumulation and null-clear |
| `tests/Feature/Simulation/EventLogTest.php` | One entry per action in order, no timestamps, prose carried forward, reset empties without a second row, resume across page loads |
| `tests/Feature/Simulation/ExplanationTest.php` | Curated reaches no provider; tutor only when nobody authored; the prompt forbids diagnosis; thread reuse; provider failure still computes the state; the notice is on every step |
| `tests/Feature/Simulation/SessionOwnershipTest.php` | Auth on every route, two students kept apart, one run per student per simulation, drafts 404 |
| `tests/Feature/Simulation/SimulationEndpointTest.php` | Payload shape, mechanism absent, `OrganDto` mountable unchanged, drafts hidden from the picker, `throttle:ai` on `event` |
| `tests/Feature/Simulation/SimulationSeederTest.php` | The demo story runs end to end and reproduces; every shipped config parses; every slug resolves against published structures; curated prose for baseline and every reachable outcome; idempotent |
| `resources/js/composables/useSimulation.test.ts` | Posts only an action id; derives nothing; in-flight guard; resume |
| `resources/js/Pages/Simulations/Show.test.ts` | Directives handed to the viewer unchanged and unmapped; runnable with no 3D at all; disposal on unmount |

## Known gaps / follow-ups

- The vocabulary stays at five verbs while the models remain single-mesh. If 02's licence gate
  closes on per-structure models, extending it is a plan change (see 02, 04).
- `SimulationSeeder` skips a definition whose organ slug is absent rather than failing; a missing
  organ therefore silently seeds one fewer simulation. `SimulationSeederTest` and 14's
  `DemoSeeder` are what catch that for the two shipped simulations.
- Threshold grammar is one comparison against a literal (`<var> <op> <number>`). Compound
  conditions are not expressible; a configuration needing one has to declare several thresholds.
- **No learning events are emitted.** 10 declares `LearningEventType::SimulationStarted` and
  `SimulationCompleted` and `ProgressService` labels them, but nothing in this lane records
  either, so a simulation run contributes nothing to the activity feed, streaks or mastery.
  Seam left for 10/14; the enum cases are currently unreachable.
- `simulation_sessions.result` is written but nothing outside this lane reads it.
