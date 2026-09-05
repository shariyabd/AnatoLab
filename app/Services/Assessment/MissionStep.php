<?php

declare(strict_types=1);

namespace App\Services\Assessment;

/**
 * One authored step of a mission, as it exists server-side.
 *
 * Two of these four fields are safe to send to a browser and two are not, and
 * keeping them in one object is deliberate: the split is made once, in
 * App\Http\Resources\Missions\MissionStepResource, rather than being re-decided
 * everywhere a step is touched.
 *
 * - `prompt` and `hint` are what the student is shown.
 * - `targetSlug` is the answer. It never leaves the server (invariant 4).
 * - `explanation` names the target in prose, so it is an answer key that
 *   happens to be readable — the same thing `questions.explanation` is. It
 *   travels with the graded result, never with the mission
 *   (docs/architecture.md §9).
 *
 * The slug rather than a structure id, because that is what
 * docs/architecture.md §9 authors — `"structure_id": "left-atrium"` — and
 * because slugs survive a `migrate:fresh --seed` while auto-increment ids do
 * not. Slugs are unique per organ, not globally, so every resolution is scoped
 * to the mission's own organ (App\Services\Anatomy\AnatomyService makes the
 * same point about `apex`).
 */
final readonly class MissionStep
{
    public function __construct(
        public string $targetSlug,
        public string $prompt,
        public ?string $hint = null,
        public ?string $explanation = null,
    ) {}

    /**
     * Parse one authored step, or null if it names no target.
     *
     * A step with no target slug cannot be scored, and inventing one would
     * make a mission that is silently unwinnable. The caller drops it and, if
     * that leaves the mission empty, stops serving the mission at all
     * (MissionConfiguration).
     *
     * @param  array<array-key, mixed>  $step
     */
    public static function fromArray(array $step): ?self
    {
        $target = $step['structure_id'] ?? null;
        $prompt = $step['prompt'] ?? null;

        if (! is_string($target) || $target === '' || ! is_string($prompt) || $prompt === '') {
            return null;
        }

        return new self(
            targetSlug: $target,
            prompt: $prompt,
            hint: self::text($step['hint'] ?? null),
            explanation: self::text($step['explanation'] ?? null),
        );
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
