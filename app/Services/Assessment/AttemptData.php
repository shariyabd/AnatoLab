<?php

declare(strict_types=1);

namespace App\Services\Assessment;

/**
 * A validated attempt, on its way from a FormRequest into the service.
 *
 * Exists so AssessmentService::recordAttempt() takes three arguments instead
 * of eight, and so nothing hands a service a Request or a `$request->all()`
 * array (invariant 7).
 *
 * Note what is *not* here: no correctness of any kind. The client states what
 * it picked and how long it took; whether that was right is decided from the
 * question's own answer key, server-side, always
 * (docs/architecture.md §5.4 rule 2).
 */
final readonly class AttemptData
{
    public function __construct(
        public int $questionId,
        public ?int $selectedStructureId = null,
        public ?int $selectedOptionId = null,
        public string $answerText = '',
        public ?int $timeSpentMs = null,
        public bool $hintUsed = false,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            questionId: (int) $validated['questionId'],
            selectedStructureId: isset($validated['selectedStructureId'])
                ? (int) $validated['selectedStructureId']
                : null,
            selectedOptionId: isset($validated['selectedOptionId'])
                ? (int) $validated['selectedOptionId']
                : null,
            answerText: trim((string) ($validated['answerText'] ?? '')),
            timeSpentMs: isset($validated['timeSpentMs']) ? (int) $validated['timeSpentMs'] : null,
            hintUsed: (bool) ($validated['hintUsed'] ?? false),
        );
    }
}
