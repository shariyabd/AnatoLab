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

];
