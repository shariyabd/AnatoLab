<?php

declare(strict_types=1);

use App\Enums\KnowledgeDocumentStatus;
use App\Models\AnatomicalStructure;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Organ;
use Database\Seeders\AchievementSeeder;
use Database\Seeders\AnatomySeeder;
use Database\Seeders\AssessmentSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\LessonSeeder;
use Database\Seeders\MissionSeeder;
use Database\Seeders\PlatformSeeder;
use Database\Seeders\SimulationSeeder;
use Illuminate\Support\Facades\Queue;

/*
| The demo dataset (docs/handovers/14-demo-polish.md).
|
| PRD §44 is walked by a judge on a database that was empty five minutes ago,
| so these assert that one `db:seed` is enough: the spine the journey walks over
| is present, and the corpus its cited answer comes from is indexed.
*/

function seedDemoPrerequisites(): void
{
    test()->seed(PlatformSeeder::class);
    test()->seed(AnatomySeeder::class);
    test()->seed(LessonSeeder::class);
    test()->seed(AssessmentSeeder::class);
    test()->seed(MissionSeeder::class);
    test()->seed(AchievementSeeder::class);
    test()->seed(SimulationSeeder::class);
}

it('indexes a corpus the demo question can be answered from', function (): void {
    seedDemoPrerequisites();
    $this->seed(DemoSeeder::class);

    $documents = KnowledgeDocument::query()->get();

    expect($documents)->not->toBeEmpty('The demo has no knowledge base to cite.');

    // Every document reached the end of the ingest chain. A `pending` document
    // is one whose passages are not searchable, which on stage looks exactly
    // like a tutor that cannot cite anything.
    $documents->each(fn (KnowledgeDocument $document) => expect($document->status)
        ->toBe(KnowledgeDocumentStatus::Indexed, "{$document->title} never finished ingest."));

    expect(KnowledgeChunk::query()->whereNotNull('embedding')->count())
        ->toBe(KnowledgeChunk::query()->count())
        ->and(KnowledgeChunk::query()->whereNotNull('embedding_reference')->count())
        ->toBe(KnowledgeChunk::query()->count());
});

it('tags the left ventricle material for every education level', function (): void {
    seedDemoPrerequisites();
    $this->seed(DemoSeeder::class);

    $ventricle = AnatomicalStructure::query()->where('slug', 'left-ventricle')->sole();

    // The tutor filters on structure_id and education_level exactly
    // (AITutorService::retrieveGrounding), so a level with no passage is a
    // student who registered at that level and gets no citation.
    $levels = KnowledgeChunk::query()
        ->where('structure_id', $ventricle->getKey())
        ->pluck('education_level')
        ->unique()
        ->sort()
        ->values()
        ->all();

    expect($levels)->toBe(['advanced', 'high_school', 'middle_school']);
});

it('scopes every passage to the heart so retrieval cannot wander', function (): void {
    seedDemoPrerequisites();
    $this->seed(DemoSeeder::class);

    $heart = Organ::query()->where('slug', 'heart')->sole();

    KnowledgeChunk::query()->get()->each(
        fn (KnowledgeChunk $chunk) => expect($chunk->organ_id)->toBe((int) $heart->getKey())
    );
});

it('is idempotent', function (): void {
    seedDemoPrerequisites();

    $this->seed(DemoSeeder::class);
    $documents = KnowledgeDocument::query()->count();
    $chunks = KnowledgeChunk::query()->count();

    $this->seed(DemoSeeder::class);

    expect(KnowledgeDocument::query()->count())->toBe($documents)
        ->and(KnowledgeChunk::query()->count())->toBe($chunks);
});

it('leaves nothing on the queue for a worker that may never run', function (): void {
    seedDemoPrerequisites();

    // The default connection, not `sync`: the seeder forces sync for the
    // duration of ingest precisely so that `migrate:fresh --seed` returns with
    // the corpus searchable rather than pending. Asserting the queue is empty
    // afterwards is what stops that being quietly removed — a demo whose
    // citations only appear once somebody starts a worker is a demo that fails
    // on stage.
    config(['queue.default' => 'database']);

    $this->seed(DemoSeeder::class);

    expect(Queue::connection('database')->size('ingest'))->toBe(0)
        ->and(KnowledgeChunk::query()->count())->toBeGreaterThan(0)
        ->and(KnowledgeDocument::query()->where('status', KnowledgeDocumentStatus::Indexed)->count())
        ->toBe(KnowledgeDocument::query()->count());
});

it('fails loudly when the journey it depends on is missing a step', function (): void {
    // Only the platform, so there is no heart to open. A demo whose spine is
    // broken must break the build, not the presentation.
    $this->seed(PlatformSeeder::class);

    expect(fn () => $this->seed(DemoSeeder::class))
        ->toThrow(RuntimeException::class, 'heart');
});
