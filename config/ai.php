<?php

declare(strict_types=1);

/*
|-------------------------------------------------------------------------------
| AI and retrieval configuration — SKELETON
|-------------------------------------------------------------------------------
|
| Handover 01 establishes the shape and the bindings. The provider and vector
| store implementations themselves belong to Handover 08 (AI Tutor) and
| Handover 11 (RAG); until those land, both default keys resolve to the null
| implementations, which are also what the test suite always uses.
|
| This file is a shared/protected path (docs/engineering.md §7). Adding a key
| is fine; changing the shape of an existing one is a stop-and-ask.
|
*/

return [

    /*
    |---------------------------------------------------------------------------
    | Active implementations
    |---------------------------------------------------------------------------
    |
    | These two keys are the whole point of the abstraction: swapping a provider
    | or a vector store is an environment change, never a code change
    | (docs/architecture.md §8.1).
    |
    */

    'provider' => env('AI_PROVIDER', 'null'),

    'vector_store' => env('VECTOR_STORE', 'null'),

    /*
    |---------------------------------------------------------------------------
    | Providers
    |---------------------------------------------------------------------------
    */

    'providers' => [

        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'chat_model' => env('ANTHROPIC_CHAT_MODEL', 'claude-sonnet-5'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        ],

        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'chat_model' => env('OPENAI_CHAT_MODEL', 'gpt-4o-mini'),
            'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        ],

        'null' => [],

    ],

    /*
    |---------------------------------------------------------------------------
    | Vector stores
    |---------------------------------------------------------------------------
    |
    | MySQL is the intended default once Handover 11 implements it: at MVP
    | corpus size a filtered cosine scan in PHP is sub-50ms and needs no
    | extension. Pinecone exists to prove the seam (docs/architecture.md §8.1).
    |
    */

    'vector_stores' => [

        'mysql' => [
            'dimensions' => (int) env('VECTOR_DIMENSIONS', 1536),
        ],

        'pinecone' => [
            'api_key' => env('PINECONE_API_KEY'),
            'host' => env('PINECONE_HOST'),
            'namespace' => env('PINECONE_NAMESPACE', 'anatolab'),
            'dimensions' => (int) env('VECTOR_DIMENSIONS', 1536),
        ],

        'null' => [],

    ],

    /*
    |---------------------------------------------------------------------------
    | Retrieval
    |---------------------------------------------------------------------------
    */

    'retrieval' => [
        'top_k' => (int) env('AI_RETRIEVAL_TOP_K', 5),
        'min_score' => (float) env('AI_RETRIEVAL_MIN_SCORE', 0.65),
    ],

    /*
    |---------------------------------------------------------------------------
    | Guardrails
    |---------------------------------------------------------------------------
    |
    | Rate limits ship with the endpoint, not after it (docs/engineering.md §10).
    | max_response_words keeps tutor answers readable for a 14-18 year old.
    |
    */

    'limits' => [
        'requests_per_minute' => (int) env('AI_REQUESTS_PER_MINUTE', 10),
        'requests_per_day' => (int) env('AI_REQUESTS_PER_DAY', 200),
        'max_response_words' => 220,
        'max_context_chunks' => 5,

        /*
        | Added by Handover 08. The generic `requests_per_minute` above stays as
        | the shared `ai` limiter for Handovers 11 and 12; the tutor endpoints
        | need their own numbers because docs/architecture.md §8.4 specifies
        | them per endpoint — 20/min and 200/day for ask and explain, 30/min for
        | hints, which are cheaper and asked in bursts while a student is stuck.
        */
        'tutor_per_minute' => (int) env('AI_TUTOR_PER_MINUTE', 20),
        'tutor_per_day' => (int) env('AI_TUTOR_PER_DAY', 200),
        'hint_per_minute' => (int) env('AI_HINT_PER_MINUTE', 30),
    ],

    /*
    |---------------------------------------------------------------------------
    | Transport
    |---------------------------------------------------------------------------
    |
    | Added by Handover 08. docs/architecture.md §8.4: a 20-second provider
    | timeout, one retry, then a graceful message. One retry rather than three
    | because a student is waiting: a second failure is a real outage, and three
    | attempts turn a 20-second wait into a minute of a spinner.
    |
    */

    'transport' => [
        'timeout_seconds' => (int) env('AI_TIMEOUT_SECONDS', 20),
        'retries' => (int) env('AI_RETRIES', 1),
        'retry_delay_ms' => (int) env('AI_RETRY_DELAY_MS', 400),
    ],

    /*
    |---------------------------------------------------------------------------
    | Tutor
    |---------------------------------------------------------------------------
    |
    | Added by Handover 08.
    |
    */

    'tutor' => [
        /*
        | How many previous turns are replayed to the provider. Enough for a
        | follow-up question to make sense, short enough that a long thread does
        | not silently grow every prompt until it is mostly history.
        */
        'history_turns' => (int) env('AI_TUTOR_HISTORY_TURNS', 6),

        'max_tokens' => (int) env('AI_TUTOR_MAX_TOKENS', 700),

        /*
        | Low, not zero. Tutoring benefits from some variation in phrasing when
        | a student asks the same thing twice; zero makes the second explanation
        | identical to the first one they did not understand.
        */
        'temperature' => (float) env('AI_TUTOR_TEMPERATURE', 0.3),
    ],

    /*
    |---------------------------------------------------------------------------
    | Embeddings
    |---------------------------------------------------------------------------
    |
    | Added by Handover 11.
    |
    | Anthropic publishes no embeddings endpoint, so AnthropicProvider::
    | generateEmbeddings() throws. Rather than making RAG require
    | AI_PROVIDER=openai, this key names the provider used for embeddings only:
    | leave it unset and embeddings use whichever provider `ai.provider` selected,
    | or set it to `openai` to run Anthropic for chat and OpenAI for vectors.
    | RagServiceProvider binds it contextually to EmbeddingService, so services
    | still depend on AIProviderInterface and nothing else (invariant 2).
    |
    | `anthropic` is deliberately not accepted here: naming a provider that
    | cannot embed should fail at boot, not at the first ingest.
    |
    */

    'embeddings' => [
        'provider' => env('AI_EMBEDDING_PROVIDER'),

        /*
        | Texts per embeddings request. Batched because ingest embeds thousands
        | of chunks and the round trip, not the compute, is the cost.
        */
        'batch_size' => (int) env('AI_EMBEDDING_BATCH_SIZE', 64),
    ],

    /*
    |---------------------------------------------------------------------------
    | Knowledge base
    |---------------------------------------------------------------------------
    |
    | Added by Handover 11 (docs/architecture.md §8.3).
    |
    */

    'knowledge' => [
        /*
        | The private disk: storage/app/private, outside the web root. An
        | uploaded corpus document is never served as a static file — a citation
        | shows the passage that was retrieved, not the source PDF.
        */
        'disk' => env('KNOWLEDGE_DISK', 'local'),
        'directory' => 'knowledge',

        /*
        | MIME type AND extension must both appear here (docs/engineering.md §10).
        | Extension alone trusts the uploader; MIME alone is not specific enough
        | — PHP detects a Markdown file's content as `text/plain`, so `text/plain`
        | has to admit `.md`, and the extension is what says how to read it.
        |
        | Plain text and Markdown only, on purpose: every binary document format
        | needs a parser dependency, and adding one requires human approval with
        | a licence check (docs/engineering.md §5). F13's upload UI converts, or
        | an admin pastes text.
        |
        | @var array<string, list<string>> MIME type => permitted extensions
        */
        'allowed_types' => [
            'text/plain' => ['txt', 'text', 'md', 'markdown'],
            'text/markdown' => ['md', 'markdown'],
            'text/x-markdown' => ['md', 'markdown'],
        ],

        'max_upload_kilobytes' => (int) env('KNOWLEDGE_MAX_UPLOAD_KB', 8192),

        /*
        | ~500-token chunks with ~50 tokens of overlap (docs/architecture.md
        | §8.3), counted in words because a word count needs no tokeniser and
        | English prose runs about 0.75 words per token. Overlap exists so a
        | definition split across a boundary still appears whole in one chunk.
        */
        'chunk_words' => (int) env('KNOWLEDGE_CHUNK_WORDS', 375),
        'chunk_overlap_words' => (int) env('KNOWLEDGE_CHUNK_OVERLAP_WORDS', 38),
    ],

];
