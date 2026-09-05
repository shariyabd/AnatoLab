<?php

declare(strict_types=1);

use App\Services\Rag\ChunkingService;
use App\Services\Rag\TextChunk;

/*
| Chunk boundaries and overlap quietly decide answer quality: a definition split
| across two chunks is retrievable from neither, and a chunk that lost its
| metadata is invisible to every filtered search (PRD §28).
|
| ChunkingService is pure, so all of it is testable with a string and a config
| value — no document, no provider, no database.
*/

beforeEach(function (): void {
    // Small numbers so a boundary is a few sentences away rather than a page.
    // The production defaults (375 / 38) are asserted separately below.
    config()->set('ai.knowledge.chunk_words', 10);
    config()->set('ai.knowledge.chunk_overlap_words', 3);

    $this->chunker = new ChunkingService;
});

it('returns nothing for text with no words in it', function (string $text): void {
    expect((new ChunkingService)->chunk($text))->toBe([]);
})->with(['', '   ', "\n\n\t\n"]);

it('keeps a document shorter than the target in a single chunk', function (): void {
    $chunks = $this->chunker->chunk('The aorta leaves the left ventricle.');

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0])->toBeInstanceOf(TextChunk::class)
        ->and($chunks[0]->index)->toBe(0)
        ->and($chunks[0]->content)->toBe('The aorta leaves the left ventricle.');
});

it('splits once the target is exceeded and numbers chunks from zero', function (): void {
    // Six words each, so the second sentence crosses the ten-word target.
    $chunks = $this->chunker->chunk('One two three four five six. Seven eight nine ten eleven twelve.');

    expect($chunks)->toHaveCount(2)
        ->and(array_map(static fn (TextChunk $chunk): int => $chunk->index, $chunks))->toBe([0, 1])
        ->and($chunks[0]->content)->toBe('One two three four five six.')
        ->and($chunks[1]->content)->toBe('Seven eight nine ten eleven twelve.');
});

it('overlaps whole sentences so a boundary is never mid-thought', function (): void {
    $text = 'Alpha one two three. Beta four five six. Gamma seven eight nine. Delta ten eleven twelve.';

    $chunks = $this->chunker->chunk($text);

    // The trailing sentence of one chunk opens the next, so a definition that
    // straddled the boundary is whole in at least one of them.
    expect(count($chunks))->toBeGreaterThan(1)
        ->and($chunks[1]->content)->toStartWith('Beta four five six.')
        ->and($chunks[0]->content)->toEndWith('Beta four five six.');
});

it('never starts a chunk in the middle of a sentence', function (): void {
    $text = implode(' ', array_map(
        static fn (int $i): string => "Sentence number {$i} runs to here.",
        range(1, 12),
    ));

    foreach ($this->chunker->chunk($text) as $chunk) {
        expect($chunk->content)->toStartWith('Sentence number');
    }
});

it('hard-splits a single sentence longer than the whole target', function (): void {
    // Twenty-five words with no full stop: a heading, a table row, or a badly
    // OCR'd page. Left whole it would blow the embedding model's input limit.
    $sentence = implode(' ', array_map(static fn (int $i): string => "word{$i}", range(1, 25)));

    $chunks = $this->chunker->chunk($sentence);

    expect(count($chunks))->toBeGreaterThan(1)
        ->and($chunks[0]->wordCount)->toBeLessThanOrEqual(10);
});

it('treats a paragraph break as a boundary rather than fusing across it', function (): void {
    // A heading has no full stop. Without the paragraph rule it would fuse onto
    // the paragraph below and the pair would then be hard-split mid-phrase.
    $chunks = $this->chunker->chunk(
        "A heading with no full stop\n\nThe paragraph beneath it carries the actual explanation of the structure."
    );

    expect($chunks[0]->content)->toBe('A heading with no full stop');
});

it('copies the document metadata onto every chunk', function (): void {
    $metadata = [
        'organ_id' => 7,
        'structure_id' => null,
        'education_level' => 'high_school',
        'content_type' => 'function',
        'source_title' => 'Cardiac anatomy',
    ];

    $chunks = $this->chunker->chunk(
        'Alpha one two three. Beta four five six. Gamma seven eight nine. Delta ten eleven twelve.',
        $metadata,
    );

    // A chunk that lost these is retrievable only by an unfiltered search,
    // which is to say never (PRD §28).
    expect(count($chunks))->toBeGreaterThan(1);

    foreach ($chunks as $chunk) {
        expect($chunk->metadata)->toBe($metadata);
    }
});

it('reports the word count it actually produced', function (): void {
    $chunks = $this->chunker->chunk('One two three four five six.');

    expect($chunks[0]->wordCount)->toBe(6);
});

it('cannot loop forever when the overlap is configured at or above the target', function (): void {
    config()->set('ai.knowledge.chunk_overlap_words', 999);

    $chunks = (new ChunkingService)->chunk(
        'Alpha one two three. Beta four five six. Gamma seven eight nine. Delta ten eleven twelve.'
    );

    // Clamped to target - 1, so every chunk still makes progress.
    expect($chunks)->not->toBeEmpty();
});

it('targets roughly 500 tokens with 50 of overlap by default', function (): void {
    // ~0.75 words per token (docs/architecture.md §8.3). Read from the file
    // rather than from the container, because beforeEach above has overridden
    // the live values. Asserted so a later edit cannot quietly halve the
    // retrieval unit and change every embedding's meaning.
    $defaults = require base_path('config/ai.php');

    expect($defaults['knowledge']['chunk_words'])->toBe(375)
        ->and($defaults['knowledge']['chunk_overlap_words'])->toBe(38);
});
