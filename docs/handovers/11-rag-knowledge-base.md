# Handover 11 — RAG & Knowledge Base

**Feature:** F11 · **Lane:** AI · **Wave:** 5 · **Branch:** `feat/f11-rag-knowledge-base`

> Read first: PRD §10, §11, §28 · `docs/architecture.md` §8.1, §8.3, §11

## Objective

Ground the tutor's answers in curated educational sources, with citations — behind a vector
store abstraction whose default implementation is MySQL.

## Scope

- `knowledge_documents`, `knowledge_chunks` migrations, models
- `KnowledgeService`, `ChunkingService`, `EmbeddingService`, `RetrievalService`
- `app/Infrastructure/VectorStore/**` — `MySqlVectorStore` (default), `PineconeVectorStore`,
  `NullVectorStore`
- `ProcessKnowledgeDocument`, `GenerateEmbeddings`, `SyncVectorStore` queued jobs
- Source attribution surfaced in tutor answers

## Out of scope

The tutor service, prompts, and validator — **all F08**. You insert into the retrieval seam
F08 left for you. Document upload UI is F13's; you provide the service it calls.

## Dependencies / prerequisites

**F08 merged.** You need `AIProviderInterface` for embeddings and F08's named retrieval seam.

## Existing code to reuse

None.

## Ownership boundaries

**You own** the two tables, `app/Services/Rag/**`, `app/Infrastructure/VectorStore/**`,
`RagServiceProvider`, the three jobs.

**You must not** modify `AITutorService`, `PromptBuilder`, or `AIResponseValidator` beyond
inserting at the documented seam. Do not modify `app/Contracts/**` (frozen).

## Required implementation

### MySQL is the default, not a fallback

At MVP corpus size — a few thousand chunks, filtered by `organ_id` / `structure_id` /
`education_level` down to tens or low hundreds of rows — `MySqlVectorStore` scans the
survivors and computes cosine similarity in PHP in under 50 ms. No extension, no external
service, no credentials. `PineconeVectorStore` exists to prove the seam and to demonstrate on
request. **Do not make Pinecone the default** and do not let Pinecone-specific types leak past
the interface (`docs/architecture.md` §8.1, PRD §43).

### `search()` takes a vector

```php
public function search(array $queryVector, int $topK = 5, array $filters = []): array;
```

Not a string. Embedding lives in `EmbeddingService`, so both stores stay trivially
interchangeable and one query vector can be reused across them. `RetrievalService` is the
only caller and does embed-then-search.

### Ingestion — all queued (`docs/architecture.md` §8.3)

```
upload → KnowledgeService (validate MIME + extension, size cap, sanitise)
       → ProcessKnowledgeDocument   extract text → ChunkingService (~500 tokens, ~50 overlap)
       → GenerateEmbeddings         batched via generateEmbeddings()
       → SyncVectorStore
status: pending → processing → indexed | failed
```

**Nothing in this chain runs in a web request.**

### Retrieval and honesty

Filters: `organ_id`, `structure_id`, `education_level`, `content_type`. Top-k = 5.

If nothing clears the relevance threshold, the tutor answers from curated structure metadata
and **says so** — it does not silently fall back to unsourced model knowledge. F08 built that
path; keep it working.

Every answer carries its sources; store the retrieved chunk ids in
`conversation_messages.metadata` so a citation can be reconstructed later.

## DB changes

`knowledge_documents` (title, source, source_type, version, status, metadata JSON) and
`knowledge_chunks` (document_id, chunk_index, content, `embedding LONGBLOB` for MySQL,
`embedding_reference` for Pinecone, `organ_id`, `structure_id`, `education_level`,
`content_type`, metadata; index `(organ_id, structure_id, education_level)`).

## Tests

- **One shared contract suite run against both store implementations** — upsert, filtered
  search, delete, ranking order. This is what proves the abstraction.
- `NullVectorStore` used everywhere else; **no test makes a network call**
- Chunking: boundaries, overlap, metadata propagation
- Ingestion happens in jobs — a feature test asserts the request returns before processing
- Retrieval filters actually narrow (a heart query does not return lung chunks)
- No relevant chunks → the tutor's "no sources" path fires

## Acceptance criteria

1. A document uploads, processes through the queue, and becomes retrievable.
2. A tutor answer cites a real source that demonstrably informed it.
3. Switching `VECTOR_STORE` between `mysql` and `pinecone` needs no code change.
4. Retrieval under 50 ms on the MVP corpus with `MySqlVectorStore`.

## Constraints and guardrails

- Uploads: MIME **and** extension checked, size-capped, stored outside the web root with
  generated names, admin-only.
- Never expose vector-store credentials to the browser.
- Do not add a third store. Two is the point (`docs/engineering.md` §12).
- Corpus sources must themselves be redistributable — coordinate with F02's register.

## Definition of Done

`docs/engineering.md` §11.

## Commit boundary

`feat(rag): schema` → `feat(rag): vector store interface implementations` (with the shared
contract suite) → `feat(rag): chunking and embedding services` → `feat(rag): ingestion jobs`
→ `feat(rag): retrieval and tutor integration` → `test(rag): coverage`.
