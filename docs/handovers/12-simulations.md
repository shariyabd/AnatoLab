# Handover 12 — Simulations

**Feature:** F12 · **Lane:** Simulation · **Wave:** 6 · **Branch:** `feat/f12-simulations`

> Read first: PRD §14 · `docs/architecture.md` §12, §5.3

## Objective

"What happens if…" educational simulations: a deterministic state engine whose results drive
the 3D view and are then *explained* by the AI. Deterministic logic, AI narration — never the
reverse.

## Scope

- `simulations`, `simulation_sessions` migrations, models, seeder
- `SimulationService` — state transitions, threshold evaluation, event log
- The `visual_directives` vocabulary and its viewer wiring
- Simulation UI

## Out of scope

Viewer internals (F04 — you call `applySimulationState`), tutor internals (F08 — you call it),
mastery (F10).

## Dependencies / prerequisites

**F04** (`applySimulationState`), **F05** (mounting pattern), **F08** (explanations).

## Existing code to reuse

None. The upstream's "animation" feature is a static image behind a play badge, and the models
carry **zero animation clips** (`docs/project-context.md` §2.2, §2.5).

## Ownership boundaries

**You own** the two tables, `SimulationService`, simulation controllers/Resources/pages,
`routes/features/simulations.php`, `SimulationSeeder`.

**You must not** add viewer methods — request them from F04's owner. Do not modify
`AITutorService`.

## Required implementation

### Deterministic engine

`simulations.configuration` JSON, per `docs/architecture.md` §12:

```json
{
  "initial_state": { "valve_closure": 1.0, "output": 1.0, "oxygenation": 0.98 },
  "actions": [
    { "id": "impair_mitral", "label": "Mitral valve does not close fully",
      "effects": { "valve_closure": -0.4, "output": -0.25 },
      "visual": { "highlight": "mitral", "tint": "#d1584f", "pulse_rate": 1.35 } }
  ],
  "thresholds": [
    { "when": "output < 0.8", "outcome": "reduced_systemic_flow",
      "explain_key": "sim.heart.reduced_flow" }
  ]
}
```

A JSON config and a `match` expression. **No state machine library** (`docs/engineering.md` §12).

```
POST /api/v1/simulations/{simulation}/event
  → apply one action → clamp state → evaluate thresholds → append to event log
  ← { state, visual_directives, explanation }
```

### The visual vocabulary is closed — and deliberately so

`highlight` · `tint` · `pulse_rate` · `focus` · `cross_section`.

These five were chosen because **a single-mesh model can actually perform them**. You cannot
animate a valve, hide a chamber, or deform geometry — there is no per-structure geometry to
address. Do not add a directive the viewer cannot honour; if F02 lands per-structure models,
propose an extension then.

### AI explains, never decides

The state transition is computed in PHP and is reproducible. The explanation comes from
curated content where available and from F08's tutor otherwise. Always labelled as
educational; **never diagnostic** (PRD §14, §24).

### Content

Seed at least the heart valve simulation used in PRD §2.3's demo story.

## Tests

- Same action sequence → identical final state, every time (determinism)
- Thresholds fire at the right boundaries; state clamps at its limits
- Event log records every action in order
- `visual_directives` only ever contains the five permitted keys
- Sessions scoped to `auth()->id()`
- Explanation generation is mocked — no real API call

## Acceptance criteria

1. "What happens if the mitral valve does not close" runs, changes the 3D view, and is explained.
2. Replaying the same actions reproduces the same state exactly.
3. Output is framed as educational, never as a diagnosis.
4. Every directive is honoured by the viewer — nothing silently ignored.

## Constraints and guardrails

- Deterministic where possible; the LLM never drives state.
- No medical claims, no diagnosis, no treatment suggestions.
- Do not exceed the closed directive vocabulary.

## Definition of Done

`docs/engineering.md` §11.

## Commit boundary

`feat(simulation): schema and config format` → `feat(simulation): deterministic state engine`
(with determinism tests) → `feat(simulation): visual directives and viewer wiring` →
`feat(simulation): AI explanation integration` → `feat(simulation): UI and seed` →
`test(simulation): coverage`.
