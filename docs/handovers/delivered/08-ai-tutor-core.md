# Handover 08 — AI Tutor Core (delivered)

**Branch** `feat/f08-ai-tutor-core` · **Contract** [`08-ai-tutor-core.md`](../08-ai-tutor-core.md) · **Status** delivered

## What shipped

A context-aware tutor behind a provider abstraction. A question arrives with whatever the
student has selected; the service resolves the organ and structure server-side, builds a
`LearningContext`, calls the retrieval seam, prompts the provider, runs the reply past a
validator, and persists both turns. `AI_PROVIDER` decides which provider class is
constructed; nothing above `App\Infrastructure\AI` names one.

- Ask, explain and hint endpoints, each rate limited per minute and per day.
- Conversation threads that persist and can be read back, scoped to their owner.
- A validator that replaces clinical-advice output with a fixed educational fallback and
  trims answers to a word ceiling at a sentence boundary.
- A `sourceNote` the application writes itself when an answer had no cited sources.
- A tutor panel component, mounted on `/tutor` and (by Handover 05) inside Explore.
- A named, single-method retrieval seam, documented in
  [`08-retrieval-seam.md`](../08-retrieval-seam.md) and filled by Handover 11.

## Public surface

### Routes (`routes/features/ai.php`)

| Method | URI | Name | Handler | Limiters |
|---|---|---|---|---|
| POST | `/api/v1/ai/tutor/ask` | `api.v1.ai.tutor.ask` | `Api\V1\TutorController@ask` | `ai-tutor`, `ai-tutor-daily` |
| POST | `/api/v1/ai/tutor/explain` | `api.v1.ai.tutor.explain` | `Api\V1\TutorController@explain` | `ai-tutor`, `ai-tutor-daily` |
| POST | `/api/v1/ai/tutor/hint` | `api.v1.ai.tutor.hint` | `Api\V1\TutorController@hint` | `ai-hint`, `ai-tutor-daily` |
| GET | `/api/v1/ai/conversations` | `api.v1.ai.conversations.index` | `Api\V1\ConversationController@index` | `api` |
| GET | `/api/v1/ai/conversations/{conversation}` | `api.v1.ai.conversations.show` | `Api\V1\ConversationController@show` | `api` |
| GET | `/tutor` | `tutor.index` | `Web\TutorPanelController` | — |

All are `auth`. Limiter budgets: `ai-tutor` 20/min, `ai-tutor-daily` 200/day, `ai-hint` 30/min,
registered in `AiServiceProvider::boot()` and bucketed by user id.

### Services / DTOs

| Class | Responsibility |
|---|---|
| `Services\AI\AITutorService` | The pipeline: context → seam → prompt → provider → validator → persist |
| `Services\AI\AIContextBuilder` | Assembles `LearningContext` and the curated notes an answer is grounded in |
| `Services\AI\PromptBuilder` | System prompt, context block, and parsing the follow-up block back out |
| `Services\AI\AIResponseValidator` | Clinical-framing, length, emptiness and truncation backstop |
| `Services\AI\ConversationHistory` | Reads a student's own threads; every method takes the `User` |
| `Services\AI\TutorRequestData` / `TutorReply` / `ValidatedAnswer` | Request, reply and validation outcome DTOs |
| `Infrastructure\AI\AnthropicProvider` / `OpenAIProvider` | HTTP adapters behind `AIProviderInterface` |

### Schema

| Table | Key columns | Indexes / FKs |
|---|---|---|
| `conversations` | `user_id`, `context_type` (32, default `general`), `context_id` (nullable), `title` | FK `user_id` cascade; `(user_id, updated_at)`, `(user_id, context_type, context_id)` |
| `conversation_messages` | `conversation_id`, `role` (16), `content` text, `metadata` json, `created_at` | FK `conversation_id` cascade; `(conversation_id, id)` |

`conversation_messages.metadata` holds `model`, `validator_replaced`, `validator_trimmed`,
`violation`, `source_ids`, `follow_up_questions`. It is never serialised to the client.

### Client contract (`resources/js/types/tutor.ts`)

`TutorReply { answer, followUpQuestions[], sources: TutorSource[], sourceNote|null, conversationId }`,
`TutorSource { id, title, excerpt }`, `TutorConversation`, `TutorMessage`, `TutorSubject`, `TutorTurn`.
`useTutor.ts` is the only place that issues HTTP for the panel.

### Config keys

`config/ai.php` gains `limits.tutor_per_minute|tutor_per_day|hint_per_minute`, `transport.*`
(20 s timeout, 1 retry, 400 ms delay) and `tutor.*` (`history_turns` 6, `max_tokens` 700,
`temperature` 0.3). `bootstrap/providers.php` appends `AiServiceProvider` after
`AppServiceProvider`; `.env.example` gains an append-only AI block. Handover 11 appends to all
three files after these lines — order is load order, and a later `bind()` wins.

## Key decisions

- **The provider is resolved from config and an unknown name throws** — "an installation that
  thinks it has a tutor and is quietly serving placeholder text is the worse failure"
  (`AiServiceProvider::makeProvider`).
- **No `try/catch` in the controller or the service.** `AIProviderException` is logged against a
  correlation id and rendered by `bootstrap/app.php`; a second error path "would be the thing
  that eventually leaks one" (`AITutorService::respond`).
- **The conversation is resolved, not created, before the provider call**, so a failed call
  "leaves no empty titled conversation behind".
- **`sourceNote` is generated by the application, not asked of the model**, so "the tutor says
  when it had no sources" is a tested server behaviour rather than a hope about
  instruction-following.
- **The lesson title is resolved server-side or not at all** — "unverified client text going
  straight into a prompt is the cheapest injection surface a tutor has"
  (`TutorRequestData`).
- **`AnthropicProvider::generateEmbeddings()` throws rather than returning zeros**, which would
  "silently rank every chunk identically and look like a retrieval-quality problem".

## Invariants honoured

- **1 — services read no ambient state.** `AITutorService`, `AIContextBuilder` and
  `ConversationHistory` take the `User` as an argument; ownership of a thread is checked
  against it, proved by `TutorEndpointTest` → "starts a new thread rather than writing into
  someone else's" and `ConversationHistoryTest` → "404s on another student's thread rather than
  saying it exists".
- **2 — depend on `App\Contracts`.** `ProviderAbstractionTest` → "resolves the provider named by
  config, and nothing else" / "refuses to start on an unknown provider name".
- **4 — no answer key to the client.** `TutorEndpointTest` → "carries no correctness field in any
  payload" and "does not expose message metadata to the student"; `AIContextBuilderTest` →
  "carries no model, id, or credential into the context".
- **7 — thin controllers.** Three-line actions, FormRequest in, Resource out;
  `TutorEndpointTest` → "validates the question".
- **8 — templates do not query.** All HTTP lives in `useTutor.ts` / `useTutorSubject.ts`;
  `TutorPanelPageTest` → "renders the panel with the published organ list" and "puts no
  credential into an Inertia prop".

## Tests

| File / dir | What it proves |
|---|---|
| `tests/Feature/AI/ProviderAbstractionTest.php` | Config selects the provider; unknown names fail at boot; both adapters normalise their own payload shapes |
| `tests/Feature/AI/ProviderFailureTest.php` | Outage yields an educational message with no provider name or status, leaves no half-written thread, and does not break the rest of the app |
| `tests/Feature/AI/AIResponseValidatorTest.php` | Clinical persona / diagnosis / treatment replaced; teaching about disease left alone; length ceiling at a sentence boundary; empty and truncated handled |
| `tests/Feature/AI/PromptBuilderTest.php` | §39 context block, §8.4 safety rules, reading-level guidance, follow-up split and cap |
| `tests/Feature/AI/AIContextBuilderTest.php` | Context assembly and curated notes; nothing secret enters the context |
| `tests/Feature/AI/GroundingTest.php` | The "no indexed source matched" note is stated by the server, and the stored turn's `source_ids` is the slot Handover 11 fills |
| `tests/Feature/AI/TutorEndpointTest.php` | The three endpoints, selection resolution, persistence, thread ownership, payload absences |
| `tests/Feature/AI/ConversationHistoryTest.php` | Threads scoped to their owner; 404 does not confirm existence |
| `tests/Feature/AI/TutorRateLimitTest.php` | 20/min, 200/day, 30/min hints, bucketed by user not address |
| `tests/Feature/AI/TutorPanelPageTest.php` | Page renders with published organs; no credential or provider name in any payload |
| `tests/Support/RecordingProvider.php` | In-memory provider that records the prompts it was given and fails on demand; no test makes a network call |

## Known gaps / follow-ups

- **`AIContextBuilder::recentMistakesFor()` and `masteryGapsFor()` still return `[]`.** They are
  documented seams for Handover 07 (`attempts`) and Handover 10 (`learning_mastery`), both of
  which have since landed. The queries are written out in the docblock but were never wired,
  so the prompt still degrades to "no known misconceptions" for every student.
- **`lessonTitle` is passed as `null`** from `AITutorService::respond()`. Handover 06's
  `lesson_progress` exists; resolving the student's most recent in-progress lesson is still
  outstanding, and PRD §39 asks for it in the prompt.
- The `/tutor` route was a stopgap while Handover 05 built Explore. Explore now mounts
  `TutorPanel` directly, so the standalone page is redundant and could be retired.
- The retrieval seam is filled — see [11-rag-knowledge-base.md](11-rag-knowledge-base.md).
