<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use App\Contracts\AIProviderInterface;
use App\Contracts\AIResponse;
use App\Exceptions\AIProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;

/**
 * The default provider (docs/architecture.md §8.1).
 *
 * Talks to the Anthropic Messages API. The model is a config value, never a
 * literal, so upgrading from one Claude release to the next is an environment
 * change (`ANTHROPIC_CHAT_MODEL`).
 *
 * Every upstream failure — transport, HTTP status, unusable body — leaves this
 * class as an AIProviderException. Nothing above it ever sees a Guzzle
 * exception, a status code, or a provider name, which is what keeps providers
 * swappable and what stops a student seeing an upstream error (§14).
 */
final class AnthropicProvider implements AIProviderInterface
{
    private const NAME = 'anthropic';

    /** The Messages API is versioned by header, not by URL path. */
    private const API_VERSION = '2023-06-01';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $apiKey,
        private readonly string $chatModel,
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds,
        private readonly int $retries,
        private readonly int $retryDelayMs,
    ) {}

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     */
    public function chat(array $messages, array $options = []): AIResponse
    {
        $this->assertConfigured();

        $payload = array_filter([
            'model' => $this->chatModel,
            'max_tokens' => (int) ($options['max_tokens'] ?? 1024),
            'temperature' => (float) ($options['temperature'] ?? 0.3),
            // Anthropic takes the system prompt as a top-level field rather than
            // as a message with role "system"; PromptBuilder therefore returns
            // it separately instead of prepending it to $messages.
            'system' => isset($options['system']) ? (string) $options['system'] : null,
            'messages' => $messages,
        ], static fn (mixed $value): bool => $value !== null);

        $body = $this->post('/v1/messages', $payload);

        return $this->toResponse($body);
    }

    /**
     * @return list<float>
     */
    public function generateEmbedding(string $text): array
    {
        return $this->generateEmbeddings([$text])[0]
            ?? throw AIProviderException::malformedResponse(self::NAME, 'empty embedding batch');
    }

    /**
     * Anthropic publishes no embeddings endpoint.
     *
     * This is not an oversight, and it is not a stub to fill in later: there is
     * nothing to call. config/ai.php reflects it — `openai` carries an
     * `embedding_model` key and `anthropic` does not.
     *
     * Handover 11 needs embeddings for ingest and retrieval. When it lands, an
     * installation running AI_PROVIDER=anthropic must either run OpenAI for
     * embeddings or add a dedicated embeddings provider; failing loudly here is
     * what makes that decision visible instead of producing silent zero vectors
     * that would rank every chunk identically.
     *
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function generateEmbeddings(array $texts): array
    {
        unset($texts);

        throw AIProviderException::malformedResponse(
            self::NAME,
            'this provider has no embeddings endpoint; configure an embeddings-capable provider',
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<mixed>
     */
    private function post(string $path, array $payload): array
    {
        try {
            $response = $this->http
                ->asJson()
                ->acceptJson()
                ->withHeaders([
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => self::API_VERSION,
                ])
                ->timeout($this->timeoutSeconds)
                // One retry, not three: a student is waiting, and a second
                // failure is an outage rather than a blip (docs/architecture.md
                // §8.4). throw: false so a 4xx becomes our exception below with
                // its status intact, rather than a Guzzle exception here.
                ->retry($this->retries + 1, $this->retryDelayMs, throw: false)
                ->post(rtrim($this->baseUrl, '/').$path, $payload);
        } catch (ConnectionException|RequestException $exception) {
            throw AIProviderException::unavailable(self::NAME, $exception);
        }

        if ($response->failed()) {
            throw AIProviderException::rejected(self::NAME, $response->status());
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw AIProviderException::malformedResponse(self::NAME, 'response body was not JSON');
        }

        return $body;
    }

    /**
     * @param  array<mixed>  $body
     */
    private function toResponse(array $body): AIResponse
    {
        $blocks = $body['content'] ?? null;

        if (! is_array($blocks)) {
            throw AIProviderException::malformedResponse(self::NAME, 'no content blocks');
        }

        // The Messages API returns an array of typed blocks. Only text blocks
        // are meaningful to a tutor answer; concatenating everything would
        // splice tool-use JSON into the student's reading.
        $text = '';

        foreach ($blocks as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }

        if (trim($text) === '') {
            throw AIProviderException::malformedResponse(self::NAME, 'no text block in response');
        }

        $usage = is_array($body['usage'] ?? null) ? $body['usage'] : [];

        return new AIResponse(
            content: trim($text),
            model: is_string($body['model'] ?? null) ? $body['model'] : $this->chatModel,
            promptTokens: (int) ($usage['input_tokens'] ?? 0),
            completionTokens: (int) ($usage['output_tokens'] ?? 0),
            truncated: ($body['stop_reason'] ?? null) === 'max_tokens',
        );
    }

    private function assertConfigured(): void
    {
        if (trim($this->apiKey) === '') {
            // Treated as an outage rather than a 500: a missing key in
            // production is an operational problem, and the student-facing
            // result is identical either way — the tutor is unavailable and
            // everything else keeps working.
            throw AIProviderException::unavailable(self::NAME);
        }
    }
}
