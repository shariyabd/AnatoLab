<?php

declare(strict_types=1);

use App\Contracts\AIProviderInterface;
use App\Exceptions\AIProviderException;
use App\Infrastructure\AI\AnthropicProvider;
use App\Infrastructure\AI\NullProvider;
use App\Infrastructure\AI\OpenAIProvider;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;

/*
| The provider abstraction — docs/architecture.md §8.1, PRD §23, acceptance
| criterion 2: "swapping AI_PROVIDER changes provider with no code change".
|
| Every HTTP call here is Http::fake(). No test in this suite reaches a real
| model (docs/engineering.md §9).
*/

function swapProvider(string $name): AIProviderInterface
{
    config(['ai.provider' => $name]);

    // The binding is a singleton, so an instance resolved before the config
    // change would survive it.
    app()->forgetInstance(AIProviderInterface::class);

    return app(AIProviderInterface::class);
}

it('resolves the provider named by config, and nothing else', function (): void {
    expect(swapProvider('anthropic'))->toBeInstanceOf(AnthropicProvider::class)
        ->and(swapProvider('openai'))->toBeInstanceOf(OpenAIProvider::class)
        ->and(swapProvider('null'))->toBeInstanceOf(NullProvider::class);
});

it('treats an unset AI_PROVIDER as the null provider', function (): void {
    // `AI_PROVIDER=null` in an env file arrives as PHP null, not the string.
    config(['ai.provider' => null]);
    app()->forgetInstance(AIProviderInterface::class);

    expect(app(AIProviderInterface::class))->toBeInstanceOf(NullProvider::class);
});

it('refuses to start on an unknown provider name', function (): void {
    // Loud, rather than quietly serving placeholder text from the null provider
    // to an installation that thinks it has a tutor.
    expect(fn (): AIProviderInterface => swapProvider('gemini'))
        ->toThrow(InvalidArgumentException::class);
});

describe('AnthropicProvider', function (): void {
    beforeEach(function (): void {
        $this->provider = new AnthropicProvider(
            http: app(HttpFactory::class),
            apiKey: 'test-key',
            chatModel: 'claude-sonnet-5',
            baseUrl: 'https://api.anthropic.com',
            timeoutSeconds: 20,
            retries: 1,
            retryDelayMs: 0,
        );
    });

    it('sends the system prompt as a top-level field and returns normalised usage', function (): void {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'model' => 'claude-sonnet-5',
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'The wall is thicker.']],
            'usage' => ['input_tokens' => 120, 'output_tokens' => 30],
        ])]);

        $response = $this->provider->chat(
            [['role' => 'user', 'content' => 'Why?']],
            ['system' => 'You are a tutor.', 'max_tokens' => 500, 'temperature' => 0.2],
        );

        expect($response->content)->toBe('The wall is thicker.')
            ->and($response->model)->toBe('claude-sonnet-5')
            ->and($response->totalTokens())->toBe(150)
            ->and($response->truncated)->toBeFalse();

        Http::assertSent(function ($request): bool {
            return $request->hasHeader('x-api-key', 'test-key')
                && $request->hasHeader('anthropic-version')
                && $request['system'] === 'You are a tutor.'
                && $request['messages'][0]['role'] === 'user';
        });
    });

    it('concatenates only text blocks', function (): void {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [
                ['type' => 'thinking', 'thinking' => 'internal'],
                ['type' => 'text', 'text' => 'Visible answer.'],
            ],
        ])]);

        expect($this->provider->chat([['role' => 'user', 'content' => 'Why?']])->content)
            ->toBe('Visible answer.');
    });

    it('reports a token-limit stop as truncated', function (): void {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'stop_reason' => 'max_tokens',
            'content' => [['type' => 'text', 'text' => 'Half an answer and']],
        ])]);

        expect($this->provider->chat([['role' => 'user', 'content' => 'Why?']])->truncated)->toBeTrue();
    });

    it('wraps an upstream status in AIProviderException', function (): void {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'overloaded'], 529)]);

        expect(fn () => $this->provider->chat([['role' => 'user', 'content' => 'Why?']]))
            ->toThrow(AIProviderException::class);
    });

    it('wraps an unusable body in AIProviderException', function (): void {
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => []])]);

        expect(fn () => $this->provider->chat([['role' => 'user', 'content' => 'Why?']]))
            ->toThrow(AIProviderException::class);
    });

    it('fails as unavailable when no key is configured', function (): void {
        $unconfigured = new AnthropicProvider(
            http: app(HttpFactory::class),
            apiKey: '',
            chatModel: 'claude-sonnet-5',
            baseUrl: 'https://api.anthropic.com',
            timeoutSeconds: 20,
            retries: 1,
            retryDelayMs: 0,
        );

        Http::fake();

        expect(fn () => $unconfigured->chat([['role' => 'user', 'content' => 'Why?']]))
            ->toThrow(AIProviderException::class);

        Http::assertNothingSent();
    });

    it('fails loudly on embeddings, which Anthropic does not offer', function (): void {
        // Not a stub to fill in later: there is no endpoint. Handover 11 must
        // configure an embeddings-capable provider, and a silent zero vector
        // would rank every chunk identically instead of saying so.
        expect(fn (): array => $this->provider->generateEmbedding('aorta'))
            ->toThrow(AIProviderException::class);
    });
});

describe('OpenAIProvider', function (): void {
    beforeEach(function (): void {
        $this->provider = new OpenAIProvider(
            http: app(HttpFactory::class),
            apiKey: 'test-key',
            chatModel: 'gpt-4o-mini',
            embeddingModel: 'text-embedding-3-small',
            baseUrl: 'https://api.openai.com/v1',
            timeoutSeconds: 20,
            retries: 1,
            retryDelayMs: 0,
        );
    });

    it('sends the system prompt as the first message', function (): void {
        // The one shape difference between the two providers, normalised here
        // so PromptBuilder never learns there are two.
        Http::fake(['api.openai.com/*' => Http::response([
            'model' => 'gpt-4o-mini',
            'choices' => [['message' => ['content' => 'The wall is thicker.'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
        ])]);

        $response = $this->provider->chat(
            [['role' => 'user', 'content' => 'Why?']],
            ['system' => 'You are a tutor.'],
        );

        expect($response->content)->toBe('The wall is thicker.')
            ->and($response->totalTokens())->toBe(120);

        Http::assertSent(fn ($request): bool => $request['messages'][0]['role'] === 'system'
            && $request['messages'][0]['content'] === 'You are a tutor.'
            && $request['messages'][1]['role'] === 'user');
    });

    it('returns embeddings in input order even when the provider answers out of order', function (): void {
        Http::fake(['api.openai.com/*' => Http::response([
            'data' => [
                ['index' => 1, 'embedding' => [0.3, 0.4]],
                ['index' => 0, 'embedding' => [0.1, 0.2]],
            ],
        ])]);

        expect($this->provider->generateEmbeddings(['aorta', 'atrium']))
            ->toBe([[0.1, 0.2], [0.3, 0.4]]);
    });

    it('rejects a mismatched embedding count', function (): void {
        Http::fake(['api.openai.com/*' => Http::response(['data' => [['index' => 0, 'embedding' => [0.1]]]])]);

        expect(fn (): array => $this->provider->generateEmbeddings(['aorta', 'atrium']))
            ->toThrow(AIProviderException::class);
    });

    it('reports a length finish as truncated', function (): void {
        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'Half an answer and'], 'finish_reason' => 'length']],
        ])]);

        expect($this->provider->chat([['role' => 'user', 'content' => 'Why?']])->truncated)->toBeTrue();
    });

    it('wraps an upstream status in AIProviderException', function (): void {
        Http::fake(['api.openai.com/*' => Http::response(['error' => 'rate limited'], 429)]);

        expect(fn () => $this->provider->chat([['role' => 'user', 'content' => 'Why?']]))
            ->toThrow(AIProviderException::class);
    });
});
