<?php

declare(strict_types=1);

use App\Contracts\VectorStoreInterface;
use App\Infrastructure\VectorStore\MySqlVectorStore;
use App\Models\AnatomicalStructure;
use App\Models\ConversationMessage;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Organ;
use App\Models\User;
use App\Services\Rag\EmbeddingService;

/*
| The retrieval seam, end to end (docs/handovers/08-retrieval-seam.md).
|
| Handover 08 built the "no relevant sources, and saying so" path and every
| downstream consumer of a non-empty source list. This asserts both halves still
| hold now that retrieval is live: a grounded answer cites, and an ungrounded one
| still says it is ungrounded.
|
| Acceptance criterion 2.
*/

beforeEach(function (): void {
    // The default store in production, rather than the in-memory one. The seam
    // is only proved if it is proved against what an installation runs.
    app()->singleton(VectorStoreInterface::class, fn (): VectorStoreInterface => new MySqlVectorStore);

    $this->student = User::factory()->create();
    $this->actingAs($this->student);

    $this->organ = Organ::factory()->published()->create(['slug' => 'heart', 'name' => 'Heart']);
    $this->structure = AnatomicalStructure::factory()->published()->for($this->organ)->create([
        'name' => 'Left ventricle',
        'function' => 'Pumps oxygenated blood into the aorta.',
    ]);

    $this->document = KnowledgeDocument::factory()->create(['title' => 'Open anatomy reference']);
});

/**
 * Index one passage against the organ and structure on screen.
 */
function groundOn(int $documentId, int $index, string $content, array $metadata): void
{
    app(VectorStoreInterface::class)->upsert([[
        'id' => KnowledgeChunk::vectorIdFor($documentId, $index),
        'vector' => app(EmbeddingService::class)->embed($content),
        'content' => $content,
        'metadata' => ['source_title' => 'Open anatomy reference', ...$metadata],
    ]]);
}

it('cites a real source, and the source is the one that reached the prompt', function (): void {
    // NullProvider's embeddings are a deterministic hash, not a semantic model,
    // so a passage is "relevant" here only if it is the same text. Relevance
    // ranking itself is asserted in RetrievalServiceTest; what matters here is
    // that a retrieved chunk travels the whole way to the student.
    config()->set('ai.retrieval.min_score', -1.0);

    groundOn($this->document->getKey(), 0, 'The left ventricle wall is three times the thickness of the right.', [
        'organ_id' => (int) $this->organ->getKey(),
        'structure_id' => (int) $this->structure->getKey(),
        'education_level' => $this->student->education_level->value,
    ]);

    $response = $this->postJson('/api/v1/ai/tutor/ask', [
        'question' => 'Why is its wall thicker?',
        'structureId' => $this->structure->getKey(),
    ])->assertOk();

    expect($response->json('data.sources'))->toHaveCount(1)
        ->and($response->json('data.sources.0.title'))->toBe('Open anatomy reference')
        ->and($response->json('data.sources.0.excerpt'))->toContain('three times the thickness')
        // Once there are citations, the citations are the statement: the "no
        // indexed source" note is for answers that have none.
        ->and($response->json('data.sourceNote'))->toBeNull()
        // NullProvider echoes the user turn, so the passage appearing in the
        // answer is evidence the source actually reached the prompt rather than
        // merely being listed beside it.
        ->and($response->json('data.answer'))->toContain('three times the thickness');
});

it('records the retrieved chunk ids on the stored turn so a citation can be rebuilt', function (): void {
    config()->set('ai.retrieval.min_score', -1.0);

    groundOn($this->document->getKey(), 0, 'The left ventricle wall is thick.', [
        'organ_id' => (int) $this->organ->getKey(),
        'structure_id' => (int) $this->structure->getKey(),
        'education_level' => $this->student->education_level->value,
    ]);

    $conversationId = $this->postJson('/api/v1/ai/tutor/ask', [
        'question' => 'Why is its wall thicker?',
        'structureId' => $this->structure->getKey(),
    ])->assertOk()->json('data.conversationId');

    $assistantTurn = ConversationMessage::query()
        ->where('conversation_id', $conversationId)
        ->where('role', 'assistant')
        ->firstOrFail();

    // The key Handover 08 shipped empty is now filled (docs/architecture.md §8.2).
    expect($assistantTurn->metadata['source_ids'])
        ->toBe([KnowledgeChunk::vectorIdFor((int) $this->document->getKey(), 0)]);
});

it('still says it had no sources when nothing clears the threshold', function (): void {
    // Indexed, but not relevant. This is the distinction the source note
    // depends on, and it is why the threshold lives in RetrievalService rather
    // than in the seam.
    groundOn($this->document->getKey(), 0, 'Alveoli exchange gas with the pulmonary capillaries.', [
        'organ_id' => (int) $this->organ->getKey(),
        'structure_id' => (int) $this->structure->getKey(),
        'education_level' => $this->student->education_level->value,
    ]);

    $response = $this->postJson('/api/v1/ai/tutor/ask', [
        'question' => 'Why is its wall thicker?',
        'structureId' => $this->structure->getKey(),
    ])->assertOk();

    expect($response->json('data.sources'))->toBe([])
        ->and($response->json('data.sourceNote'))
        ->toContain('No indexed source')
        ->toContain('Left ventricle')
        ->toContain('curated notes');
});

it('still says it had no sources when the corpus is empty', function (): void {
    config()->set('ai.retrieval.min_score', -1.0);

    // Nothing indexed at all: the path Handover 08 built and this lane inherits
    // rather than reinvents (PRD §24).
    $response = $this->postJson('/api/v1/ai/tutor/ask', ['question' => 'What is an artery?'])
        ->assertOk();

    expect($response->json('data.sources'))->toBe([])
        ->and($response->json('data.sourceNote'))
        ->toContain('No indexed source')
        ->toContain('well-established anatomy');
});

it('does not cite a source indexed for another organ', function (): void {
    config()->set('ai.retrieval.min_score', -1.0);

    $lungs = Organ::factory()->published()->create(['slug' => 'lungs', 'name' => 'Lungs']);

    groundOn($this->document->getKey(), 0, 'Alveoli exchange gas with the pulmonary capillaries.', [
        'organ_id' => (int) $lungs->getKey(),
        'education_level' => $this->student->education_level->value,
    ]);

    $response = $this->postJson('/api/v1/ai/tutor/ask', [
        'question' => 'Why is its wall thicker?',
        'structureId' => $this->structure->getKey(),
    ])->assertOk();

    // The filter set is assembled from the resolved organ and structure, so a
    // lung passage cannot be cited under a heart question however well it scores.
    expect($response->json('data.sources'))->toBe([])
        ->and($response->json('data.answer'))->not->toContain('Alveoli');
});

it('keeps the answer free of a knowledge-base failure when the store is down', function (): void {
    app()->singleton(VectorStoreInterface::class, fn (): VectorStoreInterface => new class implements VectorStoreInterface
    {
        public function upsert(array $documents): void {}

        public function search(array $queryVector, int $topK = 5, array $filters = []): array
        {
            throw App\Exceptions\VectorStoreException::unavailable('pinecone');
        }

        public function delete(array $ids): void {}
    });

    $response = $this->postJson('/api/v1/ai/tutor/ask', ['question' => 'What is an artery?'])
        ->assertOk();

    $body = json_encode($response->json(), JSON_THROW_ON_ERROR);

    // A retrieval outage degrades to an ungrounded answer. It never becomes an
    // error page, and it never names the store (docs/architecture.md §14).
    expect($response->json('data.sources'))->toBe([])
        ->and($response->json('data.sourceNote'))->toContain('No indexed source')
        ->and($body)->not->toContain('pinecone');
});
