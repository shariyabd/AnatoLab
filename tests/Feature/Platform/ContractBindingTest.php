<?php

declare(strict_types=1);

use App\Contracts\AIProviderInterface;
use App\Contracts\AIResponse;
use App\Contracts\RetrievedChunk;
use App\Contracts\VectorStoreInterface;
use App\Infrastructure\AI\NullProvider;
use App\Infrastructure\VectorStore\NullVectorStore;

it('resolves both contracts from the container', function (): void {
    expect(app(AIProviderInterface::class))->toBeInstanceOf(NullProvider::class)
        ->and(app(VectorStoreInterface::class))->toBeInstanceOf(NullVectorStore::class);
});

it('keeps the vector store a singleton so upserts survive a re-resolve', function (): void {
    // NullVectorStore holds its documents in memory. Bound per-resolve, a
    // service that upserts and a service that searches would see two different,
    // empty stores — and the bug would look like "retrieval returns nothing".
    app(VectorStoreInterface::class)->upsert([[
        'id' => 'chunk-1',
        'vector' => [1.0, 0.0, 0.0],
        'content' => 'The left ventricle pumps blood into the aorta.',
        'metadata' => ['source_title' => 'Cardiac anatomy'],
    ]]);

    expect(app(VectorStoreInterface::class)->search([1.0, 0.0, 0.0], topK: 1))->toHaveCount(1);
});

describe('NullProvider satisfies AIProviderInterface', function (): void {
    it('returns an AIResponse from chat', function (): void {
        $response = app(AIProviderInterface::class)->chat([
            ['role' => 'user', 'content' => 'What does the left ventricle do?'],
        ]);

        expect($response)->toBeInstanceOf(AIResponse::class)
            ->and($response->content)->toContain('left ventricle')
            ->and($response->model)->toBe('null')
            ->and($response->totalTokens())->toBe(0);
    });

    it('produces deterministic, unit-length embeddings', function (): void {
        $provider = new NullProvider(dimensions: 32);

        $first = $provider->generateEmbedding('aorta');
        $second = $provider->generateEmbedding('aorta');

        // Determinism is what lets retrieval tests assert an ORDER rather than
        // merely asserting that something came back.
        expect($first)->toBe($second)->toHaveCount(32);

        $magnitude = sqrt(array_sum(array_map(fn (float $v): float => $v ** 2, $first)));

        expect($magnitude)->toEqualWithDelta(1.0, 1e-9);
    });

    it('embeds a batch in input order', function (): void {
        $provider = new NullProvider(dimensions: 8);

        $batch = $provider->generateEmbeddings(['aorta', 'atrium']);

        expect($batch)->toHaveCount(2)
            ->and($batch[0])->toBe($provider->generateEmbedding('aorta'))
            ->and($batch[1])->toBe($provider->generateEmbedding('atrium'));
    });

    it('gives different text different vectors', function (): void {
        $provider = new NullProvider(dimensions: 64);

        expect($provider->generateEmbedding('aorta'))
            ->not->toBe($provider->generateEmbedding('trachea'));
    });
});

describe('NullVectorStore satisfies VectorStoreInterface', function (): void {
    beforeEach(function (): void {
        $this->store = new NullVectorStore;
    });

    it('ranks by similarity, closest first', function (): void {
        $this->store->upsert([
            ['id' => 'far', 'vector' => [0.0, 1.0], 'content' => 'far', 'metadata' => []],
            ['id' => 'near', 'vector' => [1.0, 0.0], 'content' => 'near', 'metadata' => []],
        ]);

        $results = $this->store->search([1.0, 0.0], topK: 2);

        expect($results[0]->id)->toBe('near')
            ->and($results[0]->score)->toBeGreaterThan($results[1]->score)
            ->and($results[0])->toBeInstanceOf(RetrievedChunk::class);
    });

    it('honours topK', function (): void {
        $this->store->upsert(array_map(
            fn (int $i): array => [
                'id' => "chunk-{$i}",
                'vector' => [1.0, (float) $i],
                'content' => "chunk {$i}",
                'metadata' => [],
            ],
            range(1, 10),
        ));

        expect($this->store->search([1.0, 0.0], topK: 3))->toHaveCount(3);
    });

    it('applies metadata filters before scoring', function (): void {
        $this->store->upsert([
            [
                'id' => 'heart',
                'vector' => [1.0, 0.0],
                'content' => 'heart',
                'metadata' => ['organ_id' => 1, 'source_title' => 'Cardiac'],
            ],
            [
                'id' => 'lung',
                'vector' => [1.0, 0.0],
                'content' => 'lung',
                'metadata' => ['organ_id' => 2, 'source_title' => 'Respiratory'],
            ],
        ]);

        $results = $this->store->search([1.0, 0.0], topK: 5, filters: ['organ_id' => 2]);

        expect($results)->toHaveCount(1)
            ->and($results[0]->id)->toBe('lung')
            ->and($results[0]->sourceTitle)->toBe('Respiratory');
    });

    it('replaces rather than duplicates on re-upsert', function (): void {
        $document = ['id' => 'same', 'vector' => [1.0, 0.0], 'content' => 'v1', 'metadata' => []];

        $this->store->upsert([$document]);
        $this->store->upsert([[...$document, 'content' => 'v2']]);

        $results = $this->store->search([1.0, 0.0], topK: 5);

        expect($this->store->count())->toBe(1)
            ->and($results[0]->content)->toBe('v2');
    });

    it('deletes by id and ignores unknown ids', function (): void {
        $this->store->upsert([
            ['id' => 'a', 'vector' => [1.0], 'content' => 'a', 'metadata' => []],
        ]);

        $this->store->delete(['a', 'never-existed']);

        expect($this->store->count())->toBe(0);
    });

    it('scores mismatched dimensions as zero rather than ranking garbage', function (): void {
        // A dimension mismatch means the corpus was embedded with a different
        // model than the query. Scoring it anyway would put nonsense at the top.
        $this->store->upsert([
            ['id' => 'wrong-dims', 'vector' => [1.0, 0.0, 0.0], 'content' => 'x', 'metadata' => []],
        ]);

        expect($this->store->search([1.0, 0.0], topK: 1)[0]->score)->toBe(0.0);
    });

    it('returns nothing from an empty store', function (): void {
        expect($this->store->search([1.0, 0.0], topK: 5))->toBe([]);
    });
});
