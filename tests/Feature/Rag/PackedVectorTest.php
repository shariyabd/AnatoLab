<?php

declare(strict_types=1);

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;

/*
| The embedding column is packed little-endian float32, not JSON: a 1536-
| dimension vector is 6 KB rather than ~36 KB, and MySqlVectorStore reads one of
| these for every row it scores. If the round trip were lossy or ordered
| differently, every similarity score would be quietly wrong.
*/

it('round-trips a vector through the packed column', function (): void {
    $vector = [0.5, -0.25, 0.125, 0.0, 1.0];

    $chunk = KnowledgeChunk::factory()->create([
        'document_id' => KnowledgeDocument::factory(),
        'embedding' => $vector,
    ]);

    // Read back from the database, not from the in-memory model.
    expect($chunk->fresh()->embedding)->toBe($vector);
});

it('preserves order and float32 precision, which is what a dot product needs', function (): void {
    $vector = array_map(static fn (int $i): float => $i / 100, range(1, 64));

    $chunk = KnowledgeChunk::factory()->create(['embedding' => $vector]);

    $read = $chunk->fresh()->embedding;

    // float32, so a value that is not exact in binary comes back rounded to
    // about seven significant digits. Cosine similarity over unit vectors needs
    // roughly six, and halving the blob halves the bytes every search reads.
    expect($read)->toHaveCount(64);

    foreach ($vector as $index => $component) {
        expect($read[$index])->toEqualWithDelta($component, 1e-6);
    }
});

it('carries a full-width embedding without truncating it', function (): void {
    $vector = array_fill(0, 1536, 0.125);

    $chunk = KnowledgeChunk::factory()->create(['embedding' => $vector]);

    expect($chunk->fresh()->embedding)->toHaveCount(1536);
});

it('treats an absent embedding as null rather than an empty vector', function (): void {
    // A chunk written by ProcessKnowledgeDocument has no vector yet. MySql
    // VectorStore filters those out with whereNotNull, so the distinction
    // between "not embedded" and "embedded to nothing" has to survive.
    $chunk = KnowledgeChunk::factory()->create(['embedding' => null]);

    expect($chunk->fresh()->embedding)->toBeNull();

    $chunk->embedding = [];
    $chunk->save();

    expect($chunk->fresh()->embedding)->toBeNull();
});
