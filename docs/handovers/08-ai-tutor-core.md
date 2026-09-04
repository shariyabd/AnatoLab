# Handover 08 — AI Tutor Core

**Feature:** F08 · **Lane:** AI · **Wave:** 4 · **Branch:** `feat/f08-ai-tutor-core`

> Read first: PRD §9, §24, §39 · `docs/architecture.md` §8.1, §8.2, §8.4, §14

## Objective

A context-aware AI tutor that knows which organ and structure the student is looking at, and
answers at their level — inside a provider abstraction, with a validator between the model
and the student.

## Scope

- `conversations`, `conversation_messages` migrations, models
- `AITutorService`, `AIContextBuilder`, `PromptBuilder`, `AIResponseValidator`
- `app/Infrastructure/AI/**` — `AnthropicProvider` (default), `OpenAIProvider`, `NullProvider`
- `AiServiceProvider`, `config/ai.php`
- `/api/v1/ai/tutor/{ask,explain,hint}`, tutor panel UI, rate limiting

## Out of scope

RAG retrieval, embeddings, and the vector store — **all F11**. You call
`AIProviderInterface`; F11 later injects retrieved context through a seam you leave for it.
You also do not generate questions (F13) or compute any score (F10).

## Dependencies / prerequisites

**F01 merged** (`AIProviderInterface`, `AIResponse`, `LearningContext` DTOs) and **F03 merged**
(structure metadata for context). You do **not** need F11.

## Existing code to reuse

None — the upstream has no backend at all (`docs/project-context.md` §1.2).

## Ownership boundaries

**You own** `conversations`, `conversation_messages`, `app/Services/AI/**`,
`app/Infrastructure/AI/**`, `config/ai.php`, `routes/features/ai.php`, the tutor panel.

**You must not** implement `VectorStoreInterface` or any embedding storage — that is F11's.
Do not modify `app/Contracts/**` (frozen).

## Required implementation

### Flow (`docs/architecture.md` §8.2)

```
POST /api/v1/ai/tutor/ask
  → TutorController: FormRequest, authorize, rate limit
  → AITutorService
      → AIContextBuilder   user level · organ · structure (+ ta_term) · lesson objective
                           · difficulty · recent mistakes on this topic
      → [retrieval seam — F11 fills this]
      → PromptBuilder      system prompt + context + question
      → AIProviderInterface->chat()
      → AIResponseValidator
      → persist conversation + message
  ← { answer, sources[], follow_up_questions[], conversation_id }
```

Leave the retrieval step as an explicit, named seam so F11 is an insertion, not a rewrite.
`sources[]` ships as an empty array until F11 lands — build the UI for it now.

### Provider abstraction

Default `AnthropicProvider` (`claude-sonnet-5` is a sensible default for tutoring; make it a
config value, not a literal). `OpenAIProvider` proves the seam. `NullProvider` is what every
test uses. Bind from `config/ai.php` in `AiServiceProvider` — swapping is a `.env` change.

### Validator (`docs/architecture.md` §8.4)

Every response passes through before a student sees it: educational scope only; never claims
to be a doctor; refuses clinical diagnosis and redirects to educational framing; states
uncertainty rather than inventing; matches the student's education level; length ceiling
suited to ages 13–18. On failure return a safe fallback and log — **never raw model output,
never a provider error**.

### Grounding without RAG

Until F11, answers are grounded in the structure's curated metadata from F03. Build the
"no relevant sources — and saying so" path **now**; it is the honest behaviour and F11
inherits it.

### Rate limits

Per user: tutor 20/min and 200/day, hints 30/min. Provider timeout 20 s, one retry, then a
graceful message.

## DB changes

`conversations` (user_id, context_type, context_id, title), `conversation_messages`
(conversation_id, role, content, metadata JSON — metadata carries source ids for F11).

## Tests

- **No test touches a real API.** `NullProvider` everywhere; a network call in a test is a defect.
- Context builder assembles organ + structure + level + recent mistakes correctly
- Validator: rejects clinical-diagnosis output, enforces the length ceiling, returns the fallback
- Provider failure yields a safe student-facing message with no provider name or status code
- Rate limit returns 429
- Conversations scoped to `auth()->id()` in the service

## Acceptance criteria

1. A student selects the left ventricle, asks "why is its wall thicker", and gets a
   level-appropriate answer that demonstrably used the selected structure as context.
2. Swapping `AI_PROVIDER` in `.env` changes provider with no code change.
3. A provider outage degrades gracefully — the 3D interaction keeps working.
4. Conversation history persists.

## Constraints and guardrails

- Services never call `auth()` — the `User` is an argument.
- Depend on `App\Contracts`, never on `App\Infrastructure` concretes.
- No API key in an Inertia prop, a `VITE_*` variable, or any log line.
- Never log a prompt containing student data.
- The AI never computes a score or decides correctness — that is F07 and F10.

## Definition of Done

`docs/engineering.md` §11. Plus: document the retrieval seam in the PR so F11 knows exactly
where to insert.

## Commit boundary

`feat(ai): provider abstraction and bindings` → `feat(ai): conversation schema` →
`feat(ai): context builder and prompts` → `feat(ai): response validator` →
`feat(ai): tutor API and rate limits` → `feat(ai): tutor panel UI` → `test(ai): coverage`.
