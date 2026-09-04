<?php

declare(strict_types=1);

use App\Exceptions\AIProviderException;
use App\Exceptions\VectorStoreException;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/*
| A student must never see a provider name, an upstream status code, or a stack
| trace (docs/architecture.md §14, PRD §40). These are the tests that keep that
| true once four different lanes start calling providers.
*/

beforeEach(function (): void {
    Route::middleware(['web'])->get('/__test__/ai-fails', function (): never {
        throw AIProviderException::rejected('anthropic', 429, 'rate_limit_exceeded');
    });

    Route::middleware(['web'])->get('/__test__/store-fails', function (): never {
        throw VectorStoreException::unavailable('pinecone');
    });
});

it('returns a safe JSON message when a provider fails', function (): void {
    $response = $this->actingAs(User::factory()->create())
        ->getJson('/__test__/ai-fails')
        ->assertStatus(503);

    $body = $response->json();

    expect($body)->toHaveKeys(['message', 'correlation_id'])
        ->and($body['message'])->not->toContain('anthropic')
        ->and($body['message'])->not->toContain('429')
        ->and($body['message'])->not->toContain('rate_limit_exceeded');

    // The whole serialised response, not just the message: a stack frame or an
    // exception class name leaking through any other key is the same failure.
    expect(json_encode($body, JSON_THROW_ON_ERROR))
        ->not->toContain('AIProviderException')
        ->not->toContain('vendor/')
        ->not->toContain('#0 ');
});

it('hides the vector store name too', function (): void {
    $body = $this->actingAs(User::factory()->create())
        ->getJson('/__test__/store-fails')
        ->assertStatus(503)
        ->json();

    expect($body['message'])->not->toContain('pinecone')
        ->and($body['message'])->not->toContain('Pinecone');
});

it('logs the detail it refuses to render, against a correlation id', function (): void {
    Log::spy();

    $correlationId = $this->actingAs(User::factory()->create())
        ->getJson('/__test__/ai-fails')
        ->json('correlation_id');

    expect($correlationId)->toBeString()->not->toBeEmpty();

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(function (string $message, array $context) use ($correlationId): bool {
            // Everything withheld from the student has to be in the log, or the
            // failure becomes undiagnosable rather than merely unrevealing.
            return $message === 'Upstream service failure'
                && $context['correlation_id'] === $correlationId
                && str_contains((string) $context['message'], 'anthropic')
                && str_contains((string) $context['message'], '429');
        });
});

it('sends a browser request back with a flash message rather than an error page', function (): void {
    $this->actingAs(User::factory()->create())
        ->from('/dashboard')
        ->get('/__test__/ai-fails')
        ->assertRedirect('/dashboard')
        ->assertSessionHas('error');
});

it('tells the student what still works', function (): void {
    $message = $this->actingAs(User::factory()->create())
        ->getJson('/__test__/ai-fails')
        ->json('message');

    // PRD §40: an AI outage degrades the tutor, it does not break the lesson.
    // The message has to say so, or a student assumes the whole app is down.
    expect($message)->toContain('lesson');
});
