<?php

declare(strict_types=1);

use App\Enums\QuestionStatus;
use App\Enums\QuestionType;
use App\Models\AnatomicalStructure;
use App\Models\Organ;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\User;
use App\Services\Assessment\AssessmentService;
use Illuminate\Support\Facades\Cache;

/*
| The AI review queue — PRD §24's control before generated content becomes
| canonical curriculum (docs/handovers/13-admin-content.md, Tests).
|
| Two properties matter and are asserted separately: a `review` question is
| never served to a student, and publishing is what makes it servable.
*/

beforeEach(function (): void {
    Cache::flush();

    $this->admin = User::factory()->admin()->create();
    $this->organ = Organ::factory()->published()->create();
});

function reviewableQuestion(Organ $organ): Question
{
    $question = Question::factory()->for($organ)->create([
        'type' => QuestionType::Mcq,
        'status' => QuestionStatus::Review,
        'generated_by_ai' => true,
    ]);

    QuestionOption::factory()->for($question)->create(['label' => 'A', 'is_correct' => true]);
    QuestionOption::factory()->for($question)->create(['label' => 'B', 'is_correct' => false]);

    return $question;
}

it('never serves a question in review to a student', function (): void {
    reviewableQuestion($this->organ);

    // The student-facing quiz is built from `published` questions only.
    expect(app(AssessmentService::class)->findPublishedQuiz($this->organ->slug))->toBeNull();
});

it('lists only AI-generated questions awaiting review', function (): void {
    $generated = reviewableQuestion($this->organ);

    // A human draft parked in review is not what PRD §24 asks a reviewer to
    // look at, and neither is an already-published question.
    Question::factory()->for($this->organ)->create([
        'status' => QuestionStatus::Review,
        'generated_by_ai' => false,
    ]);
    Question::factory()->for($this->organ)->create([
        'status' => QuestionStatus::Published,
        'generated_by_ai' => true,
    ]);

    $props = $this->actingAs($this->admin)
        ->get('/admin/questions/review')
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['questions']['data'])->toHaveCount(1)
        ->and($props['questions']['data'][0]['id'])->toBe((string) $generated->id);
});

it('publishes an approved question into the pool', function (): void {
    // Acceptance criterion 3.
    $question = reviewableQuestion($this->organ);

    $this->actingAs($this->admin)
        ->post("/admin/questions/{$question->id}/publish")
        ->assertRedirect();

    expect($question->refresh()->status)->toBe(QuestionStatus::Published);

    Cache::flush();
    $quiz = app(AssessmentService::class)->findPublishedQuiz($this->organ->slug);

    expect($quiz?->questions->modelKeys())->toContain($question->getKey());
});

it('refuses to publish a multiple-choice question with no correct option', function (): void {
    $question = Question::factory()->for($this->organ)->create([
        'type' => QuestionType::Mcq,
        'status' => QuestionStatus::Review,
        'generated_by_ai' => true,
    ]);

    QuestionOption::factory()->for($question)->create(['is_correct' => false]);

    // Every student would get it wrong. That is a validation failure the
    // reviewer can fix, not a 500.
    $this->actingAs($this->admin)
        ->post("/admin/questions/{$question->id}/publish")
        ->assertSessionHasErrors('status');

    expect($question->refresh()->status)->toBe(QuestionStatus::Review);
});

it('refuses to publish a spatial question whose structure is unpublished', function (): void {
    $structure = AnatomicalStructure::factory()->for($this->organ)->create(['is_published' => false]);

    $question = Question::factory()->for($this->organ)->create([
        'type' => QuestionType::Spatial,
        'status' => QuestionStatus::Review,
        'generated_by_ai' => true,
        'correct_structure_id' => $structure->getKey(),
    ]);

    $this->actingAs($this->admin)
        ->post("/admin/questions/{$question->id}/publish")
        ->assertSessionHasErrors('status');
});

it('sends a question back to draft without the publishable check', function (): void {
    // Withdrawing a broken question must not be blocked by the check that
    // guards publishing one.
    $question = Question::factory()->for($this->organ)->create([
        'type' => QuestionType::Mcq,
        'status' => QuestionStatus::Published,
        'generated_by_ai' => true,
    ]);

    $this->actingAs($this->admin)
        ->patch("/admin/questions/{$question->id}/status", ['status' => 'draft'])
        ->assertRedirect();

    expect($question->refresh()->status)->toBe(QuestionStatus::Draft);
});

it('routes a status change to published through the publishable check', function (): void {
    $question = Question::factory()->for($this->organ)->create([
        'type' => QuestionType::Mcq,
        'status' => QuestionStatus::Draft,
        'generated_by_ai' => false,
    ]);

    // No correct option: publishing has one door, and it has the check on it,
    // whichever route was used to knock.
    $this->actingAs($this->admin)
        ->patch("/admin/questions/{$question->id}/status", ['status' => 'published'])
        ->assertSessionHasErrors('status');

    expect($question->refresh()->status)->toBe(QuestionStatus::Draft);
});

it('is the only thing that can publish a question', function (): void {
    // The control PRD §24 requires is only real if nothing else writes
    // `questions.status`. AssessmentService is the single writer, and its two
    // status methods are covered above; this asserts no other service exposes
    // one.
    $writers = collect(glob(base_path('app/Services/**/*.php')) ?: [])
        ->filter(fn (string $file): bool => str_contains((string) file_get_contents($file), 'QuestionStatus::Published'))
        ->map(fn (string $file): string => basename($file))
        ->values()
        ->all();

    expect($writers)->toBe(['AssessmentService.php']);
});
