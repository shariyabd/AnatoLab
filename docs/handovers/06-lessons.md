# Handover 06 — Lessons & Content Delivery

**Feature:** F06 · **Lane:** Learning · **Wave:** 4 · **Branch:** `feat/f06-lessons`

> Read first: PRD §8 · `docs/architecture.md` §6, §11

## Objective

Deliver structured lessons around organs and body systems, tracking completion per student.

## Scope

- `lessons`, `lesson_progress` migrations, models, factories, seeder
- `LessonService`, lesson API, lesson Inertia pages
- Step sequencing and completion

## Out of scope

Questions and grading (F07), mastery calculation (F10), AI explanations (F08). A lesson may
*contain* a knowledge check — F07 owns the question; you own its placement in the sequence.

## Dependencies / prerequisites

**F05 merged** (you embed the viewer using its mounting pattern). F03 for organ/structure
references.

## Existing code to reuse

The upstream organ prose migrated in F03 is your content source. Nothing else.

## Ownership boundaries

**You own** `lessons`, `lesson_progress`, `LessonService`, lesson controllers/Resources/pages,
`routes/features/lessons.php`, `LessonSeeder`.

**You must not** create question, attempt, or mastery tables. You emit `lesson_progress` rows;
F10 reads them.

## Required implementation

Lesson content is a JSON `content` column holding an ordered step list per PRD §8:
objective → 3D exploration → guided explanation → interactive activity → knowledge check →
reflection. Each step names its type and its payload; the page renders the matching component.

```
GET  /api/v1/lessons                    filterable by organ, system, difficulty
GET  /api/v1/lessons/{lesson}
POST /api/v1/lessons/{lesson}/complete
```

`lesson_progress` is unique on `(user_id, lesson_id)` and carries `status`,
`progress_percent`, `completed_at`.

**Content:** 5–10 lessons across the 3 MVP organs. At least one must be the demo lesson —
blood circulation through the heart (PRD §8) — since F14's journey uses it.

## DB changes

`lessons` (organ_id FK, title, slug, description, objective, difficulty, estimated_minutes,
content JSON, status) and `lesson_progress`. Cache `lesson:{slug}` for 1 h.

## Tests

- Feature: list, show, complete; authorization (a student cannot complete another's lesson)
- Progress is scoped to `auth()->id()` in the service, never from a request parameter
- Completing twice is idempotent
- Seeder produces the blood-circulation lesson F14 depends on

## Acceptance criteria

1. A student opens a lesson, moves through its steps, and completes it.
2. Progress persists and survives a refresh.
3. The 3D exploration step embeds the viewer via F05's pattern.
4. 5–10 lessons seeded, including blood circulation.

## Constraints and guardrails

- No logic in the template — step rendering is data-driven.
- `LessonService` takes the `User` as an argument; it never calls `auth()`.
- Do not grade anything. If a step needs a graded answer, it is F07's question.

## Definition of Done

`docs/engineering.md` §11.

## Commit boundary

`feat(lessons): schema and models` → `feat(lessons): service and API` →
`feat(lessons): lesson pages and step renderer` → `feat(lessons): seed MVP lessons` →
`test(lessons): coverage`.
