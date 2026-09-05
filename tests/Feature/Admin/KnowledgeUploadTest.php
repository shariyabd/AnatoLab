<?php

declare(strict_types=1);

use App\Enums\KnowledgeDocumentStatus;
use App\Jobs\ProcessKnowledgeDocument;
use App\Models\KnowledgeDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
| Knowledge document upload (PRD §41, docs/engineering.md §10).
|
| The rule being tested is that a rejected file is **not stored**. Validation
| that runs after the bytes are written is not upload validation, it is
| cleanup.
*/

beforeEach(function (): void {
    Queue::fake();
    Storage::fake('local');

    $this->admin = User::factory()->admin()->create();

    $this->payload = [
        'title' => 'Cardiac anatomy',
        'source' => 'Gray’s Anatomy, 42nd ed.',
        'source_type' => 'textbook',
        'education_level' => 'high_school',
    ];
});

it('stores an accepted upload under a generated name and queues the ingest', function (): void {
    $this->actingAs($this->admin)
        ->post('/admin/knowledge', [
            ...$this->payload,
            'document' => UploadedFile::fake()->createWithContent('notes.txt', 'The heart has four chambers.'),
        ])
        ->assertRedirect();

    $document = KnowledgeDocument::query()->sole();

    expect($document->status)->toBe(KnowledgeDocumentStatus::Pending)
        // The client's filename is kept as a label only. The stored name is
        // generated, because an uploaded filename is attacker-controlled.
        ->and($document->original_filename)->toBe('notes.txt')
        ->and($document->storage_path)->not->toContain('notes.txt')
        ->and($document->storage_path)->toStartWith('knowledge/');

    Storage::disk('local')->assertExists($document->storage_path);

    Queue::assertPushed(ProcessKnowledgeDocument::class);
});

it('does not store a file with a rejected extension', function (): void {
    $this->actingAs($this->admin)
        ->post('/admin/knowledge', [
            ...$this->payload,
            'document' => UploadedFile::fake()->create('payload.php', 4, 'text/plain'),
        ])
        ->assertSessionHasErrors('document');

    expect(KnowledgeDocument::query()->count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBeEmpty();

    Queue::assertNothingPushed();
});

it('does not store a file with a rejected mime type', function (): void {
    $this->actingAs($this->admin)
        ->post('/admin/knowledge', [
            ...$this->payload,
            'document' => UploadedFile::fake()->create('scan.pdf', 16, 'application/pdf'),
        ])
        ->assertSessionHasErrors('document');

    expect(KnowledgeDocument::query()->count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('does not store a file over the size cap', function (): void {
    $cap = (int) config('ai.knowledge.max_upload_kilobytes');

    $this->actingAs($this->admin)
        ->post('/admin/knowledge', [
            ...$this->payload,
            'document' => UploadedFile::fake()->create('huge.txt', $cap + 1024, 'text/plain'),
        ])
        ->assertSessionHasErrors('document');

    expect(KnowledgeDocument::query()->count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('accepts pasted text as an alternative to a file', function (): void {
    $this->actingAs($this->admin)
        ->post('/admin/knowledge', [...$this->payload, 'text' => 'The aorta leaves the left ventricle.'])
        ->assertRedirect();

    expect(KnowledgeDocument::query()->sole()->mime_type)->toBe('text/plain');

    Queue::assertPushed(ProcessKnowledgeDocument::class);
});

it('requires exactly one of a file or pasted text', function (): void {
    // Neither.
    $this->actingAs($this->admin)
        ->post('/admin/knowledge', $this->payload)
        ->assertSessionHasErrors('document');

    // Both.
    $this->actingAs($this->admin)
        ->post('/admin/knowledge', [
            ...$this->payload,
            'text' => 'Some text',
            'document' => UploadedFile::fake()->createWithContent('notes.txt', 'More text'),
        ])
        ->assertSessionHasErrors('document');

    expect(KnowledgeDocument::query()->count())->toBe(0);
});

it('re-indexes a document by bumping its version', function (): void {
    $document = KnowledgeDocument::factory()->create([
        'status' => KnowledgeDocumentStatus::Failed,
        'version' => 1,
    ]);

    $this->actingAs($this->admin)
        ->post("/admin/knowledge/{$document->id}/reingest")
        ->assertRedirect();

    expect($document->refresh()->version)->toBe(2);

    Queue::assertPushed(ProcessKnowledgeDocument::class);
});

it('reports ingestion status on the index', function (): void {
    // Acceptance criterion 4: a document uploads and reaches `indexed`.
    KnowledgeDocument::factory()->create([
        'title' => 'Indexed source',
        'status' => KnowledgeDocumentStatus::Indexed,
    ]);

    $props = $this->actingAs($this->admin)
        ->get('/admin/knowledge')
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['documents']['data'][0]['status'])->toBe('indexed');
});

it('never puts the private storage path in a page prop', function (): void {
    // It is a path on a disk deliberately outside the web root.
    KnowledgeDocument::factory()->create(['storage_path' => 'knowledge/secret-uuid.txt']);

    $props = $this->actingAs($this->admin)->get('/admin/knowledge')->viewData('page')['props'];

    expect(json_encode($props, JSON_THROW_ON_ERROR))->not->toContain('secret-uuid');
});
