<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * A large-language-model provider.
 *
 * One of exactly two abstractions in this codebase (docs/engineering.md §5).
 * It exists because a second real implementation exists: Anthropic is the
 * default, OpenAI is the alternate, and NullProvider serves the test suite.
 *
 * Services depend on this interface and never on a concrete provider; the
 * binding is made once in AppServiceProvider from config('ai.provider'), so
 * swapping providers is an environment change (docs/architecture.md §8.1).
 *
 * Implementations must not leak upstream failures. A provider or transport
 * error is wrapped in App\Exceptions\AIProviderException, which the exception
 * handler turns into an educational-tone message with no provider name, status
 * code, or stack trace (docs/architecture.md §14).
 */
interface AIProviderInterface
{
    /**
     * Send a chat completion request.
     *
     * @param  list<array{role: string, content: string}>  $messages  ordered oldest-first
     * @param  array<string, mixed>  $options  provider-agnostic hints: system, max_tokens, temperature
     *
     * @throws \App\Exceptions\AIProviderException
     */
    public function chat(array $messages, array $options = []): AIResponse;

    /**
     * Embed a single string.
     *
     * @return list<float> dense vector, config('ai.vector_stores.*.dimensions') long
     *
     * @throws \App\Exceptions\AIProviderException
     */
    public function generateEmbedding(string $text): array;

    /**
     * Embed many strings in one round trip.
     *
     * Batched because ingest embeds thousands of chunks; calling
     * generateEmbedding() in a loop is the difference between one request and
     * one per chunk. Returned vectors are in the same order as $texts.
     *
     * @param  list<string>  $texts
     * @return list<list<float>>
     *
     * @throws \App\Exceptions\AIProviderException
     */
    public function generateEmbeddings(array $texts): array;
}
