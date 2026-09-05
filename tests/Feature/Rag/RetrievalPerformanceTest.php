<?php

declare(strict_types=1);

use App\Infrastructure\VectorStore\MySqlVectorStore;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Organ;

/*
| Acceptance criterion 4: retrieval under 50 ms on the MVP corpus with
| MySqlVectorStore (docs/architecture.md §8.1).
|
| The claim that MySQL can be the DEFAULT rests entirely on this number, so it
| is measured rather than asserted in a comment. The shape being measured is the
| one the architecture describes: filter in SQL down to hundreds of rows, load
| those embeddings, score them in PHP.
*/

it('scores a filtered MVP corpus well inside the 50 ms budget', function (): void {
    $dimensions = 1536;
    $document = KnowledgeDocument::factory()->create();
    $heart = (int) Organ::factory()->published()->create(['slug' => 'heart'])->getKey();
    $lungs = (int) Organ::factory()->published()->create(['slug' => 'lungs'])->getKey();

    // 400 chunks, of which 100 survive an organ filter — the corpus size
    // docs/architecture.md §8.1 sizes the design for, scaled down enough that
    // seeding it does not dominate the suite.
    foreach (range(0, 399) as $index) {
        KnowledgeChunk::factory()->create([
            'document_id' => $document->getKey(),
            'chunk_index' => $index,
            'organ_id' => $index % 4 === 0 ? $heart : $lungs,
            'embedding' => randomUnitVector($dimensions),
        ]);
    }

    $query = randomUnitVector($dimensions);
    $store = new MySqlVectorStore;

    // Warm the query plan and the autoloader, then take the median of three:
    // one cold outlier on a loaded CI box should not fail a budget the design
    // meets comfortably.
    $store->search($query, topK: 5, filters: ['organ_id' => $heart]);

    $timings = [];

    foreach (range(1, 3) as $ignored) {
        $started = hrtime(true);
        $results = $store->search($query, topK: 5, filters: ['organ_id' => $heart]);
        $timings[] = (hrtime(true) - $started) / 1_000_000;
    }

    sort($timings);

    expect($results)->toHaveCount(5)
        ->and($timings[1])->toBeLessThan(50.0);
});

/**
 * @return list<float>
 */
function randomUnitVector(int $dimensions): array
{
    $vector = [];
    $magnitude = 0.0;

    for ($index = 0; $index < $dimensions; $index++) {
        $component = mt_rand(-1000, 1000) / 1000;
        $vector[] = $component;
        $magnitude += $component ** 2;
    }

    $magnitude = sqrt($magnitude) ?: 1.0;

    return array_map(static fn (float $component): float => $component / $magnitude, $vector);
}
