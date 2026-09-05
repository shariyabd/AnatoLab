<?php

declare(strict_types=1);

use App\Contracts\AIProviderInterface;
use App\Contracts\VectorStoreInterface;
use App\Exceptions\AIProviderException;
use App\Infrastructure\AI\NullProvider;
use App\Infrastructure\VectorStore\MySqlVectorStore;
use App\Infrastructure\VectorStore\NullVectorStore;
use App\Infrastructure\VectorStore\PineconeVectorStore;
use App\Providers\RagServiceProvider;
use App\Services\Rag\EmbeddingService;
use Illuminate\Support\Facades\Http;

/*
| Acceptance criterion 3: switching VECTOR_STORE between `mysql` and `pinecone`
| needs no code change (docs/architecture.md §8.1, PRD §43).
|
| The whole abstraction is worth nothing if the swap is not actually an
| environment variable, so it is asserted rather than asserted about.
*/

function rebindRag(string $store, ?string $embeddingProvider = null): void
{
    config()->set('ai.vector_store', $store);
    config()->set('ai.embeddings.provider', $embeddingProvider);

    // Exactly what bootstrap/providers.php does at boot, no more.
    (new RagServiceProvider(app()))->register();
}

it('resolves the store named by the environment, and nothing else', function (string $store, string $class): void {
    rebindRag($store);

    expect(app(VectorStoreInterface::class))->toBeInstanceOf($class);
})->with([
    // MySQL is the DEFAULT, not a fallback: no extension, no external service,
    // no credentials, and sub-50ms at MVP corpus size.
    'mysql' => ['mysql', MySqlVectorStore::class],
    // Pinecone exists to prove the seam and is chosen deliberately.
    'pinecone' => ['pinecone', PineconeVectorStore::class],
    'null' => ['null', NullVectorStore::class],
]);

it('refuses to start on an unknown store rather than falling back to an empty one', function (): void {
    // An installation that believes it has a knowledge base and is quietly
    // searching an in-memory store is the worse failure.
    expect(function (): void {
        rebindRag('qdrant');
        app(VectorStoreInterface::class);
    })->toThrow(InvalidArgumentException::class, 'Unknown vector store [qdrant]');
});

it('keeps the store a singleton so an upsert survives a re-resolve', function (): void {
    rebindRag('null');

    app(VectorStoreInterface::class)->upsert([[
        'id' => '1:0',
        'vector' => [1.0, 0.0],
        'content' => 'The aorta leaves the left ventricle.',
        'metadata' => ['source_title' => 'Cardiac anatomy'],
    ]]);

    expect(app(VectorStoreInterface::class)->search([1.0, 0.0], topK: 1))->toHaveCount(1);
});

it('embeds with the chat provider when no embedding provider is named', function (): void {
    rebindRag('null', embeddingProvider: null);

    // AI_PROVIDER is `null` in phpunit.xml, and NullProvider embeds without a
    // network call — which is why the whole suite can exercise retrieval.
    expect(app(EmbeddingService::class)->embed('aorta'))
        ->toBe(app(AIProviderInterface::class)->generateEmbedding('aorta'));
});

it('can embed with a second provider while chat stays on the first', function (): void {
    Http::preventStrayRequests();

    rebindRag('null', embeddingProvider: 'openai');

    // Unconfigured OpenAI fails before it reaches the network, which is enough
    // to prove EmbeddingService got the *other* provider: NullProvider would
    // have returned a vector.
    expect(fn (): array => app(EmbeddingService::class)->embed('aorta'))
        ->toThrow(AIProviderException::class);

    // ...and the tutor's chat provider is untouched by that choice.
    expect(app(AIProviderInterface::class))->toBeInstanceOf(NullProvider::class);
});

it('rejects an embedding provider that has no embeddings endpoint', function (): void {
    // Anthropic publishes none. Naming it should fail where it is configured,
    // not at the first ingest three days later
    // (docs/handovers/08-retrieval-seam.md §5).
    expect(function (): void {
        rebindRag('null', embeddingProvider: 'anthropic');
        app(EmbeddingService::class);
    })->toThrow(InvalidArgumentException::class, 'Unknown embedding provider [anthropic]');
});
