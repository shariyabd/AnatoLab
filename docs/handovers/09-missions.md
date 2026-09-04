# Handover 09 — Missions

**Feature:** F09 · **Lane:** Assessment · **Wave:** 5 · **Branch:** `feat/f09-missions`

> Read first: PRD §13 · `docs/architecture.md` §9

## Objective

Mission-based learning: multi-step spatial challenges where a student traces a pathway or
arranges a process using the 3D model, validated as a sequence rather than a single answer.

## Scope

- `missions`, `mission_attempts` migrations, models, factories, seeder
- `MissionService` — sequence and pathway validation, per-step scoring
- Mission API, mission UI, viewer choreography

## Out of scope

Single-question grading (F07 — you reuse its `attempts` table via `mission_attempt_id`),
mastery calculation (F10), simulations (F12).

## Dependencies / prerequisites

**F07 merged** (the `attempts` table and its `mission_attempt_id` column) and **F04 merged**
(`setMode('mission')`, `focusStructure`, `flashStructure`).

## Existing code to reuse

None beyond F07's attempt pipeline and F04's viewer interface.

## Ownership boundaries

**You own** `missions`, `mission_attempts`, `MissionService`, mission
controllers/Resources/pages, `routes/features/missions.php`, `MissionSeeder`.

**You must not** alter F07's `questions`/`attempts` schema — `mission_attempt_id` is already
there for you. If you need another column, ask F07's owner.

## Required implementation

### Configuration

`missions.configuration` JSON:

```json
{
  "type": "trace_pathway",
  "steps": [
    { "structure_id": "left-atrium",  "prompt": "…", "hint": "…" },
    { "structure_id": "left-ventricle", "prompt": "…", "hint": "…" },
    { "structure_id": "aorta", "prompt": "…", "hint": "…" }
  ],
  "scoring": { "correct": 10, "after_hint": 6, "wrong": 0 }
}
```

### The rule that matters

**The client receives prompts and hints; never the target sequence.** The Resource strips
`structure_id` from every step. Validation is server-side, step by step. Same principle as
F07's answer key, same test requirement.

### Scoring and feedback

`MissionService` scores each step as correct / correct-after-hint / wrong and returns per-step
feedback so the UI can walk the student back through the pathway. Persist a
`mission_attempts` row plus one `attempts` row per step (with `mission_attempt_id` set), so
F10's mastery consumes mission work through the same pipeline as quizzes.

### API

```
GET  /api/v1/missions
GET  /api/v1/missions/{mission}          config with target sequence stripped
POST /api/v1/missions/{mission}/attempt  → { score, per_step, feedback }
```

### Content

Seed at least **"Trace the Blood"** (left atrium → left ventricle → aorta → body) — PRD §13
names it and F14's demo journey uses it. Add one more of a different type (identify or
compare) to prove the config generalises.

## Tests

- **Target sequence absent from every payload** — required
- Correct sequence scores full; out-of-order scores partially with correct per-step feedback
- Hint usage reduces the step score and is recorded
- One `attempts` row per step, each carrying `mission_attempt_id`
- Mission attempts scoped to `auth()->id()`

## Acceptance criteria

1. "Trace the Blood" runs end to end with per-step feedback.
2. An out-of-order attempt reports exactly which step went wrong.
3. Mission steps feed mastery through F07's attempts table.
4. No target sequence reachable from the browser, verified by test.

## Constraints and guardrails

- No client-side validation of order.
- Reuse F04's `focusStructure` and `flashStructure` — do not add viewer methods; request them
  from F04's owner if genuinely missing.

## Definition of Done

`docs/engineering.md` §11.

## Commit boundary

`feat(missions): schema and models` → `feat(missions): service and sequence validation` →
`feat(missions): API with sequence stripping` → `feat(missions): mission UI and choreography`
→ `feat(missions): seed trace-the-blood` → `test(missions): coverage`.
