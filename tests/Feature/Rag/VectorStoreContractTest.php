<?php

declare(strict_types=1);

use App\Contracts\RetrievedChunk;
use App\Contracts\VectorStoreInterface;
use App\Infrastructure\VectorStore\MySqlVectorStore;
use App\Infrastructure\VectorStore\NullVectorStore;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Organ;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakePineconeIndex;

/*
| THE SHARED CONTRACT SUITE (docs/engineering.md §9).
|
| One body of assertions, run against every VectorStoreInterface implementation.
| This is what makes the interface an abstraction rather than a shape: a store
| that ranked differently, filtered after scoring, duplicated on re-upsert, or
| threw on an unknown delete would pass its own tests and break the tutor the
| day someone changed VECTOR_STORE.
|
| No test here makes a network call. MySQL and null are local; Pinecone runs
| against FakePineconeIndex over Http::fake(), and preventStrayRequests() below
| turns any request that escapes into a failure rather than a timeout.
*/

dataset('vector stores', ['mysql', 'pinecone', 'null']);

/**
 * Build the named implementation, wired the way RagServiceProvider wires it.
 */
function makeVectorStore(string $name): VectorStoreInterface
{
    return match ($name) {
        'mysql' => new MySqlVectorStore,
        'pinecone' => FakePineconeIndex::install(),
        default => new NullVectorStore,
    };
}

/**
 * One document in the store's own vocabulary.
 *
 * The id is `{document}:{index}` because that is what MySqlVectorStore
 * addresses a row by, and it is an opaque string to the other two — which is
 * the point: one call shape works everywhere.
 *
 * @param  list<float>  $vector
 * @param  array<string, scalar|null>  $metadata
 * @return array{id: string, vector: list<float>, content: string, metadata: array<string, scalar|null>}
 */
function passage(int $documentId, int $index, array $vector, string $content, array $metadata = []): array
{
    return [
        'id' => KnowledgeChunk::vectorIdFor($documentId, $index),
        'vector' => $vector,
        'content' => $content,
        'metadata' => ['source_title' => 'Cardiac anatomy', ...$metadata],
    ];
}

beforeEach(function (): void {
    Http::preventStrayRequests();

    // MySqlVectorStore is backed by knowledge_chunks, so its rows need a parent
    // document and real organs to point at. The other two implementations
    // ignore all of it and read the ids and metadata they were handed.
    $this->document = KnowledgeDocument::factory()->create(['title' => 'Cardiac anatomy']);
    $this->documentId = (int) $this->document->getKey();
    $this->heart = (int) Organ::factory()->published()->create(['slug' => 'heart'])->getKey();
    $this->lungs = (int) Organ::factory()->published()->create(['slug' => 'lungs'])->getKey();
});

it('returns nothing from an empty store', function (string $name): void {
    expect(makeVectorStore($name)->search([1.0, 0.0], topK: 5))->toBe([]);
})->with('vector stores');

it('stores a passage and finds it again', function (string $name): void {
    $store = makeVectorStore($name);

    $store->upsert([passage($this->documentId, 0, [1.0, 0.0], 'The left ventricle pumps into the aorta.')]);

    $results = $store->search([1.0, 0.0], topK: 5);

    expect($results)->toHaveCount(1)
        ->and($results[0])->toBeInstanceOf(RetrievedChunk::class)
        ->and($results[0]->id)->toBe(KnowledgeChunk::vectorIdFor($this->documentId, 0))
        ->and($results[0]->content)->toBe('The left ventricle pumps into the aorta.')
        ->and($results[0]->score)->toBeGreaterThan(0.99);
})->with('vector stores');

it('ranks by similarity, closest first', function (string $name): void {
    $store = makeVectorStore($name);

    $store->upsert([
        passage($this->documentId, 0, [0.0, 1.0], 'far'),
        passage($this->documentId, 1, [1.0, 0.0], 'near'),
        passage($this->documentId, 2, [0.7, 0.7], 'middle'),
    ]);

    $results = $store->search([1.0, 0.0], topK: 3);

    expect(array_map(static fn (RetrievedChunk $chunk): string => $chunk->content, $results))
        ->toBe(['near', 'middle', 'far'])
        ->and($results[0]->score)->toBeGreaterThan($results[1]->score)
        ->and($results[1]->score)->toBeGreaterThan($results[2]->score);
})->with('vector stores');

it('honours topK', function (string $name): void {
    $store = makeVectorStore($name);

    $store->upsert(array_map(
        fn (int $index): array => passage($this->documentId, $index, [1.0, $index / 10], "passage {$index}"),
        range(0, 9),
    ));

    expect($store->search([1.0, 0.0], topK: 3))->toHaveCount(3);
})->with('vector stores');

it('applies metadata filters before scoring', function (string $name): void {
    $store = makeVectorStore($name);

    // Identical vectors: only the filter can decide the result, so a store that
    // filtered after scoring — or not at all — returns the wrong passage.
    $store->upsert([
        passage($this->documentId, 0, [1.0, 0.0], 'heart passage', ['organ_id' => $this->heart]),
        passage($this->documentId, 1, [1.0, 0.0], 'lung passage', ['organ_id' => $this->lungs]),
    ]);

    $results = $store->search([1.0, 0.0], topK: 5, filters: ['organ_id' => $this->lungs]);

    expect($results)->toHaveCount(1)
        ->and($results[0]->content)->toBe('lung passage')
        ->and($results[0]->metadata['organ_id'])->toBe($this->lungs);
})->with('vector stores');

it('replaces rather than duplicates on re-upsert', function (string $name): void {
    $store = makeVectorStore($name);

    $store->upsert([passage($this->documentId, 0, [1.0, 0.0], 'version one')]);
    $store->upsert([passage($this->documentId, 0, [1.0, 0.0], 'version two')]);

    $results = $store->search([1.0, 0.0], topK: 5);

    // Idempotence is not a nicety: re-ingesting a corrected document is the
    // normal admin action, and a store that appended would serve both versions.
    expect($results)->toHaveCount(1)
        ->and($results[0]->content)->toBe('version two');
})->with('vector stores');

it('deletes by id and ignores unknown ids', function (string $name): void {
    $store = makeVectorStore($name);

    $store->upsert([
        passage($this->documentId, 0, [1.0, 0.0], 'kept'),
        passage($this->documentId, 1, [1.0, 0.0], 'removed'),
    ]);

    $store->delete([
        KnowledgeChunk::vectorIdFor($this->documentId, 1),
        // An id the store never held: a replayed delete must not fail the job.
        KnowledgeChunk::vectorIdFor($this->documentId, 99),
    ]);

    $results = $store->search([1.0, 0.0], topK: 5);

    expect($results)->toHaveCount(1)
        ->and($results[0]->content)->toBe('kept');
})->with('vector stores');

it('carries a source title so an answer can cite it', function (string $name): void {
    $store = makeVectorStore($name);

    $store->upsert([passage($this->documentId, 0, [1.0, 0.0], 'The aorta leaves the left ventricle.')]);

    // PRD §12: a chunk that cannot say where it came from is unusable for a
    // grounded answer, so every implementation has to carry the attribution.
    expect($store->search([1.0, 0.0], topK: 1)[0]->sourceTitle)->toBe('Cardiac anatomy');
})->with('vector stores');

it('scores a mismatched dimension as zero rather than ranking garbage', function (string $name): void {
    $store = makeVectorStore($name);

    $store->upsert([passage($this->documentId, 0, [1.0, 0.0, 0.0], 'embedded with another model')]);

    expect($store->search([1.0, 0.0], topK: 1)[0]->score)->toBe(0.0);
})->with('vector stores');
