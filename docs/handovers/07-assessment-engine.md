# Handover 07 — Assessment Engine

**Feature:** F07 · **Lane:** Assessment · **Wave:** 4 · **Branch:** `feat/f07-assessment-engine`

> Read first: PRD §12 · `docs/architecture.md` §9, §5.4 · `docs/project-context.md` §2.4

## Objective

Build the quiz engine — standard MCQ and the flagship **3D spatial quiz**, where a student
answers by clicking an anatomical structure. Both feed one attempt pipeline so one mastery
score can consume them.

## Scope

- `questions`, `question_options`, `attempts` migrations, models, factories, seeder
- `AssessmentService`, `Api/V1/QuizController`, **answer-stripping API Resources**
- MCQ and spatial quiz UI, feedback and explanation display

## Out of scope

Missions (F09), mastery calculation (F10), AI-generated questions (F13's review queue), AI
short-answer grading beyond calling F08's service.

## Dependencies / prerequisites

**F04 merged** (`setMode('quiz')`, `structure:picked`, `flashStructure`) and **F05 merged**
(mounting pattern). F03 for structures.

## Existing code to reuse

The upstream `LabelQuiz` component's **interaction design** is genuinely good and should be
reproduced (`docs/project-context.md` §2.4):

- A miss flashes the picked structure red **and the correct one green** — otherwise the
  learner is told they were wrong but never shown the right answer.
- The feedback card is placed on the opposite half of the screen from the structure being
  revealed, so it never covers the dot it is pointing at.
- A wrong answer dwells longer than a correct one (2.4 s vs 1.2 s) because there is more to read.
- Fisher–Yates shuffle so each structure is asked once per round, in a fresh order.

Reproduce the UX. **Do not reproduce its state model** — upstream scoring is client-side and
ephemeral.

## Ownership boundaries

**You own** `questions`, `question_options`, `attempts`, `AssessmentService`, the quiz
controller/Resources/pages, `routes/features/quiz.php`, `QuestionSeeder`.

**You must not** create `missions`, `mission_attempts`, or `learning_mastery`. You dispatch
`RecalculateMastery`; F10 implements it (no-op until F10 lands).

## Required implementation

### The one rule that matters most

**The client never receives an answer key.** `is_correct`, `correct_structure_id`, and
`correct_option_id` are stripped by the API Resource. Validation is server-side, always.
`docs/architecture.md` §5.4 rule 2. A feature test asserts their absence in every payload —
this is non-negotiable and is checked by `/boundary-audit` and the architecture hook.

### Flow

```
student clicks a structure → viewer emits structure:picked { structureId }
→ POST /quizzes/{quiz}/attempt { question_id, selected_structure_id, time_spent_ms, hint_used }
→ AssessmentService validates → persists attempt → dispatches RecalculateMastery
← { is_correct, correct_structure_id, explanation, mastery_delta }
→ viewer.flashStructure(picked, correct); on a miss also flashStructure(correct, true)
```

MCQ attempts take the same route with `selected_option_id`. Short-answer attempts call F08's
tutor service against a rubric in `questions.metadata` and persist the verdict as a normal
attempt.

### API

```
GET  /api/v1/quizzes/{quiz}          questions, ANSWERS STRIPPED
POST /api/v1/quizzes/{quiz}/attempt  → validated result + explanation
```

## DB changes

`questions` (lesson_id nullable, organ_id, type mcq|spatial|short_answer, question,
difficulty, explanation, `correct_structure_id` nullable, metadata JSON, status
draft|review|published, `generated_by_ai`), `question_options`, `attempts`
(user_id, question_id nullable, `mission_attempt_id` nullable, selected_structure_id,
selected_option_id, answer_text, is_correct, time_spent_ms, hint_used;
index `(user_id, created_at)`).

`mission_attempt_id` is nullable and unused by you — F09 populates it. Including it now means
F09 does not alter your table.

**Land this migration in your first commit** — F09 and F10 both depend on it.

## Tests

- **Answer key absent from every payload** — required, one test per endpoint
- Spatial attempt: correct and incorrect, both persisted with timing
- MCQ attempt, both outcomes
- Attempts scoped to `auth()->id()` in the service
- `hint_used` and `time_spent_ms` recorded
- Only `status = 'published'` questions are served

## Acceptance criteria

1. A student answers a spatial question by clicking the model and gets validated feedback.
2. A miss reveals the correct structure in green.
3. No correctness field is reachable from the browser, verified by test.
4. Attempts persist with timing and hint usage.

## Constraints and guardrails

- Never grade client-side. Never send the key "just for the animation".
- The viewer holds no answer state — it receives `flashStructure` calls after the server responds.
- Questions with `status = 'review'` never reach a student.

## Definition of Done

`docs/engineering.md` §11, with special attention to item 6 (no answer key reachable).

## Commit boundary

`feat(assessment): schema and models` (**first**) → `feat(assessment): service and validation`
→ `feat(assessment): answer-stripping resources and API` → `feat(assessment): spatial quiz UI`
→ `feat(assessment): MCQ UI and feedback` → `test(assessment): coverage incl. answer-key absence`.
