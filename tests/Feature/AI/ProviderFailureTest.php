<?php

declare(strict_types=1);

use App\Contracts\AIProviderInterface;
use App\Contracts\AIResponse;
use App\Exceptions\AIProviderException;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/*
| Acceptance criterion 3: a provider outage degrades gracefully, and everything
| else keeps working (docs/architecture.md §14, PRD §40).
|
| The double below throws; nothing here touches a network.
*/

function failWith(AIProviderException $exception): void
{
    app()->bind(AIProviderInterface::class, fn (): AIProviderInterface => new class($exception) implements AIProviderInterface
    {
        public function __construct(private readonly AIProviderException $exception) {}

        public function chat(array $messages, array $options = []): AIResponse
        {
            throw $this->exception;
        }

        public function generateEmbedding(string $text): array
        {
            throw $this->exception;
        }

        public function generateEmbeddings(array $texts): array
        {
            throw $this->exception;
        }
    });
}

beforeEach(function (): void {
    $this->student = User::factory()->create();
    $this->actingAs($this->student);
});

it('returns an educational-tone message, never the provider or the status', function (): void {
    Log::spy();
    failWith(AIProviderException::rejected('anthropic', 529, 'overloaded_error'));

    $response = $this->postJson('/api/v1/ai/tutor/ask', ['question' => 'Why is its wall thicker?'])
        ->assertStatus(503);

    $body = json_encode($response->json(), JSON_THROW_ON_ERROR);

    expect($response->json('message'))->toContain('Everything else still works')
        ->and($body)->not->toContain('anthropic')
        ->and($body)->not->toContain('529')
        ->and($body)->not->toContain('overloaded_error')
        // No stack trace, even with APP_DEBUG on in the test environment.
        ->and($body)->not->toContain('vendor/laravel');
});

it('gives support something to trace without putting it in the message', function (): void {
    Log::spy();
    failWith(AIProviderException::unavailable('anthropic'));

    $response = $this->postJson('/api/v1/ai/tutor/ask', ['question' => 'Why is its wall thicker?']);

    expect($response->json('correlation_id'))->not->toBeEmpty();

    Log::shouldHaveReceived('error')->once()->withArgs(
        // The provider name and status belong in the log, which is exactly why
        // they are not in the response.
        fn (string $message, array $payload): bool => str_contains($payload['message'], 'anthropic')
            && $payload['correlation_id'] !== ''
    );
});

it('leaves no half-written conversation behind', function (): void {
    Log::spy();
    failWith(AIProviderException::unavailable('anthropic'));

    $this->postJson('/api/v1/ai/tutor/ask', ['question' => 'Why is its wall thicker?'])
        ->assertStatus(503);

    expect(Conversation::query()->count())->toBe(0);
});

it('keeps the rest of the application working during an outage', function (): void {
    Log::spy();
    failWith(AIProviderException::unavailable('anthropic'));

    $this->postJson('/api/v1/ai/tutor/ask', ['question' => 'Why?'])->assertStatus(503);

    // The 3D interaction and the structure notes are what a student falls back
    // to, so they must be untouched by an AI outage.
    $this->getJson('/api/v1/anatomy/organs')->assertOk();
    $this->getJson('/api/v1/ai/conversations')->assertOk();
});
