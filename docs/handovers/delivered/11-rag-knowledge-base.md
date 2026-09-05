# Handover 11 — RAG & Knowledge Base (delivered)

**Branch** `feat/f11-rag-knowledge-base` · **Contract** [`11-rag-knowledge-base.md`](../11-rag-knowledge-base.md) · **Status** delivered

## What shipped

Curated documents become citable passages behind `VectorStoreInterface`. Ingest is three
queued jobs; retrieval is embed-then-search with a relevance floor; the tutor's answers now
carry real sources. `MySqlVectorStore` is the default and `PineconeVectorStore` proves the
seam, and one shared contract suite runs against both.

- `KnowledgeService` validates MIME **and** extension against `ai.knowledge.allowed_types`,
  stores the bytes under a generated name on the private disk, records the row and returns;
  status goes `pending → processing → indexed | failed`.
- `ChunkingService` splits on sentence boundaries to ~375 words with ~38 of overlap (~500 / ~50
  tokens), copying the document's filter metadata onto every chunk.
- `EmbeddingService` batches through `AIProviderInterface`, bound contextually so chat can stay
  on Anthropic while embeddings run on OpenAI.
- `RetrievalService` searches with `organ_id` / `structure_id` / `education_level` /
  `content_type` filters; the answer's chunk ids land in `conversation_messages.metadata.source_ids`.

## What was plugged into Handover 08's seam

[`08-retrieval-seam.md`](../08-retrieval-seam.md) named one method in one file —
`AITutorService::retrieveGrounding()` — and listed what already expected a non-empty
`list<RetrievedChunk>`: `TutorReplyResource.sources`, `sourceNoteFor()`, `TutorSources.vue`,
`metadata.source_ids`, and `PromptBuilder`'s sources block. Its three steps were followed:

1. `RetrievalService` was added to the constructor, after `AIResponseValidator`.
2. The body calls `$this->retrieval->search()` with `ai.retrieval.top_k` and the frozen
   `LearningContext::retrievalFilters()` (which yields only `education_level`) merged with the
   resolved `organ_id` and `structure_id`, nulls filtered out.
3. The `ai.retrieval.min_score` floor is applied **inside** `RetrievalService::aboveThreshold()`,
   not at the seam — so an empty return keeps meaning "nothing was relevant" rather than
   "nothing was indexed", which is what `sourceNoteFor()` depends on.

Nothing else in `AITutorService` changed, and `PromptBuilder` and `AIResponseValidator` were not
touched. The seam's §5 question was answered by `ai.embeddings.provider`: a separate embedding
provider bound contextually to `EmbeddingService`, with `anthropic` rejected at boot rather than
failing at the first ingest.

## Public surface

No routes. The upload UI belongs to Handover 13, which calls `KnowledgeService` from
`Admin\KnowledgeAdminController` behind `KnowledgeDocumentPolicy`.

### Services / stores

| Class | Responsibility |
|---|---|
| `Services\Rag\KnowledgeService` | Validate, store privately, record the row, queue the chain; also extract, re-ingest and delete |
| `Services\Rag\ChunkingService` | Pure sentence-boundary chunking with overlap and metadata propagation |
| `Services\Rag\EmbeddingService` | Batched embedding, dimension assertion against the active store |
| `Services\Rag\RetrievalService` | The only caller of `search()`; embed-then-search, threshold, graceful degradation |
| `Infrastructure\VectorStore\MySqlVectorStore` | Default. Filters in SQL, scores cosine similarity in PHP |
| `Infrastructure\VectorStore\PineconeVectorStore` | Alternate. `$eq` filters, `matches`, `values`, namespaces all die in this file |
| `Jobs\ProcessKnowledgeDocument` → `GenerateEmbeddings` → `SyncVectorStore` | The ingest chain, all on the `ingest` queue |

### Schema

| Table | Key columns | Indexes / FKs |
|---|---|---|
| `knowledge_documents` | `title`, `source`, `source_type` (32), `version`, `status` (16), `storage_path`, `original_filename`, `mime_type`, `size_bytes`, `metadata` json | `(status, id)` |
| `knowledge_chunks` | `document_id`, `chunk_index`, `content` text, `embedding` blob, `embedding_reference`, `organ_id`, `structure_id`, `education_level` (32), `content_type` (32), `metadata` json | FK `document_id` cascade, `organ_id` / `structure_id` null-on-delete; index `(organ_id, structure_id, education_level)`; unique `(document_id, chunk_index)` |

### How the embedding is stored

`knowledge_chunks.embedding` is a BLOB, widened to LONGBLOB for MySQL by a `DB::statement` in
the migration, and read through `App\Casts\PackedVector`:

- **Packed little-endian float32** (`pack`/`unpack` format `'g'`), fixed rather than the
  machine-dependent `'f'`, "because a database written on one architecture must read back on
  another".
- **4 bytes per dimension** — a 1536-dimension vector is ~6 KB. float32 rather than float64
  because "cosine similarity over unit vectors needs about six significant digits, float32
  carries seven, and halving the blob halves the bytes `MySqlVectorStore` reads per query".
  JSON was rejected as "roughly six times the size … `pack()` is a memcpy in both directions".
- A cast rather than a store helper, so `$chunk->embedding` is a `list<float>` everywhere. An
  empty column reads back as `null`, never as an empty vector, and the store filters on
  `whereNotNull('embedding')` so an unembedded chunk cannot score 0.0 and pad a small result set.
- `embedding_reference` holds the external index's id when Pinecone is active. `GenerateEmbeddings`
  writes the local blob **whichever store is configured**, so switching `VECTOR_STORE` re-syncs
  from existing rows instead of re-embedding the corpus.

### Config keys

`config/ai.php` gains `embeddings.*` (batch size 64) and the `knowledge` block (`disk`,
`allowed_types`, `max_upload_kilobytes` 8192, `chunk_words` 375, `chunk_overlap_words` 38);
`vector_stores` and `retrieval` were already stubbed by Handover 01 and kept their shape.
`bootstrap/providers.php` appends `RagServiceProvider` after `AiServiceProvider`, and
`.env.example` appends a RAG block after Handover 08's, with `VECTOR_STORE=mysql`.

## The retrieval floor

`RetrievalService::search()` returns `[]` in four distinct cases, and the tutor's honest
"no indexed source matched this question" note fires for all of them:

- an empty question or `topK < 1` — the store is not asked at all, or an empty embedding vector;
- every candidate scoring below `ai.retrieval.min_score` (default **0.65**), dropped by
  `aboveThreshold()` — "a weak match is worse than no match: it is cited to the student as a source";
- an `AIProviderException` or `VectorStoreException`, caught and logged at warning with the
  filter keys only — "rethrowing here would turn a knowledge-base outage into a broken tutor".

## Key decisions

- **MySQL is the default, chosen not fallen back to.** "A competition demo that depends on a
  third-party index is a demo that can be taken down by someone else's outage. If it stops
  being fast, the answer is `PineconeVectorStore`, not a rewrite."
- **An unknown `VECTOR_STORE` throws at boot**, loud rather than "quietly searching an empty
  index".
- **`MySqlVectorStore` uses Eloquent, not raw SQL.** Infrastructure normally stays clear of
  models, but "a MySQL-backed store *is* that table, and the alternative — raw SQL — breaks
  invariant 6 to avoid breaking a guideline".
- **`RagServiceProvider` rebinds only `VectorStoreInterface` globally**; the embedding provider
  is attached contextually to `EmbeddingService`, "so nothing else in the application sees a
  different provider than the one `AI_PROVIDER` selected".
- **Vector ids are `{document}:{index}`**, which makes `upsert()` idempotent through the unique
  `(document_id, chunk_index)` pair and makes `delete()` on an unknown id a no-op rather than a
  failure.

## Invariants honoured

- **1 — services read no ambient state.** `KnowledgeService` takes the `UploadedFile` and a
  `KnowledgeDocumentData`; authorisation is the caller's. `KnowledgeIngestionTest` → "returns
  before anything is processed and leaves the work on the ingest queue".
- **2 — depend on `App\Contracts`.** `VectorStoreBindingTest` → "resolves the store named by the
  environment, and nothing else", "refuses to start on an unknown store rather than falling back
  to an empty one", "can embed with a second provider while chat stays on the first", "rejects
  an embedding provider that has no embeddings endpoint".
- **6 — Eloquent only.** The single `DB::statement` is the LONGBLOB widening in the migration,
  which invariant 6 explicitly permits.
- **The tutor never loses its honesty path.** `TutorGroundingTest` → "still says it had no
  sources when nothing clears the threshold" and "keeps the answer free of a knowledge-base
  failure when the store is down".

## Tests

| File / dir | What it proves |
|---|---|
| `tests/Feature/Rag/VectorStoreContractTest.php` | **The shared contract suite.** One body of assertions over a `['mysql','pinecone','null']` dataset: ranking order, filter-before-score, topK, upsert replaces rather than duplicates, delete ignores unknown ids, mismatched dimensions score zero |
| `tests/Support/FakePineconeIndex.php` | An in-memory Pinecone over `Http::fake()` implementing cosine similarity, `$eq` filters and topK for real; `preventStrayRequests()` turns any escaped request into a failure |
| `tests/Feature/Rag/RetrievalServiceTest.php` | Filters narrow (a heart question never cites a lung source), the threshold drops weak matches, outages degrade to no sources |
| `tests/Feature/Rag/RetrievalPerformanceTest.php` | Acceptance criterion 4 measured, not asserted: 400 chunks, 100 surviving an organ filter, median of three under 50 ms |
| `tests/Feature/Rag/KnowledgeIngestionTest.php` | The request returns before processing; MIME-vs-extension mismatch, wrong extension and oversize rejected; the full chain leaves a document indexed and retrievable; re-ingest replaces; delete removes file, chunks and vectors |
| `tests/Feature/Rag/ChunkingServiceTest.php` | Boundaries, whole-sentence overlap, hard-split of an oversized sentence, metadata propagation, ~500/~50 defaults |
| `tests/Feature/Rag/EmbeddingServiceTest.php`, `PackedVectorTest.php` | Batching preserves input order and refuses a vector the active store cannot hold; the packed column round-trips order and float32 precision, and an absent embedding reads as null |
| `tests/Feature/Rag/TutorGroundingTest.php` | A cited source is the one that reached the prompt; chunk ids on the stored turn; no cross-organ citation; the "no sources" path still fires |

## Known gaps / follow-ups

- **No corpus is seeded by this lane.** `KnowledgeSourceType` requires redistributable material
  and the licence register is Handover 02's; the only knowledge documents in the database come
  from Handover 14's `DemoSeeder`. Coordinating real sources with the register is outstanding.
- **Ingest accepts `.txt` and `.md` only** — "every binary document format needs a parser
  dependency, and adding one requires human approval with a licence check". Handover 13's UI
  must convert, or an admin pastes text.
- `AI_PROVIDER=anthropic` still cannot embed on its own; `AI_EMBEDDING_PROVIDER=openai` must be
  set, and `anthropic` is rejected there deliberately.
- The MySQL store scores every surviving row in PHP; the 50 ms budget is measured at 400 chunks
  and 100 survivors. Beyond MVP corpus size the documented answer is Pinecone, not a rewrite.
