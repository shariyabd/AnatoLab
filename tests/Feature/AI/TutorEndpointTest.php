<?php

declare(strict_types=1);

use App\Enums\EducationLevel;
use App\Models\AnatomicalStructure;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Organ;
use App\Models\User;

/*
| POST /api/v1/ai/tutor/{ask,explain,hint} — docs/architecture.md §8.2.
|
| AI_PROVIDER is pinned to `null` in phpunit.xml, so nothing here can reach a
| real model even by accident (docs/engineering.md §9). NullProvider echoes the
| last user message back, which is exactly what makes "the prompt contained the
| selected structure" assertable without a live model.
*/

beforeEach(function (): void {
    $this->student = User::factory()->create([
        'education_level' => EducationLevel::HighSchool,
    ]);

    $this->organ = Organ::factory()->published()->create(['slug' => 'heart', 'name' => 'Heart']);

    $this->structure = AnatomicalStructure::factory()->published()->for($this->organ)->create([
        'slug' => 'left-ventricle',
        'name' => 'Left ventricle',
        'ta_term' => 'Ventriculus sinister',
        'function' => 'Pumps oxygenated blood into the aorta.',
    ]);

    $this->actingAs($this->student);
});

it('answers a question using the selected structure as context', function (): void {
    // PRD §9.3 and acceptance criterion 1: select the left ventricle, ask why
    // the wall is thicker, get an answer that demonstrably used the selection.
    $response = $this->postJson('/api/v1/ai/tutor/ask', [
        'question' => 'Why is its wall thicker?',
        'organSlug' => 'heart',
        'structureId' => $this->structure->getKey(),
    ]);

    $response->assertOk()
        ->assertJsonStructure(['data' => [
            'answer', 'followUpQuestions', 'sources', 'sourceNote', 'conversationId',
        ]]);

    $answer = $response->json('data.answer');

    expect($answer)
        ->toContain('Left ventricle')
        ->toContain('Ventriculus sinister')
        ->toContain('Why is its wall thicker?')
        // The student's own level reached the prompt (PRD §39).
        ->toContain('High school')
        // The curated metadata Handover 03 published is the grounding until
        // Handover 11 lands retrieval (docs/architecture.md §8.2).
        ->toContain('Pumps oxygenated blood into the aorta.');
});

it('ships an empty sources array and says which grounding it used instead', function (): void {
    $response = $this->postJson('/api/v1/ai/tutor/ask', [
        'question' => 'What does it do?',
        'structureId' => $this->structure->getKey(),
    ]);

    $response->assertOk()
        ->assertJsonPath('data.sources', [])
        ->assertJsonPath('data.conversationId', fn (mixed $id): bool => is_int($id));

    expect($response->json('data.sourceNote'))
        ->toContain('No indexed source')
        ->toContain('Left ventricle');
});

it('explains a selection with no question of the student\'s own', function (): void {
    $this->postJson('/api/v1/ai/tutor/explain', [
        'structureId' => $this->structure->getKey(),
    ])->assertOk();
});

it('rejects an explain request with nothing selected', function (): void {
    $this->postJson('/api/v1/ai/tutor/explain', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['structureId', 'organSlug']);
});

it('hints without needing a question', function (): void {
    $this->postJson('/api/v1/ai/tutor/hint', [
        'structureId' => $this->structure->getKey(),
    ])->assertOk();
});

it('validates the question', function (): void {
    $this->postJson('/api/v1/ai/tutor/ask', ['question' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors('question');

    $this->postJson('/api/v1/ai/tutor/ask', ['question' => str_repeat('a', 501)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('question');
});

it('refuses an unauthenticated request', function (): void {
    auth()->logout();

    $this->postJson('/api/v1/ai/tutor/ask', ['question' => 'Why is its wall thicker?'])
        ->assertUnauthorized();
});

it('answers without a selection rather than failing', function (): void {
    // A student can open the tutor with nothing selected; refusing would be a
    // worse experience than answering without an anchor.
    $this->postJson('/api/v1/ai/tutor/ask', ['question' => 'What is an artery?'])
        ->assertOk()
        ->assertJsonPath('data.sources', []);
});

it('treats an unpublished structure as no selection', function (): void {
    $draft = AnatomicalStructure::factory()->for($this->organ)->create(['name' => 'Draft structure']);

    $response = $this->postJson('/api/v1/ai/tutor/ask', [
        'question' => 'What is this?',
        'structureId' => $draft->getKey(),
    ]);

    $response->assertOk();
    expect($response->json('data.answer'))->not->toContain('Draft structure');
});

it('persists both turns of the conversation', function (): void {
    $response = $this->postJson('/api/v1/ai/tutor/ask', [
        'question' => 'Why is its wall thicker?',
        'structureId' => $this->structure->getKey(),
    ])->assertOk();

    $conversation = Conversation::query()->findOrFail($response->json('data.conversationId'));

    expect($conversation->user_id)->toBe($this->student->getKey())
        ->and($conversation->context_type->value)->toBe('structure')
        ->and($conversation->context_id)->toBe($this->structure->getKey())
        ->and($conversation->title)->toContain('Why is its wall thicker?');

    $messages = ConversationMessage::query()->where('conversation_id', $conversation->getKey())->get();

    expect($messages)->toHaveCount(2)
        ->and($messages[0]->role->value)->toBe('user')
        ->and($messages[0]->content)->toBe('Why is its wall thicker?')
        ->and($messages[1]->role->value)->toBe('assistant');
});

it('continues an existing conversation the student owns', function (): void {
    $first = $this->postJson('/api/v1/ai/tutor/ask', [
        'question' => 'Why is its wall thicker?',
        'structureId' => $this->structure->getKey(),
    ])->json('data.conversationId');

    $second = $this->postJson('/api/v1/ai/tutor/ask', [
        'question' => 'And the right one?',
        'structureId' => $this->structure->getKey(),
        'conversationId' => $first,
    ])->json('data.conversationId');

    expect($second)->toBe($first)
        ->and(ConversationMessage::query()->where('conversation_id', $first)->count())->toBe(4);
});

it('starts a new thread rather than writing into someone else\'s', function (): void {
    // Ownership is scoped in the service from the passed-in User and never
    // trusted from the request (docs/architecture.md §14).
    $someoneElse = Conversation::factory()->for(User::factory())->create();

    $mine = $this->postJson('/api/v1/ai/tutor/ask', [
        'question' => 'Why is its wall thicker?',
        'conversationId' => $someoneElse->getKey(),
    ])->assertOk()->json('data.conversationId');

    expect($mine)->not->toBe($someoneElse->getKey())
        ->and(Conversation::query()->findOrFail($mine)->user_id)->toBe($this->student->getKey())
        ->and(ConversationMessage::query()->where('conversation_id', $someoneElse->getKey())->count())->toBe(0);
});

it('carries no correctness field in any payload', function (): void {
    // Deliberately NOT expect()->toCarryNoAnswerKey(). That shared matcher
    // (tests/Pest.php) forbids a field literally named `answer`, which is the
    // tutor's own prose field in docs/architecture.md §8.2's envelope — the
    // matcher is aimed at quiz and mission payloads, where `answer` means the
    // correct one. Asserting the correctness fields directly keeps the real
    // guarantee without weakening a shared assertion for every other lane.
    $encoded = json_encode(
        $this->postJson('/api/v1/ai/tutor/ask', [
            'question' => 'Why is its wall thicker?',
            'structureId' => $this->structure->getKey(),
        ])->assertOk()->json(),
        JSON_THROW_ON_ERROR,
    );

    foreach ([
        'is_correct', 'isCorrect',
        'correct_option_id', 'correctOptionId',
        'correct_structure_id', 'correctStructureId',
        'correct_sequence', 'correctSequence',
    ] as $forbidden) {
        expect($encoded)->not->toContain("\"{$forbidden}\"");
    }
});

it('does not expose message metadata to the student', function (): void {
    // `metadata` records the model, token usage, and the validator's reason
    // code. `violation` in particular is a hint about how to rephrase to get
    // past the validator.
    $conversationId = $this->postJson('/api/v1/ai/tutor/ask', [
        'question' => 'Why is its wall thicker?',
        'structureId' => $this->structure->getKey(),
    ])->assertOk()->json('data.conversationId');

    $payload = $this->getJson("/api/v1/ai/conversations/{$conversationId}")->assertOk()->json();

    expect(json_encode($payload, JSON_THROW_ON_ERROR))
        ->not->toContain('violation')
        ->not->toContain('validator_replaced');
});
