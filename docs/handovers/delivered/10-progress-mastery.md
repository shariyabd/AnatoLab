# Handover 10 — Progress, Mastery & Gamification (delivered)

**Branch** `feat/f10-progress-mastery` · **Contract** [`10-progress-mastery.md`](../10-progress-mastery.md) · **Status** delivered

## What shipped

Attempts and lesson completions become mastery rows, a dashboard, a recommendation and
lightweight gamification. `MasteryCalculator` holds the formula and nothing else — no
database, no clock, no ambient user — and `MasteryService` is the part that reads `attempts`,
groups them by topic and writes `learning_mastery`. The body of `RecalculateMastery`, which
Handover 07 shipped as an empty queued job and Handover 09 dispatches for mission steps, is
implemented here; neither dispatch site changed.

- Mastery at three levels — structure, organ, body system — rolled up unweighted by attempts.
- A queued recalculation chained to a queued badge evaluation; no request path computes a score.
- Recommendations: weakest published topic with content left, tie-broken toward the organ on
  screen, with a templated reason naming the weakest factor.
- Append-only `learning_events`: client events batch to `POST /api/v1/events`; server-side
  events come from observers on `Attempt` and `LessonProgress`.
- XP, levels, streaks and six seeded badges, all recomputed rather than incremented.

## Public surface

### Routes (`routes/features/progress.php`, plus `/dashboard` in `routes/web.php`)

| Method | URI | Name | Handler |
|---|---|---|---|
| GET | `/api/v1/progress` | `api.v1.progress.index` | `Api\V1\ProgressController@index` |
| GET | `/api/v1/progress/systems` | `api.v1.progress.systems` | `Api\V1\ProgressController@systems` |
| GET | `/api/v1/progress/recommendations` | `api.v1.progress.recommendations` | `Api\V1\ProgressController@recommendations` |
| POST | `/api/v1/events` | `api.v1.events.store` | `Api\V1\LearningEventController@store` |
| GET | `/dashboard` | `dashboard` | `Web\DashboardController` |

Progress reads are `auth` + `throttle:api`; the events endpoint has its own
`learning-events` limiter — 30/min, keyed by user — registered in `ProgressServiceProvider`.
`recommendations` answers `{"data": null}` rather than 404 when there is nothing to do.

### Services / DTOs

| Class | Responsibility |
|---|---|
| `Services\Progress\MasteryCalculator` | The formula and the roll-up. Pure — `MasteryInput` carries even the current time |
| `Services\Progress\MasteryService` | Reads `attempts`, attributes each to a topic, writes `learning_mastery` |
| `Services\Progress\ProgressService` | Assembles the dashboard from stored rows; computes nothing |
| `Services\Progress\RecommendationService` | Picks the next activity and templates the reason |
| `Services\Progress\GamificationService` | XP, level, streak, badge evaluation — all recomputed |
| `Services\Progress\AnalyticsService` | The single seam every `learning_events` write passes through |
| `Jobs\RecalculateMastery` → `Jobs\AwardAchievements` | Queued recalculation, chained badge evaluation |
| `Jobs\RecordLearningEvents` | One insert per event batch, off the critical path |
| `Observers\AttemptObserver`, `Observers\LessonProgressObserver` | Record events where they happen without editing Handover 06's or 07's files |

### Schema

| Table | Key columns | Indexes / FKs |
|---|---|---|
| `learning_mastery` | `user_id`, `topic_type` (16), `topic_id`, `mastery_score` decimal(5,2), `attempts`, `correct_attempts`, `hinted_attempts`, `covered_structures`, `total_structures`, `last_activity_at` | unique `(user_id, topic_type, topic_id)`; index `(user_id, topic_type, mastery_score)` |
| `learning_events` | `user_id`, `event_type` (48), `context_type` (32, nullable), `context_id`, `payload` json, `occurred_at`, `created_at` | `(user_id, occurred_at)`, `(event_type, occurred_at)`, `(context_type, context_id)` |
| `achievements` | `slug` (unique), `name`, `description`, `icon`, `criteria` json | — |
| `user_achievements` | `user_id`, `achievement_id`, `earned_at` | unique `(user_id, achievement_id)`; index `(user_id, earned_at)` |

### Client contract (`resources/js/types/progress.ts`)

`ProgressDto { overallScore, systems: SystemMasteryDto[], strongest, needsPractice,
lessonsCompleted, quiz { attempts, correctAttempts, accuracyPercent }, gamification,
recentActivity: ActivityEntryDto[], recommendation }`.
`RecommendationDto` carries `reason` and `weakestFactor`; `GamificationDto` carries `xp`,
`level`, `xpIntoLevel`, `xpForLevel`, `streakDays` and `achievements`. `ClientEvent` is limited
to four client-reportable types: `organ_viewed`, `structure_selected`, `structure_isolated`,
`layer_changed`; `useLearningEvents.ts` batches 50 at a time on a 15 s interval and on unload.

## The formula, as implemented

`MasteryCalculator::calculate()` produces `100 × accuracy × recency × hintPenalty ×
(0.5 + 0.5 × coverage)`, rounded to two decimals and clamped to 0–100:

| Factor | Implementation | Constant |
|---|---|---|
| accuracy | `(correct + 1) / (attempts + 2)`, Laplace-smoothed | — |
| recency | `2 ^ (−days / 14)` on `last_activity_at`; a future timestamp clamps to 1.0 | `HALF_LIFE_DAYS = 14.0` |
| hint penalty | `1 − 0.15 × (hinted / attempts)`, so 0.85–1.0 | `HINT_PENALTY_WEIGHT = 0.15` |
| coverage | distinct structures attempted / structures in topic | `COVERAGE_FLOOR = 0.5` |

Two boundary conditions are decided in the class and documented there: **zero attempts scores
zero** (the formula is undefined at zero and an untouched topic must not already sit at the
smoothed prior of 25), and **a topic with no structures counts as fully covered** (otherwise an
organ quizzed only by multiple choice is capped at half marks forever). `rollUp()` averages the
three per-attempt factors across children unweighted and applies the parent's own coverage.

## Key decisions

- **`MasteryCalculator` is pure.** "No database, no cache, no clock, no `auth()` — `MasteryInput`
  carries even the current time … it is why the calculator can be exercised without a single
  fixture row."
- **`recalculate()` rebuilds every topic for one student, not just the organ just answered** —
  recency decays with the calendar, so a partial rebuild "would leave the rest of the dashboard
  reporting scores computed against an older `now`". It also makes the table self-healing.
- **One attribution rule with no branch for where an attempt came from**: the question's
  `correct_structure_id`, else `selected_structure_id`, else the question's organ. Mission steps
  take the second branch, and the mastery code never mentions missions.
- **XP is recomputed from the ledger, never incremented** — a retried or double-dispatched job
  "cannot inflate a score"; badges rely on `UNIQUE(user_id, achievement_id)` rather than a
  check-then-insert.
- **The observers are attached in `ProgressServiceProvider`, not by `#[ObservedBy]`**, because
  `Attempt` and `LessonProgress` belong to other lanes. `LessonProgressObserver` handles
  `created`/`updated` rather than `saved`, or "lessons started" would count scrolling.
- **`AwardAchievements` is chained after `RecalculateMastery`, not dispatched beside it** — a
  badge like "Respiratory Specialist" is a threshold on the score the first job just wrote.
- **Recommendation reasons are templated from `MasteryFactor`**, so "adding a term to the
  formula does not compile until somebody has written the sentence for it".

## Invariants honoured

- **Mastery is never computed synchronously in a web request.**
  `tests/Feature/Progress/RecalculationTest.php` → "queues the recalculation rather than
  running it in the request", "is a queued job on the default queue", and — the direct proof of
  acceptance criterion 5 — "never computes mastery on a dashboard read".
  `ProgressEndpointTest` → "reads the stored score rather than recomputing one" says the same
  from the read side.
- **1 — services read no ambient state.** Every progress service takes the `User` and a
  `CarbonImmutable`; `ProgressEndpointTest` → "returns the authenticated student progress and
  never another one", `GamificationTest` → "scopes badges and XP to one student".
- **4 — no answer key to the client.** `DashboardPageTest` → "carries no answer key into the
  page", `ProgressEndpointTest` → "carries no answer key", `LearningEventsTest` → "does not name
  the outcome with the answer key field names" (the payload says `outcome: correct|incorrect`,
  never a field named after the column).
- **6 — Eloquent only**, and `declare(strict_types=1)` throughout.
- **7 — thin controllers.** `ProgressController` and `LearningEventController` validate through
  a FormRequest and return a Resource; `LearningEventsTest` → "rejects an event the server
  records for itself" and "rejects a replayed batch of stale events".

## Tests

| File / dir | What it proves |
|---|---|
| `tests/Unit/Progress/MasteryCalculatorTest.php` | The formula table-driven: each factor, clamping, future timestamps, decay that never reaches zero, unweighted roll-up, parent coverage, factor sentences |
| `tests/Feature/Progress/MasteryRollupTest.php` | A row at every level; attribution to the structure tested rather than the one picked; one drilled structure cannot carry an organ; idempotence; scoping |
| `tests/Feature/Progress/RecalculationTest.php` | Recalculation is queued and only queued; the chain to badges; deleted users and deleted attempts |
| `tests/Feature/Progress/ProgressEndpointTest.php` | Summary shape, untouched systems included, stored-score reads, activity descriptions, current-organ hint validation |
| `tests/Feature/Progress/RecommendationTest.php` | Weakest-topic selection, exhausted content skipped, tie-break toward the current organ, lesson-before-quiz, templated reasons |
| `tests/Feature/Progress/GamificationTest.php` | XP curve and recomputation, all five badge criteria, streak edges, no double awards, unknown criteria ignored |
| `tests/Feature/Progress/LearningEventsTest.php` | Batch acceptance and queueing, client-reportable types only, stale and future events rejected, rate limiting, append-only log, server-side events |
| `tests/Feature/Progress/DashboardPageTest.php`, `AchievementSeederTest.php` | Dashboard props and empty state; the badge catalogue matches the evaluator's branches |

## Known gaps / follow-ups

- **Five `LearningEventType` cases are defined and labelled but never written**:
  `mission_started`, `mission_completed`, `ai_question_asked`, `simulation_started`,
  `simulation_completed`. `ProgressService` has activity labels for all of them and nothing
  dispatches any — see [09-missions.md](09-missions.md).
- Nothing feeds `AIContextBuilder::masteryGapsFor()` — Handover 08 left the seam and it is still
  returning `[]`, so the tutor cannot yet mention a student's weak systems.
