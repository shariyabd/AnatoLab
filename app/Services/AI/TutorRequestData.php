<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Enums\TutorTask;

/**
 * A validated tutor request, on its way from a FormRequest into the service.
 *
 * Exists so AITutorService::respond() takes two arguments instead of six, and
 * so nothing ever hands a service a Request or a `$request->all()` array
 * (docs/engineering.md invariant 7).
 *
 * Note what is *not* here: no education level, no lesson title, no organ name.
 * Those are resolved server-side from the authenticated user and from the
 * database. A client-supplied lesson title would be unverified text going
 * straight into a prompt, which is the cheapest prompt-injection surface a
 * tutor can have.
 */
final readonly class TutorRequestData
{
    /**
     * @param  string  $question  the student's own words; empty for an `explain`
     *                            request, which has no question of its own
     * @param  string|null  $organSlug  what is on screen, if anything
     * @param  int|null  $structureId  what is selected, if anything
     * @param  int|null  $conversationId  thread to continue; ownership is checked
     *                                    in the service, never trusted from here
     */
    public function __construct(
        public TutorTask $task,
        public string $question = '',
        public ?string $organSlug = null,
        public ?int $structureId = null,
        public ?int $conversationId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(TutorTask $task, array $validated): self
    {
        return new self(
            task: $task,
            question: trim((string) ($validated['question'] ?? '')),
            organSlug: isset($validated['organSlug']) ? (string) $validated['organSlug'] : null,
            structureId: isset($validated['structureId']) ? (int) $validated['structureId'] : null,
            conversationId: isset($validated['conversationId']) ? (int) $validated['conversationId'] : null,
        );
    }
}
