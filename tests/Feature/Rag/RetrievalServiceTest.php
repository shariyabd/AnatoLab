<?php

declare(strict_types=1);

use App\Contracts\AIProviderInterface;
use App\Contracts\AIResponse;
use App\Contracts\RetrievedChunk;
use App\Contracts\VectorStoreInterface;
use App\Exceptions\AIProviderException;
use App\Exceptions\VectorStoreException;
use App\Infrastructure\VectorStore\MySqlVectorStore;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Organ;
use App\Services\Rag\EmbeddingService;
use App\Services\Rag\RetrievalService;
use Illuminate\Support\Facades\Log;

/*
| Retrieval (docs/architecture.md §8.1, §8.2, PRD §28).
|
| Run against MySqlVectorStore, because that is the default an installation
| actually uses. Embeddings come from NullProvider, which is deterministic —
| that is what lets these tests assert an order and a threshold rather than
| merely asserting that something came back.
*/

beforeEach(function (): void {
    $this->store = new MySqlVectorStore;
    $this->embeddings = app(EmbeddingService::class);
    $this->retrieval = new RetrievalService($this->embeddings, $this->store);

    $this->document = KnowledgeDocument::factory()->create(['title' => 'Open anatomy reference']);
    $this->heart = (int) Organ::factory()->published()->create(['slug' => 'heart'])->getKey();
    $this->lungs = (int) Organ::factory()->published()->create(['slug' => 'lungs'])->getKey();

    // Cosine runs from -1 to 1, so -1 is "no threshold at all". Off by default
    // here so a test asserting *filtering* is not quietly asserting the
    // threshold as well; the threshold has its own tests below.
    config()->set('ai.retrieval.min_score', -1.0);
});

/**
 * Index one passage the way SyncVectorStore would.
 *
 * @param  array<string, scalar|null>  $metadata
 */
function indexPassage(int $documentId, int $index, string $content, array $metadata = []): void
{
    (new MySqlVectorStore)->upsert([[
        'id' => KnowledgeChunk::vectorIdFor($documentId, $index),
        'vector' => app(EmbeddingService::class)->embed($content),
        'content' => $content,
        'metadata' => ['source_title' => 'Open anatomy reference', ...$metadata],
    ]]);
}

it('narrows to the selected organ, so a heart question never cites a lung source', function (): void {
    indexPassage($this->document->getKey(), 0, 'The left ventricle wall is thick.', ['organ_id' => $this->heart]);
    indexPassage($this->document->getKey(), 1, 'Alveoli exchange gas with capillaries.', ['organ_id' => $this->lungs]);

    $results = $this->retrieval->search(
        'Why is the wall thick?',
        topK: 5,
        filters: ['organ_id' => $this->heart],
    );

    expect($results)->toHaveCount(1)
        ->and($results[0]->content)->toContain('left ventricle')
        ->and($results[0]->metadata['organ_id'])->toBe($this->heart);
});

it('narrows by education level as well, so a filter set composes', function (): void {
    indexPassage($this->document->getKey(), 0, 'A simple explanation.', [
        'organ_id' => $this->heart,
        'education_level' => 'middle_school',
    ]);
    indexPassage($this->document->getKey(), 1, 'A detailed explanation.', [
        'organ_id' => $this->heart,
        'education_level' => 'high_school',
    ]);

    $results = $this->retrieval->search('Explain it', topK: 5, filters: [
        'organ_id' => $this->heart,
        'education_level' => 'high_school',
    ]);

    expect($results)->toHaveCount(1)
        ->and($results[0]->content)->toBe('A detailed explanation.');
});

it('drops matches below the relevance threshold', function (): void {
    $question = 'Why is the left ventricle wall thick?';

    // The first passage embeds to exactly the query vector, the second to
    // something else. Only the first can clear a threshold this high.
    indexPassage($this->document->getKey(), 0, $question);
    indexPassage($this->document->getKey(), 1, 'Alveoli exchange gas with capillaries.');

    config()->set('ai.retrieval.min_score', 0.99);

    $results = $this->retrieval->search($question, topK: 5);

    expect($results)->toHaveCount(1)
        ->and($results[0]->content)->toBe($question);
});

it('returns nothing at all when no passage clears the threshold', function (): void {
    indexPassage($this->document->getKey(), 0, 'Alveoli exchange gas with capillaries.');

    config()->set('ai.retrieval.min_score', 0.999);

    // Empty here means "nothing was relevant", which is what lets the tutor
    // say so honestly rather than citing a weak match (PRD §24).
    expect($this->retrieval->search('Why is the left ventricle wall thick?'))->toBe([]);
});

it('honours topK', function (): void {
    foreach (range(0, 7) as $index) {
        indexPassage($this->document->getKey(), $index, "Passage number {$index} about the heart.");
    }

    expect($this->retrieval->search('the heart', topK: 3))->toHaveCount(3);
});

it('asks nothing of the store for an empty question', function (): void {
    indexPassage($this->document->getKey(), 0, 'The left ventricle wall is thick.');

    expect($this->retrieval->search('   '))->toBe([])
        ->and($this->retrieval->search('a real question', topK: 0))->toBe([]);
});

it('answers without sources rather than throwing when the store is unreachable', function (): void {
    Log::spy();

    $broken = new class implements VectorStoreInterface
    {
        public function upsert(array $documents): void {}

        public function search(array $queryVector, int $topK = 5, array $filters = []): array
        {
            throw VectorStoreException::unavailable('pinecone');
        }

        public function delete(array $ids): void {}
    };

    // A knowledge-base outage must not become a broken tutor: the student still
    // gets taught, and the tutor still says the answer was not sourced.
    expect((new RetrievalService($this->embeddings, $broken))->search('anything'))->toBe([]);

    Log::shouldHaveReceived('warning')->once();
});

it('answers without sources when the configured provider cannot embed', function (): void {
    Log::spy();

    // AnthropicProvider::generateEmbeddings() throws — Anthropic publishes no
    // embeddings endpoint (docs/handovers/08-retrieval-seam.md §5).
    $cannotEmbed = new class implements AIProviderInterface
    {
        public function chat(array $messages, array $options = []): AIResponse
        {
            throw AIProviderException::unavailable('anthropic');
        }

        public function generateEmbedding(string $text): array
        {
            throw AIProviderException::unavailable('anthropic');
        }

        public function generateEmbeddings(array $texts): array
        {
            throw AIProviderException::unavailable('anthropic');
        }
    };

    $retrieval = new RetrievalService(new EmbeddingService($cannotEmbed), $this->store);

    expect($retrieval->search('Why is the left ventricle wall thick?'))->toBe([]);

    Log::shouldHaveReceived('warning')->once();
});

it('returns chunks that can be cited', function (): void {
    indexPassage($this->document->getKey(), 0, 'The left ventricle wall is thick.');

    $results = $this->retrieval->search('Why is the wall thick?');

    expect($results[0])->toBeInstanceOf(RetrievedChunk::class)
        ->and($results[0]->sourceTitle)->toBe('Open anatomy reference')
        ->and($results[0]->id)->toBe(KnowledgeChunk::vectorIdFor((int) $this->document->getKey(), 0));
});
