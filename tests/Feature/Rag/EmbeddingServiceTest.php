<?php

declare(strict_types=1);

use App\Contracts\AIProviderInterface;
use App\Contracts\AIResponse;
use App\Exceptions\VectorStoreException;
use App\Infrastructure\AI\NullProvider;
use App\Services\Rag\EmbeddingService;

/*
| Embedding lives in one service so that VectorStoreInterface::search() can take
| a vector and the two stores stay interchangeable (docs/architecture.md §8.1).
| Everything here runs against NullProvider or a recorder — no network.
*/

/**
 * A provider that remembers how it was called.
 */
function recordingProvider(int $dimensions = 4): AIProviderInterface
{
    return new class($dimensions) implements AIProviderInterface
    {
        /** @var list<int> */
        public array $batchSizes = [];

        public function __construct(private readonly int $dimensions) {}

        public function chat(array $messages, array $options = []): AIResponse
        {
            return new AIResponse(content: '', model: 'recorder');
        }

        public function generateEmbedding(string $text): array
        {
            return $this->generateEmbeddings([$text])[0];
        }

        public function generateEmbeddings(array $texts): array
        {
            $this->batchSizes[] = count($texts);

            return array_map(
                fn (string $text): array => array_fill(0, $this->dimensions, (float) strlen($text)),
                $texts,
            );
        }
    };
}

it('embeds nothing for an empty batch without calling the provider', function (): void {
    $provider = recordingProvider();

    expect((new EmbeddingService($provider))->embedAll([]))->toBe([]);
    expect($provider->batchSizes)->toBe([]);
});

it('splits a long list into provider batches and keeps input order', function (): void {
    config()->set('ai.embeddings.batch_size', 3);

    $provider = recordingProvider();
    $texts = ['a', 'bb', 'ccc', 'dddd', 'eeeee', 'ffffff', 'ggggggg'];

    $vectors = (new EmbeddingService($provider))->embedAll($texts);

    // One request per batch, not one per chunk: ingest embeds thousands of
    // passages and the round trip is the cost.
    expect($provider->batchSizes)->toBe([3, 3, 1])
        ->and($vectors)->toHaveCount(7)
        // Each vector here encodes its own text's length, so order is visible.
        ->and(array_map(static fn (array $vector): float => $vector[0], $vectors))
        ->toBe([1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0]);
});

it('never sends a zero-size batch even if the config says so', function (): void {
    config()->set('ai.embeddings.batch_size', 0);

    $provider = recordingProvider();

    (new EmbeddingService($provider))->embedAll(['a', 'b']);

    expect($provider->batchSizes)->toBe([1, 1]);
});

it('refuses a vector the configured store cannot hold', function (): void {
    // A mismatch means the corpus and the query were embedded by different
    // models. Both stores score that as zero, so without this check the symptom
    // is "retrieval quietly returns nothing" rather than "the model changed".
    config()->set('ai.vector_store', 'mysql');
    config()->set('ai.vector_stores.mysql.dimensions', 1536);

    expect(fn (): array => (new EmbeddingService(recordingProvider(8)))->embed('aorta'))
        ->toThrow(VectorStoreException::class, 'expects 1536-dimension vectors, got 8');
});

it('accepts whatever the null store is given, so the suite needs no fixed width', function (): void {
    config()->set('ai.vector_store', 'null');

    expect((new EmbeddingService(recordingProvider(8)))->embed('aorta'))->toHaveCount(8);
});

it('reports the width the active store was built for', function (): void {
    config()->set('ai.vector_store', 'pinecone');
    config()->set('ai.vector_stores.pinecone.dimensions', 3072);

    expect((new EmbeddingService(new NullProvider))->dimensions())->toBe(3072);
});
