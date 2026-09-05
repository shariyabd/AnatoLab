<?php

declare(strict_types=1);

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;

/*
| GET /api/v1/ai/conversations — acceptance criterion 4, "conversation history
| persists", and the scoping rule in docs/architecture.md §14.
*/

beforeEach(function (): void {
    $this->student = User::factory()->create();
    $this->actingAs($this->student);
});

it('lists only this student\'s threads, most recently active first', function (): void {
    $older = Conversation::factory()->for($this->student)->create(['title' => 'Older thread']);
    $newer = Conversation::factory()->for($this->student)->create(['title' => 'Newer thread']);
    Conversation::factory()->for(User::factory())->create(['title' => 'Someone else']);

    $older->forceFill(['updated_at' => now()->subDay()])->save();
    $newer->forceFill(['updated_at' => now()])->save();

    $response = $this->getJson('/api/v1/ai/conversations')->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('data.0.title'))->toBe('Newer thread')
        ->and($response->json('data.1.title'))->toBe('Older thread');
});

it('returns a transcript in order', function (): void {
    $conversation = Conversation::factory()->for($this->student)->create();

    ConversationMessage::factory()->for($conversation)->create(['content' => 'Why is its wall thicker?']);
    ConversationMessage::factory()->for($conversation)->fromAssistant()->create(['content' => 'Because pressure.']);

    $response = $this->getJson("/api/v1/ai/conversations/{$conversation->getKey()}")->assertOk();

    expect($response->json('data.messages'))->toHaveCount(2)
        ->and($response->json('data.messages.0.role'))->toBe('user')
        ->and($response->json('data.messages.0.content'))->toBe('Why is its wall thicker?')
        ->and($response->json('data.messages.1.role'))->toBe('assistant');
});

it('404s on another student\'s thread rather than saying it exists', function (): void {
    $theirs = Conversation::factory()->for(User::factory())->create();

    $this->getJson("/api/v1/ai/conversations/{$theirs->getKey()}")->assertNotFound();
});

it('404s on a thread that does not exist', function (): void {
    $this->getJson('/api/v1/ai/conversations/999999')->assertNotFound();
});

it('never exposes the owner of a thread', function (): void {
    $conversation = Conversation::factory()->for($this->student)->create();

    $payload = $this->getJson("/api/v1/ai/conversations/{$conversation->getKey()}")->assertOk()->json();

    expect(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain('user_id');
});

it('refuses an unauthenticated request', function (): void {
    auth()->logout();

    $this->getJson('/api/v1/ai/conversations')->assertUnauthorized();
});
