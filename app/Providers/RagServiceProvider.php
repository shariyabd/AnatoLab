<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\AIProviderInterface;
use App\Contracts\VectorStoreInterface;
use App\Infrastructure\AI\NullProvider;
use App\Infrastructure\AI\OpenAIProvider;
use App\Infrastructure\VectorStore\MySqlVectorStore;
use App\Infrastructure\VectorStore\NullVectorStore;
use App\Infrastructure\VectorStore\PineconeVectorStore;
use App\Services\Rag\EmbeddingService;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * OWNED BY HANDOVER 11.
 *
 * Registered after AppServiceProvider and AiServiceProvider in
 * bootstrap/providers.php, so the bind below replaces the platform's baseline
 * NullVectorStore binding — a later bind() wins, which is how a feature
 * overrides the platform without editing a file Handover 01 owns.
 *
 * It rebinds only VectorStoreInterface globally. AiServiceProvider's
 * AIProviderInterface binding is left exactly as it is; the embedding provider
 * is attached *contextually* to EmbeddingService, so nothing else in the
 * application sees a different provider than the one AI_PROVIDER selected.
 */
final class RagServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singleton: NullVectorStore holds its documents in memory, and the two
        // real stores are stateless, so one instance per process is right in
        // every case.
        $this->app->singleton(VectorStoreInterface::class, fn (): VectorStoreInterface => $this->makeStore());

        $this->app->when(EmbeddingService::class)
            ->needs(AIProviderInterface::class)
            ->give(fn (): AIProviderInterface => $this->makeEmbeddingProvider());
    }

    /**
     * The whole point of the abstraction: which class this returns is an
     * environment variable (docs/architecture.md §8.1, PRD §43).
     *
     * MySQL is the default. Pinecone exists to prove the seam and is chosen
     * deliberately, never fallen back to.
     */
    private function makeStore(): VectorStoreInterface
    {
        return match ($this->configuredName('ai.vector_store')) {
            'mysql' => new MySqlVectorStore,
            'pinecone' => $this->makePineconeStore(),
            'null' => new NullVectorStore,
            // Loud rather than silently falling back: an installation that
            // believes it has a knowledge base and is quietly searching an
            // empty in-memory store is the worse failure.
            default => throw new InvalidArgumentException(sprintf(
                'Unknown vector store [%s]. Set VECTOR_STORE to mysql, pinecone, or null.',
                $this->configuredName('ai.vector_store'),
            )),
        };
    }

    private function makePineconeStore(): PineconeVectorStore
    {
        return new PineconeVectorStore(
            http: $this->app->make(HttpFactory::class),
            apiKey: (string) config('ai.vector_stores.pinecone.api_key', ''),
            host: (string) config('ai.vector_stores.pinecone.host', ''),
            namespace: (string) config('ai.vector_stores.pinecone.namespace', 'anatolab'),
            timeoutSeconds: (int) config('ai.transport.timeout_seconds', 20),
            retries: (int) config('ai.transport.retries', 1),
            retryDelayMs: (int) config('ai.transport.retry_delay_ms', 400),
        );
    }

    /**
     * The provider EmbeddingService embeds with.
     *
     * Anthropic publishes no embeddings endpoint and AnthropicProvider::
     * generateEmbeddings() throws rather than returning zero vectors, which
     * would rank every chunk identically and look like a retrieval-quality
     * problem (docs/handovers/08-retrieval-seam.md §5). Rather than making RAG
     * require AI_PROVIDER=openai, `AI_EMBEDDING_PROVIDER` names a second
     * provider used for embeddings only.
     *
     * Unset — the common case — means "whatever AI_PROVIDER selected", so a
     * single-provider installation configures nothing. `anthropic` is
     * deliberately not accepted: naming a provider that cannot embed should
     * fail at boot, not at the first ingest.
     */
    private function makeEmbeddingProvider(): AIProviderInterface
    {
        $name = config('ai.embeddings.provider');

        if (! is_string($name) || $name === '') {
            return $this->app->make(AIProviderInterface::class);
        }

        return match ($name) {
            'openai' => $this->makeOpenAIProvider(),
            'null' => new NullProvider(dimensions: $this->dimensions()),
            default => throw new InvalidArgumentException(sprintf(
                'Unknown embedding provider [%s]. Set AI_EMBEDDING_PROVIDER to openai or null, '
                .'or leave it unset to embed with AI_PROVIDER.',
                $name,
            )),
        };
    }

    private function makeOpenAIProvider(): OpenAIProvider
    {
        return new OpenAIProvider(
            http: $this->app->make(HttpFactory::class),
            apiKey: (string) config('ai.providers.openai.api_key', ''),
            chatModel: (string) config('ai.providers.openai.chat_model', 'gpt-4o-mini'),
            embeddingModel: (string) config('ai.providers.openai.embedding_model', 'text-embedding-3-small'),
            baseUrl: (string) config('ai.providers.openai.base_url', 'https://api.openai.com/v1'),
            timeoutSeconds: (int) config('ai.transport.timeout_seconds', 20),
            retries: (int) config('ai.transport.retries', 1),
            retryDelayMs: (int) config('ai.transport.retry_delay_ms', 400),
        );
    }

    private function dimensions(): int
    {
        return (int) config(
            'ai.vector_stores.'.$this->configuredName('ai.vector_store').'.dimensions',
            1536,
        );
    }

    /**
     * Read a config key that names an implementation, defaulting to `null`.
     *
     * `VECTOR_STORE=null` in an env file is the *string* "null", which env()
     * converts to PHP null before config() ever sees it; config() then returns
     * that null rather than the default. Normalising here keeps config/ai.php
     * unchanged, the same way AiServiceProvider does for the provider key.
     */
    private function configuredName(string $key): string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : 'null';
    }
}
