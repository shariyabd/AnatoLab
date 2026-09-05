<?php

declare(strict_types=1);

namespace App\Services\Rag;

/**
 * Splits a document's text into overlapping, retrievable passages
 * (docs/architecture.md §8.3).
 *
 * Pure: text in, TextChunk out, no database and no provider. That is what lets
 * the boundary and overlap rules — the part of RAG that quietly decides answer
 * quality — be tested exhaustively without fixtures.
 *
 * Sentences are the unit, not words. A chunk that begins mid-sentence reads as
 * broken when it is quoted back to a student as a citation, and an embedding of
 * half a sentence is a worse match for the question it should have answered.
 * Word counts therefore land near the target rather than exactly on it.
 */
final class ChunkingService
{
    /**
     * Split $text into chunks of roughly `ai.knowledge.chunk_words` words with
     * `ai.knowledge.chunk_overlap_words` words of trailing overlap.
     *
     * $metadata is copied onto every chunk unchanged: the retrieval filters are
     * a property of the source document, and a chunk that lost them would be
     * invisible to every filtered search (PRD §28).
     *
     * @param  array<string, scalar|null>  $metadata
     * @return list<TextChunk>
     */
    public function chunk(string $text, array $metadata = []): array
    {
        $sentences = $this->sentences($text);

        if ($sentences === []) {
            return [];
        }

        $target = max(1, (int) config('ai.knowledge.chunk_words', 375));
        $overlap = max(0, min(
            (int) config('ai.knowledge.chunk_overlap_words', 38),
            // An overlap at or above the target would make every chunk start
            // where the last one did and never terminate.
            $target - 1,
        ));

        $chunks = [];
        /** @var list<array{text: string, words: int}> $current */
        $current = [];
        $words = 0;

        foreach ($sentences as $sentence) {
            if ($current !== [] && $words + $sentence['words'] > $target) {
                $chunks[] = $this->assemble($current, count($chunks), $metadata);
                $current = $this->overlapTail($current, $overlap);
                $words = array_sum(array_column($current, 'words'));
            }

            $current[] = $sentence;
            $words += $sentence['words'];
        }

        // Always non-empty here: $sentences is non-empty and every iteration
        // appends, so there is a final partial chunk to flush.
        $chunks[] = $this->assemble($current, count($chunks), $metadata);

        return $chunks;
    }

    /**
     * @param  list<array{text: string, words: int}>  $sentences
     * @param  array<string, scalar|null>  $metadata
     */
    private function assemble(array $sentences, int $index, array $metadata): TextChunk
    {
        return new TextChunk(
            index: $index,
            content: implode(' ', array_column($sentences, 'text')),
            wordCount: (int) array_sum(array_column($sentences, 'words')),
            metadata: $metadata,
        );
    }

    /**
     * The trailing sentences that also open the next chunk.
     *
     * Takes whole sentences from the end until the overlap budget is met, so
     * the repeated text is always readable. Never returns the entire chunk: a
     * short chunk made only of one long sentence would otherwise repeat itself
     * forever.
     *
     * @param  list<array{text: string, words: int}>  $sentences
     * @return list<array{text: string, words: int}>
     */
    private function overlapTail(array $sentences, int $overlap): array
    {
        if ($overlap === 0 || count($sentences) < 2) {
            return [];
        }

        $tail = [];
        $words = 0;

        foreach (array_reverse($sentences) as $sentence) {
            if (count($tail) === count($sentences) - 1) {
                break;
            }

            array_unshift($tail, $sentence);
            $words += $sentence['words'];

            if ($words >= $overlap) {
                break;
            }
        }

        return $tail;
    }

    /**
     * Break text into sentences, with the word count each one costs.
     *
     * Paragraph breaks are treated as sentence breaks so a heading or a list
     * item never fuses onto the paragraph after it. A sentence longer than the
     * chunk target is hard-split on words — rare, and the alternative is a
     * single chunk that blows the embedding model's input limit.
     *
     * @return list<array{text: string, words: int}>
     */
    private function sentences(string $text): array
    {
        $target = max(1, (int) config('ai.knowledge.chunk_words', 375));
        $sentences = [];

        foreach (preg_split('/\R{2,}/u', trim($text)) ?: [] as $paragraph) {
            foreach (preg_split('/(?<=[.!?])\s+/u', trim($paragraph)) ?: [] as $candidate) {
                foreach ($this->splitOversized($candidate, $target) as $sentence) {
                    $words = $this->countWords($sentence);

                    if ($words > 0) {
                        $sentences[] = ['text' => $sentence, 'words' => $words];
                    }
                }
            }
        }

        return $sentences;
    }

    /**
     * @return list<string>
     */
    private function splitOversized(string $sentence, int $target): array
    {
        $sentence = trim(preg_replace('/\s+/u', ' ', $sentence) ?? '');

        if ($sentence === '') {
            return [];
        }

        if ($this->countWords($sentence) <= $target) {
            return [$sentence];
        }

        return array_map(
            static fn (array $window): string => implode(' ', $window),
            array_chunk(explode(' ', $sentence), $target),
        );
    }

    private function countWords(string $text): int
    {
        $words = preg_split('/\s+/u', trim($text), flags: PREG_SPLIT_NO_EMPTY);

        return $words === false ? 0 : count($words);
    }
}
