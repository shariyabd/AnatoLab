<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\AIProviderInterface;
use App\Contracts\AIResponse;
use App\Exceptions\AIProviderException;
use RuntimeException;

/**
 * A provider that records what it was asked, and behaves as the test says.
 *
 * Handover 12 swaps AIProviderInterface rather than AITutorService: the tutor
 * belongs to Handover 08 and Handover 11's retrieval already sits at its seam,
 * so a mock of the service would be a mock of two lanes' code and would stop
 * proving that the real pipeline is what runs
 * (docs/handovers/parallel-execution-plan.md D2). The binding is the seam the
 * platform already provides — `ContractBindingTest` exists because of it.
 *
 * `forbid` is the important mode. Several assertions in this lane are that a
 * provider is **not** reached — for curated content, and on a page load — and
 * a silent no-op double cannot prove that. This one throws, so the test fails
 * loudly at the call rather than passing on an assertion about its absence.
 */
final class RecordingProvider implements AIProviderInterface
{
    /** @var list<array{role: string, content: string}> */
    public array $seen = [];

    private function __construct(
        private readonly string $mode,
        private readonly string $answer,
    ) {}

    public static function answering(string $answer = 'A generated educational explanation.'): self
    {
        return new self('answer', $answer);
    }

    /** Fails the way a real outage does: the exception bootstrap/app.php knows. */
    public static function failing(): self
    {
        return new self('fail', '');
    }

    /** Any call is a test failure. */
    public static function forbidden(): self
    {
        return new self('forbid', '');
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     */
    public function chat(array $messages, array $options = []): AIResponse
    {
        $this->seen = $messages;

        if ($this->mode === 'forbid') {
            throw new RuntimeException('The provider must not be reached on this path.');
        }

        if ($this->mode === 'fail') {
            throw AIProviderException::rejected('broken', 503, 'Upstream is down.');
        }

        return new AIResponse(
            content: $this->answer,
            model: 'recording',
            promptTokens: 1,
            completionTokens: 1,
        );
    }

    /**
     * @return list<float>
     */
    public function generateEmbedding(string $text): array
    {
        return [0.0];
    }

    /**
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function generateEmbeddings(array $texts): array
    {
        return array_map(static fn (): array => [0.0], array_values($texts));
    }

    /** Everything the provider was sent last, flattened for a substring assertion. */
    public function prompt(): string
    {
        return implode("\n", array_column($this->seen, 'content'));
    }
}
