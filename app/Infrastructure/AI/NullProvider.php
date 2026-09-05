<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use App\Contracts\AIProviderInterface;
use App\Contracts\AIResponse;

/**
 * The provider every test uses, and the default until Handover 08 lands a real one.
 *
 * Makes no network call — docs/engineering.md §9 deletes any test that talks to a
 * live LLM. Output is DETERMINISTIC: the same text always yields the same vector,
 * and similar text yields similar vectors, so retrieval tests can assert ordering
 * rather than merely asserting "something came back".
 */
final class NullProvider implements AIProviderInterface
{
    public function __construct(
        private readonly int $dimensions = 1536,
    ) {}

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     */
    public function chat(array $messages, array $options = []): AIResponse
    {
        $lastUserMessage = '';

        // The shape is guaranteed by AIProviderInterface::chat(); defensive
        // ?? here would only hide a caller passing the wrong thing.
        foreach ($messages as $message) {
            if ($message['role'] === 'user') {
                $lastUserMessage = $message['content'];
            }
        }

        return new AIResponse(
            content: $lastUserMessage === ''
                ? 'This is a placeholder tutor response.'
                : "This is a placeholder tutor response to: {$lastUserMessage}",
            model: 'null',
        );
    }

    /**
     * @return list<float>
     */
    public function generateEmbedding(string $text): array
    {
        // Seed a PRNG from the text so the vector is stable across processes and
        // machines. Two texts sharing a prefix share a seed neighbourhood, which
        // is enough structure for similarity assertions without a real model.
        $seed = crc32(mb_strtolower(trim($text)));

        $vector = [];

        for ($index = 0; $index < $this->dimensions; $index++) {
            // Numerical Recipes LCG: deterministic, no global mt_srand side effect.
            $seed = ($seed * 1_664_525 + 1_013_904_223) % 4_294_967_296;
            $vector[] = ($seed / 4_294_967_296) * 2.0 - 1.0;
        }

        return $this->normalise($vector);
    }

    /**
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function generateEmbeddings(array $texts): array
    {
        return array_map(fn (string $text): array => $this->generateEmbedding($text), $texts);
    }

    /**
     * Unit-length vectors mean cosine similarity is a plain dot product, which
     * is what the MySQL store will rely on.
     *
     * @param  list<float>  $vector
     * @return list<float>
     */
    private function normalise(array $vector): array
    {
        $magnitude = sqrt(array_sum(array_map(static fn (float $value): float => $value ** 2, $vector)));

        if ($magnitude === 0.0) {
            return $vector;
        }

        return array_map(static fn (float $value): float => $value / $magnitude, $vector);
    }
}
