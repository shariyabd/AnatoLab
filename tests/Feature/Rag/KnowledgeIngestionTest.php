<?php

declare(strict_types=1);

use App\Contracts\VectorStoreInterface;
use App\Enums\KnowledgeDocumentStatus;
use App\Enums\KnowledgeSourceType;
use App\Jobs\GenerateEmbeddings;
use App\Jobs\ProcessKnowledgeDocument;
use App\Jobs\SyncVectorStore;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Organ;
use App\Services\Rag\EmbeddingService;
use App\Services\Rag\KnowledgeDocumentData;
use App\Services\Rag\KnowledgeService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
| Ingestion (docs/architecture.md §8.3).
|
| Two things are being proved: nothing in the chain runs inside the call that
| accepts the upload, and a document that has been through the chain is
| genuinely retrievable. Acceptance criterion 1.
*/

beforeEach(function (): void {
    Storage::fake('local');

    config()->set('ai.knowledge.chunk_words', 12);
    config()->set('ai.knowledge.chunk_overlap_words', 4);

    $this->knowledge = app(KnowledgeService::class);

    $this->details = new KnowledgeDocumentData(
        title: 'Cardiac anatomy',
        source: 'Open anatomy reference, 3rd edition',
        sourceType: KnowledgeSourceType::Reference,
        educationLevel: 'high_school',
    );
});

function markdown(string $name = 'cardiac.md'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, <<<'TEXT'
        The left ventricle has the thickest wall of the four chambers.

        It pumps oxygenated blood into the aorta and on to the whole body,
        against a much higher resistance than the right ventricle faces.
        TEXT);
}

it('returns before anything is processed and leaves the work on the ingest queue', function (): void {
    Queue::fake();

    $document = $this->knowledge->storeUpload(markdown(), $this->details);

    // The request is done here. A student asking a question while an admin
    // uploads a textbook must not wait behind the textbook.
    expect($document->status)->toBe(KnowledgeDocumentStatus::Pending)
        ->and($document->chunks()->count())->toBe(0);

    Queue::assertPushedOn('ingest', ProcessKnowledgeDocument::class);
    Queue::assertNotPushed(GenerateEmbeddings::class);
    Queue::assertNotPushed(SyncVectorStore::class);
});

it('stores the file outside the web root under a generated name', function (): void {
    Queue::fake();

    $document = $this->knowledge->storeUpload(markdown('Gray\'s notes.md'), $this->details);

    Storage::disk('local')->assertExists((string) $document->storage_path);

    // The uploaded name is kept as a label and never used as a path: it is
    // attacker-controlled (docs/engineering.md §10).
    expect($document->storage_path)->toStartWith('knowledge/')
        ->not->toContain('Gray')
        ->and($document->original_filename)->toBe('Gray\'s notes.md');
});

it('rejects a file whose real type is not the one its extension claims', function (): void {
    Queue::fake();

    $file = UploadedFile::fake()->create('notes.txt', 1)->mimeType('application/pdf');

    expect(fn (): KnowledgeDocument => $this->knowledge->storeUpload($file, $this->details))
        ->toThrow(ValidationException::class);

    Queue::assertNothingPushed();
});

it('rejects an extension it has no parser for, whatever the type says', function (): void {
    Queue::fake();

    $file = UploadedFile::fake()->create('slides.pptx', 1)->mimeType('text/plain');

    expect(fn (): KnowledgeDocument => $this->knowledge->storeUpload($file, $this->details))
        ->toThrow(ValidationException::class);
});

it('rejects a file over the size cap', function (): void {
    Queue::fake();

    config()->set('ai.knowledge.max_upload_kilobytes', 4);

    $file = UploadedFile::fake()->create('huge.txt', 16)->mimeType('text/plain');

    expect(fn (): KnowledgeDocument => $this->knowledge->storeUpload($file, $this->details))
        ->toThrow(ValidationException::class);
});

it('runs the whole chain and leaves the document indexed and retrievable', function (): void {
    // QUEUE_CONNECTION is `sync` in phpunit.xml, so dispatching runs the chain
    // end to end here — the same three jobs, in the same order.
    $document = $this->knowledge->storeUpload(markdown(), $this->details);

    $document->refresh();

    expect($document->status)->toBe(KnowledgeDocumentStatus::Indexed)
        ->and($document->chunks()->count())->toBeGreaterThan(0);

    $chunk = $document->chunks()->first();

    expect($chunk)->not->toBeNull()
        ->and($chunk->embedding)->toBeArray()
        ->and($chunk->embedding_reference)->toBe($chunk->vectorId())
        ->and($chunk->education_level)->toBe('high_school');

    // Retrievable, not merely stored: the same question a student would ask.
    $store = app(VectorStoreInterface::class);
    $vector = app(EmbeddingService::class)->embed('Why is the left ventricle wall thick?');

    expect($store->search($vector, topK: 5))->not->toBeEmpty();
});

it('inherits the document filters onto every chunk', function (): void {
    $organ = Organ::factory()->published()->create(['slug' => 'heart']);

    $document = $this->knowledge->storeUpload(markdown(), new KnowledgeDocumentData(
        title: 'Cardiac anatomy',
        source: 'Open anatomy reference',
        sourceType: KnowledgeSourceType::Reference,
        organId: (int) $organ->getKey(),
        educationLevel: 'middle_school',
        contentType: 'function',
    ));

    foreach ($document->chunks()->get() as $chunk) {
        expect($chunk->organ_id)->toBe((int) $organ->getKey())
            ->and($chunk->education_level)->toBe('middle_school')
            ->and($chunk->content_type)->toBe('function')
            // The citation travels with the chunk, so a store that keeps its
            // vectors elsewhere can still attribute the passage (PRD §12).
            ->and($chunk->metadata['source_title'])->toBe('Cardiac anatomy');
    }
});

it('replaces the previous version rather than appending to it', function (): void {
    $document = $this->knowledge->storeUpload(markdown(), $this->details);

    $first = $document->chunks()->count();

    $this->knowledge->reingest($document->refresh());

    $document->refresh();

    expect($document->version)->toBe(2)
        ->and($document->status)->toBe(KnowledgeDocumentStatus::Indexed)
        // Same text, same count: a second pass must not double the corpus.
        ->and($document->chunks()->count())->toBe($first)
        ->and(KnowledgeChunk::query()->count())->toBe($first);
});

it('records why a document could not be indexed instead of leaving it processing', function (): void {
    $document = $this->knowledge->storeUpload(markdown(), $this->details);

    // A document whose text is gone: the file was cleaned up, or the disk it
    // lived on was replaced.
    Storage::disk('local')->delete((string) $document->storage_path);

    try {
        (new ProcessKnowledgeDocument((int) $document->getKey()))->handle(
            $this->knowledge,
            app(App\Services\Rag\ChunkingService::class),
        );
    } catch (RuntimeException $exception) {
        (new ProcessKnowledgeDocument((int) $document->getKey()))->failed($exception);
    }

    $document->refresh();

    expect($document->status)->toBe(KnowledgeDocumentStatus::Failed)
        ->and($document->metadata['failure_reason'])->toContain('no stored file');
});

it('fails a document that holds no indexable text', function (): void {
    $document = KnowledgeDocument::factory()->create();
    $document->storage_path = 'knowledge/blank.txt';
    $document->save();

    Storage::disk('local')->put('knowledge/blank.txt', "\x07\x0B\x0C");

    (new ProcessKnowledgeDocument((int) $document->getKey()))->handle(
        $this->knowledge,
        app(App\Services\Rag\ChunkingService::class),
    );

    $document->refresh();

    expect($document->status)->toBe(KnowledgeDocumentStatus::Failed)
        ->and($document->metadata['failure_reason'])->toContain('no indexable text');
});

it('removes the file, the chunks and the vectors together', function (): void {
    $document = $this->knowledge->storeUpload(markdown(), $this->details);
    $path = (string) $document->storage_path;

    $this->knowledge->delete($document);

    Storage::disk('local')->assertMissing($path);

    expect(KnowledgeDocument::query()->count())->toBe(0)
        ->and(KnowledgeChunk::query()->count())->toBe(0)
        // An answer citing a deleted source is worse than a stale row.
        ->and(app(VectorStoreInterface::class)->search([1.0, 0.0], topK: 5))->toBe([]);
});
