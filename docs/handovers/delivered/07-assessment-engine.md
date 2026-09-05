# Handover 07 — Assessment Engine (delivered)

**Branch** `feat/f07-assessment-engine` · **Contract** [`07-assessment-engine.md`](../07-assessment-engine.md) · **Status** delivered

## What shipped

One attempt pipeline behind three question types, with correctness decided only in
`AssessmentService` and never serialised with a question. The spatial quiz answers by clicking
a structure on the model; MCQ and short answer go through the same route, the same table and
the same mastery dispatch.

- Quiz picker at `/quizzes` and a round at `/quizzes/{organ-slug}`
- Spatial questions: click (or keyboard-select) a structure, get a server verdict, see the pick
  flashed red and the right answer green
- MCQ with server-side grading; short answer graded against an authored rubric
- `attempts` rows carrying `time_spent_ms` and `hint_used`, dispatching `RecalculateMastery`
- Fisher–Yates round order, 1.2 s / 2.4 s dwell, feedback card kept off the revealed structure
- 18 seeded questions across the three MVP organs, one deliberately left `review`

## Public surface

### Routes (`routes/features/quiz.php`)

| Method | URI | Name | Controller |
|---|---|---|---|
| GET | `/quizzes` | `quiz.index` | `Web\QuizController@index` |
| GET | `/quizzes/{quiz}` | `quiz.show` | `Web\QuizController@show` |
| GET | `/api/v1/quizzes/{quiz}` | `api.v1.quiz.show` | `Api\V1\QuizController@show` |
| POST | `/api/v1/quizzes/{quiz}/attempt` | `api.v1.quiz.attempt` | `Api\V1\QuizController@attempt` |

All `auth`; the API group adds `throttle:api`. **`{quiz}` is an organ slug** — there is no
`quizzes` table.

### Services / DTOs

| Class | Responsibility |
|---|---|
| `Services\Assessment\AssessmentService` | Reads a quiz; grades, persists and dispatches. The only place correctness is decided |
| `Services\Assessment\Quiz` | One published organ + its published questions (derived, not stored) |
| `Services\Assessment\QuizSummary` | One picker card: organ + answerable question count |
| `Services\Assessment\AttemptData` | Validated attempt input — carries no correctness field of any kind |
| `Services\Assessment\AttemptResult` | The verdict, after persistence: `attemptId`, `isCorrect`, `correctStructureId`, `correctOptionId`, `explanation`, `masteryDelta` |
| `Services\Assessment\ShortAnswerGrader` | Deterministic grading against `metadata.rubric` (`accepted` any-of, `required` all-of) |
| `Enums\QuestionType` | `mcq` \| `spatial` \| `short_answer` — the three grading branches |
| `Enums\QuestionStatus` | `draft` \| `review` \| `published` |

### Schema

| Table | Key columns | Indexes / FKs |
|---|---|---|
| `questions` | `lesson_id` (nullable), `organ_id`, `type`, `question`, `difficulty`, `explanation`, `correct_structure_id`, `metadata` (JSON), `status`, `generated_by_ai` | **`lesson_id`: `unsignedBigInteger`, nullable, indexed, NO FK**; `organ_id` FK cascade; `correct_structure_id` FK → `anatomical_structures` `nullOnDelete`; indexes `(organ_id, status)`, `lesson_id`, `status` |
| `question_options` | `question_id`, `label`, `value`, `is_correct` | unique `(question_id, value)`; FK cascade. No `position` column |
| `attempts` | `user_id`, `question_id` (nullable), `mission_attempt_id` (nullable, **no FK**), `selected_structure_id`, `selected_option_id`, `answer_text`, `is_correct`, `time_spent_ms` (nullable), `hint_used` | indexes `(user_id, created_at)`, `(user_id, question_id)`, `mission_attempt_id`; selected-* FKs `nullOnDelete` |

`Attempt::$fillable` deliberately excludes `user_id` and `is_correct`.

### Client contract (`resources/js/types/quiz.ts`)

`QuizDto { slug, title, questionCount, organ: OrganDto, questions }`,
`QuizQuestion { id, type, question, difficulty, hint, options }`,
`QuizOption { id, label, value }`, `QuizCard` (organ summary + `questionCount`),
`AttemptVerdict { attemptId, isCorrect, correctStructureId, correctOptionId, explanation,
masteryDelta }`. `AttemptVerdict` is the only correctness-bearing type, and it is the response
to a recorded attempt.

## Key decisions

- **A quiz is derived from an organ, not stored.** `docs/architecture.md` §6 has no `quizzes`
  table, and inventing one would give 13 a second content type to administer for no gain.
- **`answerableQuestions()` filters spatial questions whose answer structure is unpublished**,
  because the organ payload ships published structures only — such a question has no reachable
  right answer and every student would get it wrong.
- **Ids are validated for shape, not with `exists`.** An option on another question and a structure
  on another organ both exist and are both still not answers; the service resolves each id
  *through the question* and discards what does not belong.
- **`AttemptResult` is the one payload that legitimately carries correctness**, and only after the
  attempt is written — probing for the answer costs a recorded wrong attempt in the student's own
  mastery record, which is what makes the reveal safe.
- **Resource key lists are exhaustive and written out**, never spread from the model, so a column
  added to `questions` or `question_options` later cannot ship to the browser by default.
- **`AttemptResultResource` uses camelCase** partly so the strings `is_correct` and
  `correct_structure_id` stay out of `resources/js/` entirely, where `/boundary-audit` greps for them.
- **Dwell is 1.2 s correct / 2.4 s incorrect and the order is a real Fisher–Yates shuffle**
  (`useQuiz.ts`) — a `sort(() => Math.random() - 0.5)` is biased and not even reliably a permutation.

## Invariants honoured

**Invariant 4 — the client never receives an answer key.** Proven by
`tests/Feature/Assessment/AnswerKeyAbsenceTest.php`, one test per payload, using the shared
`toCarryNoAnswerKey()` expectation in `tests/Pest.php` (which forbids the literal keys
`is_correct`, `isCorrect`, `correct_option_id`, `correctOptionId`, `correct_structure_id`,
`correctStructureId`, `correct_sequence`, `correctSequence`, `answer`):

- `GET /api/v1/quizzes/{quiz}` — no answer key
- `GET /quizzes/{quiz}` Inertia page props — no answer key (embedded in HTML; "view source" is
  the network tab)
- `GET /quizzes` Inertia page props — no answer key
- explanation absent until an attempt is recorded
- every question's key set is exactly `['id','type','question','difficulty','hint','options']`
- neither `rubric` nor `metadata` appears anywhere in the payload
- the reveal is unreachable without an `attempts` row being written

**Fields stripped.** `QuestionResource` omits `correct_structure_id`, `explanation`, `metadata`
(only the authored `metadata.hint` is lifted out by name), `status`, `lesson_id` and
`generated_by_ai`. `QuestionOptionResource` emits `id`, `label`, `value` — and not `is_correct`.

Also exercised:

- **1 — services never read the request.** `AttemptOwnershipTest` → *"grades and files an attempt
  with no authenticated user at all"* and *"files the attempt against the user it was handed, not
  the logged-in one"*.
- **6 — Eloquent only, `strict_types` throughout**; `DB::` appears only for `DB::transaction` in
  13's later admin methods.
- **7 — thin controllers.** `RecordAttemptRequest::toData()`/`::student()` are the only things the
  controller passes on; the service never sees a Request.
- **8 — templates display only.** All HTTP lives in `useQuiz.ts`, which never compares an id to an
  answer — `Pages/Quiz/Show.test.ts` → *"tells the viewer nothing about correctness before the
  server has ruled"*.

## Tests

| File | Proves |
|---|---|
| `tests/Feature/Assessment/AnswerKeyAbsenceTest.php` | Invariant 4, per endpoint and per page (see above) |
| `tests/Feature/Assessment/SpatialAttemptTest.php` (12 tests) | Correct pick + explanation; a miss still names the right structure; ownership from the authenticated student not a request field; *"cannot be told it was right"*; foreign-organ and unpublished structures discarded; `RecalculateMastery` queued not run inline; 404 for a question outside the quiz or in `review`; payload and timing validation; auth |
| `McqAttemptTest.php`, `ShortAnswerAttemptTest.php` | Both MCQ outcomes, the right option named on a miss, an option from another question discarded, an unanswered question wrong not errored; rubric matching ignoring case/punctuation, rejection outside the rubric, empty answer and missing rubric both wrong, `required` phrases enforced, length cap |
| `tests/Feature/Assessment/AttemptOwnershipTest.php` | Service works with no auth context at all; scoping; the mastery job is safe to run inline |
| `tests/Feature/Assessment/QuizEndpointTest.php` | Auth; organ + published questions; the organ arrives in the viewer's own shape; `review` questions never served; spatial question with unpublished answer omitted; options carry no distinguishing flag; hint served, rubric not; 404 for empty/draft/unknown |
| `QuizPageTest.php`, `AssessmentSeederTest.php` | Picker lists only answerable organs and counts only servable questions, empty state, unwrapped round props, one navigation entry that resolves; seeded questions for all three organs, every spatial answer a published structure on the same organ, exactly one correct MCQ option, varying correct position, at least one `review` row, idempotent |
| `resources/js/composables/useQuiz.test.ts` | Shuffle/order, answering round-trip, dwell timing, hint accounting, failure handling |
| `resources/js/Pages/Quiz/Show.test.ts` | Quiz-mode mount; nothing told to the viewer before the server rules; red pick + green answer on a miss; pick-only flash when right; the correct structure named in text as well as colour; spatial answering from the keyboard with no canvas; round summary; disposal |

## Known gaps / follow-ups

- **`RecalculateMastery` was created here as a no-op and is now implemented.** `App\Jobs\
  RecalculateMastery` exists with the dispatch signature this lane authored
  (`userId`, `attemptId`, queue `default`); **10** filled in `handle()` and its docblock records
  that no dispatch site changed to make it work. `AttemptResult::masteryDelta` is still always
  `null` — mastery is recomputed after the response is sent, so a real number is never available
  in the request. The UI renders nothing rather than a zero.
- **`questions.lesson_id` shipped exactly as instructed** — nullable, indexed, no foreign key
  (the D1 default for parallel branches) — and nothing in this lane populates it. Binding a
  lesson's `knowledge_check` step (**06**) to a question is still an open seam: the seeder writes
  no `lesson_id`, so no seeded question is attached to a lesson.
- **`attempts.mission_attempt_id`** shipped nullable and unconstrained as planned; **09** later
  populated it via `AssessmentService::recordMissionStep()`, added to this service afterwards.
- **Short-answer AI grading was not wired.** The contract said short answers call 08's tutor
  service against the rubric; `ShortAnswerGrader` grades deterministically instead and documents
  why — `AITutorService` exposes only `respond()`, has no grading entry point, and this lane may
  not edit the AI lane's service. The AI verdict is left as a documented one-method fallback seam.
- `AssessmentSeeder`'s docblock claims "one per organ is deliberately left in `review`", but only
  one question in the whole seeder is `review` (a heart MCQ). The test only asserts "at least one",
  so it passes; the comment overstates the data.
