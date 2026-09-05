# Handover 09 — Missions (delivered)

**Branch** `feat/f09-missions` · **Contract** [`09-missions.md`](../09-missions.md) · **Status** delivered

## What shipped

Multi-step spatial challenges validated as a sequence. A mission is one row whose
`configuration` JSON holds the ordered steps and the scoring table; the browser is sent the
prompts, the hints and the organ, and never the targets. A submission is graded step by step
in `MissionService`, written as one `mission_attempts` envelope plus one `attempts` row per
step, and returned as per-step feedback.

- Two mission types: `trace_pathway` (ordered) and `identify` (order-free, targets claimed
  left to right).
- Per-step outcomes — correct / correct-after-hint / wrong — with the authored scoring table,
  floored at zero.
- One target revealed per run, on the first missed step, so a run buys at most one answer.
- Mission steps reach Handover 10's mastery through Handover 07's `attempts` table, with no
  mission-specific branch anywhere in the mastery code.
- Two seeded missions: **Trace the Blood** (heart) and **Name the Airways** (lungs).
- Mission picker and run pages, plus a `missions` entry in `config/navigation.php` (order 50).

## Public surface

### Routes (`routes/features/missions.php`)

| Method | URI | Name | Handler |
|---|---|---|---|
| GET | `/api/v1/missions` | `api.v1.missions.index` | `Api\V1\MissionController@index` |
| GET | `/api/v1/missions/{mission}` | `api.v1.missions.show` | `Api\V1\MissionController@show` |
| POST | `/api/v1/missions/{mission}/attempt` | `api.v1.missions.attempt` | `Api\V1\MissionController@attempt` |
| GET | `/missions` | `missions.index` | `Web\MissionController@index` |
| GET | `/missions/{mission}` | `missions.show` | `Web\MissionController@show` |

API group is `auth` + `throttle:api`; pages are `auth`. `{mission}` is the mission slug,
constrained to `[a-z0-9-]+`.

### Services / DTOs

| Class | Responsibility |
|---|---|
| `Services\Assessment\MissionService` | Lists and resolves runnable missions; grades, persists and explains a run |
| `Services\Assessment\MissionConfiguration` | Parses `missions.configuration` into steps and a scoring table |
| `Services\Assessment\MissionDefinition` | A mission plus its parsed configuration and its resolved targets |
| `Services\Assessment\MissionStep` / `MissionStepSubmission` / `MissionAttemptData` | Authored step, one client pick, and the whole submission |
| `Services\Assessment\MissionStepOutcomeRecord` | Internal grading record — carries the target and the pick |
| `Services\Assessment\MissionResult` / `MissionStepResult` | The graded verdict the Resource renders |

### Schema

| Table | Key columns | Indexes / FKs |
|---|---|---|
| `missions` | `organ_id`, `slug` (unique), `title`, `description`, `type` (32), `difficulty` (tinyint, default 1), `configuration` json, `status` | FK `organ_id` restrict-on-delete; `(status, organ_id)` |
| `mission_attempts` | `user_id`, `mission_id`, `score` (unsigned), `completed` (bool), `duration_ms` (nullable), `result` json | FKs cascade; `(user_id, created_at)`, `(user_id, mission_id)` |

`attempts.mission_attempt_id` is Handover 07's column, still unconstrained; `MissionService`
is the only writer of either side. `missions.configuration` is an answer key and never leaves
the server; `mission_attempts.result` is the server-side record of what was expected and what
was picked, and is not the client payload.

### Client contract (`resources/js/types/missions.ts`)

`MissionDto { slug, title, description, type, typeLabel, ordered, difficulty, stepCount,
maxScore, scoring, organ: OrganDto, steps: MissionStep[] }` where
`MissionStep { index, prompt, hint }` — three keys, and none of them is the target.
`MissionOutcome { missionAttemptId, score, maxScore, completed, correctCount, firstMissedStep,
feedback, perStep: MissionStepOutcome[] }`. `OrganDto` is re-exported from `@/anatomy/types`
unchanged.

## Key decisions

- **`configuration` is a JSON column, not a `mission_steps` table** — "nothing queries, filters
  or joins a step … and the payload differs per mission type, which is the case a normalised
  table serves worst" (missions migration).
- **`type` is read from the column, never from the JSON** — "a value in both places is a value
  that can disagree with itself, and only the column can be indexed or filtered on"
  (`MissionConfiguration`).
- **The FK from `attempts` to `mission_attempts` was deliberately not added.** Handover 07's
  schema stays untouched; "`MissionService` is the only writer of either side".
- **One target revealed per run, on the first missed step.** "A mission grades a whole sequence
  in one request, so revealing every target would sell the sequence for a single throwaway run"
  (`MissionResultResource`).
- **A mission whose steps do not all resolve to published structures is not served at all** —
  five different failures (unknown slug, draft mission, draft organ, unparseable steps,
  unpublished target) all 404 without distinguishing themselves.
- **Feedback is templated from the outcomes, never generated** — "a sentence that summarises a
  score has to be right every time, and it is cheaper to write it than to validate it".
- **`compare` was not implemented.** The contract offered "identify or compare"; only branches
  the scorer can keep exist, because "a case with no branch would be a promise the scorer
  cannot keep" (`MissionType`).

## Invariants honoured

- **4 — the target sequence never reaches the client.**
  `tests/Feature/Missions/TargetSequenceAbsenceTest.php` is the proof, across both API
  endpoints and both page prop sets: "emits exactly three keys per step, and none of them is
  the target", "never emits the structure_id key the configuration is authored with", "omits
  the per-step explanation until a run has been recorded", "cannot be made to reveal a target
  without recording a run", "reveals at most one target per run, however badly the run goes".
  `MissionSeederTest` → "never names a target in a prompt or a hint" closes the content side.
- **1 — services read no ambient state.** `MissionService::score()` and `historyFor()` take the
  `User`; `MissionOwnershipTest` → "files the run against the authenticated student", "ignores
  a user id smuggled into the payload", "ignores a score or completion flag smuggled into the
  payload".
- **6 — Eloquent only.** The only `DB::` use is `DB::transaction()` around the envelope and its
  steps; `MissionAttemptPipelineTest` → "records the run and its steps together, or not at all".
- **7 — thin controllers.** FormRequest → service → Resource in both controllers;
  `MissionEndpointTest` → "rejects a submission that is not a list of steps" and "caps a step
  timer at an hour and a run at four".
- **8 — templates display.** Pages receive props from `Web\MissionController`; all HTTP is in
  `useMission.ts`.

## Tests

| File / dir | What it proves |
|---|---|
| `tests/Feature/Missions/TargetSequenceAbsenceTest.php` | Invariant 4 on every payload and page prop that carries a mission |
| `tests/Feature/Missions/MissionScoringTest.php` | Full and partial sequences, hint pricing, skipped steps, foreign-organ picks, order-free `identify` grading, one structure cannot claim two steps |
| `tests/Feature/Missions/MissionAttemptPipelineTest.php` | One `attempts` row per step carrying `mission_attempt_id`, one mastery recalculation queued per step, atomicity, quiz attempts undisturbed |
| `tests/Feature/Missions/MissionOwnershipTest.php` | Runs filed against the authenticated student; forged user/score fields ignored; history scoped |
| `tests/Feature/Missions/MissionEndpointTest.php` | Auth, the plain `OrganDto` payload, listing only runnable missions, the five 404 cases, submission validation |
| `tests/Feature/Missions/MissionPageTest.php` | Page props, empty state, guest redirect, and the navigation entry resolving |
| `tests/Feature/Missions/MissionSeederTest.php` | Trace the Blood is published and runnable end to end; a second type is seeded; idempotent |
| `resources/js/Pages/Missions/Show.test.ts`, `resources/js/composables/useMission.test.ts` | Run-page state machine and the composable's submission shape |

## Known gaps / follow-ups

- **Nothing emits `mission_started` or `mission_completed`.** Handover 10 defines both cases in
  `LearningEventType` and labels them in `ProgressService`, but no observer or service dispatch
  writes them, so mission activity is absent from the activity feed even though its graded
  steps do reach mastery.
- `MissionType::Compare` is not implemented; the second seeded mission is an `identify`.
- `mission_attempts.duration_ms` is client-reported and only capped, not corroborated against
  the per-step timers.
- The reveal rule means a student who repeats a mission can extract one target per run. That is
  the deliberate trade `MissionStepResult` documents, not an oversight, but it is the surface
  worth watching if repeat runs are ever made cheap.
