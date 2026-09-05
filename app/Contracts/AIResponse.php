<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * One completion from an AIProviderInterface.
 *
 * Provider-agnostic on purpose: no raw payload, no provider-specific finish
 * codes. If a caller needs something not on this class, normalise it here
 * rather than reaching for the provider's own response shape — that reach is
 * what makes providers non-swappable.
 */
final readonly class AIResponse
{
    /**
     * @param  string  $content  the assistant's message text
     * @param  string  $model  the model that produced it, for logging and analytics
     * @param  int  $promptTokens  0 when the provider does not report usage
     * @param  int  $completionTokens  0 when the provider does not report usage
     * @param  bool  $truncated  true when the provider stopped at a token limit
     *                           rather than finishing its answer
     */
    public function __construct(
        public string $content,
        public string $model,
        public int $promptTokens = 0,
        public int $completionTokens = 0,
        public bool $truncated = false,
    ) {}

    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }
}
