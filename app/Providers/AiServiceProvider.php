<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\AIProviderInterface;
use App\Infrastructure\AI\AnthropicProvider;
use App\Infrastructure\AI\NullProvider;
use App\Infrastructure\AI\OpenAIProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * OWNED BY HANDOVER 08.
 *
 * Registered after AppServiceProvider in bootstrap/providers.php, so the bind
 * below replaces the platform's baseline NullProvider binding — a later bind()
 * wins, which is how a feature overrides the platform without editing a file
 * Handover 01 owns (see AppServiceProvider::registerBaselineAiBindings).
 *
 * Handover 11 appends RagServiceProvider to the same list later and rebinds
 * VectorStoreInterface the same way. Neither provider touches the other's
 * contract.
 */
final class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AIProviderInterface::class, fn (): AIProviderInterface => $this->makeProvider());
    }

    public function boot(): void
    {
        $this->configureTutorRateLimits();
    }

    /**
     * The whole point of the abstraction: which class this returns is an
     * environment variable (docs/architecture.md §8.1).
     *
     * Singleton because both HTTP providers are stateless and the config reads
     * should happen once per process, not once per question.
     */
    private function makeProvider(): AIProviderInterface
    {
        return match ($this->configuredName('ai.provider')) {
            'anthropic' => $this->makeAnthropicProvider(),
            'openai' => $this->makeOpenAIProvider(),
            'null' => $this->makeNullProvider(),
            // Loud rather than silently falling back to the null provider: an
            // installation that thinks it has a tutor and is quietly serving
            // placeholder text is the worse failure.
            default => throw new InvalidArgumentException(sprintf(
                'Unknown AI provider [%s]. Set AI_PROVIDER to anthropic, openai, or null.',
                $this->configuredName('ai.provider'),
            )),
        };
    }

    /**
     * Read a config key that names an implementation, defaulting to `null`.
     *
     * `AI_PROVIDER=null` in an env file is the *string* "null", which Laravel's
     * env() helper converts to PHP null before it reaches config(). config()
     * then returns that null rather than the default, so a plain
     * `config('ai.provider', 'null')` yields null and a `(string)` cast yields
     * an empty name. Normalising here keeps config/ai.php — a shared, Handover
     * 01-owned file — untouched.
     */
    private function configuredName(string $key): string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : 'null';
    }

    private function makeAnthropicProvider(): AnthropicProvider
    {
        return new AnthropicProvider(
            http: $this->app->make(HttpFactory::class),
            apiKey: (string) config('ai.providers.anthropic.api_key', ''),
            chatModel: (string) config('ai.providers.anthropic.chat_model', 'claude-sonnet-5'),
            baseUrl: (string) config('ai.providers.anthropic.base_url', 'https://api.anthropic.com'),
            timeoutSeconds: (int) config('ai.transport.timeout_seconds', 20),
            retries: (int) config('ai.transport.retries', 1),
            retryDelayMs: (int) config('ai.transport.retry_delay_ms', 400),
        );
    }

    private function makeOpenAIProvider(): OpenAIProvider
    {
        return new OpenAIProvider(
            http: $this->app->make(HttpFactory::class),
            apiKey: (string) config('ai.providers.openai.api_key', ''),
            chatModel: (string) config('ai.providers.openai.chat_model', 'gpt-4o-mini'),
            embeddingModel: (string) config('ai.providers.openai.embedding_model', 'text-embedding-3-small'),
            baseUrl: (string) config('ai.providers.openai.base_url', 'https://api.openai.com/v1'),
            timeoutSeconds: (int) config('ai.transport.timeout_seconds', 20),
            retries: (int) config('ai.transport.retries', 1),
            retryDelayMs: (int) config('ai.transport.retry_delay_ms', 400),
        );
    }

    private function makeNullProvider(): NullProvider
    {
        $store = $this->configuredName('ai.vector_store');

        return new NullProvider(
            dimensions: (int) config("ai.vector_stores.{$store}.dimensions", 1536),
        );
    }

    /**
     * Rate limits ship with the endpoint, not after it (docs/engineering.md §10).
     *
     * Three named limiters rather than one because docs/architecture.md §8.4
     * specifies different numbers per endpoint, and because a daily cap needs
     * its own decay window: Laravel's `throttle` middleware composes, so
     * `throttle:ai-tutor,ai-tutor-daily` applies both.
     *
     * Keyed by user id, not IP: a classroom behind one school NAT would
     * otherwise share one bucket and lock itself out in a minute.
     */
    private function configureTutorRateLimits(): void
    {
        RateLimiter::for('ai-tutor', fn (Request $request): Limit => Limit::perMinute(
            (int) config('ai.limits.tutor_per_minute', 20)
        )->by(self::limiterKey($request, 'tutor')));

        RateLimiter::for('ai-tutor-daily', fn (Request $request): Limit => Limit::perDay(
            (int) config('ai.limits.tutor_per_day', 200)
        )->by(self::limiterKey($request, 'tutor-day')));

        RateLimiter::for('ai-hint', fn (Request $request): Limit => Limit::perMinute(
            (int) config('ai.limits.hint_per_minute', 30)
        )->by(self::limiterKey($request, 'hint')));
    }

    private static function limiterKey(Request $request, string $bucket): string
    {
        return $bucket.':'.($request->user()?->getAuthIdentifier() ?? $request->ip() ?? 'unknown');
    }
}
