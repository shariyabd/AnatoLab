<?php

declare(strict_types=1);

namespace App\Services\Lessons;

use App\Enums\DifficultyPreference;

/**
 * The three filters the lesson library supports (docs/handovers/06-lessons.md).
 *
 * A typed object rather than three nullable parameters because all three are
 * optional strings and a positional call site — `list(null, null, 'beginner')`
 * — reads as nothing at all. It also gives the FormRequest one thing to
 * produce and the service one thing to consume, which is what keeps a Request
 * out of the service (invariant 1).
 */
final readonly class LessonFilters
{
    public function __construct(
        public ?string $organSlug = null,
        public ?string $systemSlug = null,
        public ?DifficultyPreference $difficulty = null,
    ) {}

    /**
     * Build from already-validated input.
     *
     * Takes the validated array, never the Request: the caller is a
     * FormRequest, and handing the service a Request would make it
     * unqueueable and untestable (docs/engineering.md §3).
     *
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        $difficulty = $validated['difficulty'] ?? null;

        return new self(
            organSlug: self::string($validated['organ'] ?? null),
            systemSlug: self::string($validated['system'] ?? null),
            difficulty: is_string($difficulty) ? DifficultyPreference::tryFrom($difficulty) : null,
        );
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
