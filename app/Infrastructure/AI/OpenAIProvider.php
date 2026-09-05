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
 * The alternate provider.
 *
 * It exists to prove the seam is real: an abstraction with one implementation
 * is a guess about the future, and docs/engineering.md §5 permits exactly two
 * interfaces in this codebase precisely because both have a second
 * implementation. Swapping to it is `AI_PROVIDER=openai` and nothing else.
 *
 * It is also, today, the only configured provider that can embed — Anthropic
 * publishes no embeddings endpoint (see AnthropicProvider::generateEmbeddings).
 */
final class OpenAIProvider implements AIProviderInterface
{
    private const NAME = 'openai';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $apiKey,
        private readonly string $chatModel,
        private readonly string $embeddingModel,
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

        // Unlike Anthropic, OpenAI takes the system prompt as the first message.
        // Normalising the difference here is the whole job of a provider
        // adapter: PromptBuilder produces one shape and never learns about two.
        $system = isset($options['system']) ? (string) $options['system'] : null;

        $payload = [
            'model' => $this->chatModel,
            'messages' => $system === null
                ? $messages
                : [['role' => 'system', 'content' => $system], ...$messages],
            'max_tokens' => (int) ($options['max_tokens'] ?? 1024),
            'temperature' => (float) ($options['temperature'] ?? 0.3),
        ];

        $body = $this->post('/chat/completions', $payload);

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
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function generateEmbeddings(array $texts): array
    {
        $this->assertConfigured();

        if ($texts === []) {
            return [];
        }

        $body = $this->post('/embeddings', [
            'model' => $this->embeddingModel,
            'input' => $texts,
        ]);

        $data = $body['data'] ?? null;

        if (! is_array($data) || count($data) !== count($texts)) {
            throw AIProviderException::malformedResponse(self::NAME, 'embedding count did not match input');
        }

        // Sort by the returned index rather than trusting array order: the
        // contract promises vectors in input order, and a provider that batches
        // internally is entitled to answer out of order.
        usort($data, static fn (mixed $a, mixed $b): int => (int) (is_array($a) ? ($a['index'] ?? 0) : 0)
            <=> (int) (is_array($b) ? ($b['index'] ?? 0) : 0));

        $vectors = [];

        foreach ($data as $row) {
            $vector = is_array($row) ? ($row['embedding'] ?? null) : null;

            if (! is_array($vector)) {
                throw AIProviderException::malformedResponse(self::NAME, 'embedding row had no vector');
            }

            $vectors[] = array_map(static fn (mixed $value): float => (float) $value, array_values($vector));
        }

        return $vectors;
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
                ->withToken($this->apiKey)
                ->timeout($this->timeoutSeconds)
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
        $choice = is_array($body['choices'] ?? null) ? ($body['choices'][0] ?? null) : null;
        $content = is_array($choice) && is_array($choice['message'] ?? null)
            ? ($choice['message']['content'] ?? null)
            : null;

        if (! is_string($content) || trim($content) === '') {
            throw AIProviderException::malformedResponse(self::NAME, 'no assistant message in response');
        }

        $usage = is_array($body['usage'] ?? null) ? $body['usage'] : [];

        return new AIResponse(
            content: trim($content),
            model: is_string($body['model'] ?? null) ? $body['model'] : $this->chatModel,
            promptTokens: (int) ($usage['prompt_tokens'] ?? 0),
            completionTokens: (int) ($usage['completion_tokens'] ?? 0),
            // $choice is already known to be an array: $content came out of it.
            truncated: ($choice['finish_reason'] ?? null) === 'length',
        );
    }

    private function assertConfigured(): void
    {
        if (trim($this->apiKey) === '') {
            throw AIProviderException::unavailable(self::NAME);
        }
    }
}
