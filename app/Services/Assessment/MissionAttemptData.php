<?php

declare(strict_types=1);

namespace App\Services\Assessment;

/**
 * One whole run at a mission, validated, on its way into `MissionService`.
 *
 * A mission is submitted once, at the end, rather than a step at a time. That
 * is what docs/architecture.md §7 specifies
 * (`POST /missions/{mission}/attempt → { score, per_step, feedback }`) and it
 * is also what keeps the target sequence server-side: a per-step endpoint
 * would have to say "right" or "wrong" after each click, and a student who
 * clicked every structure in turn would read the whole pathway out of the
 * responses one 200 at a time.
 *
 * Exists so `MissionService::score()` takes three arguments rather than a
 * request array, and so nothing hands a service a Request or a
 * `$request->all()` (invariant 7).
 *
 * Steps are positional: entry *n* answers configured step *n*. A short list is
 * not an error — the missing steps are scored as misses with no pick, which is
 * what abandoning a mission halfway looks like.
 */
final readonly class MissionAttemptData
{
    /**
     * @param  list<MissionStepSubmission>  $steps
     */
    public function __construct(
        public array $steps = [],
        public ?int $durationMs = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        /** @var array<array-key, mixed> $rawSteps */
        $rawSteps = is_array($validated['steps'] ?? null) ? $validated['steps'] : [];

        $steps = [];

        foreach ($rawSteps as $rawStep) {
            $steps[] = MissionStepSubmission::fromValidated(is_array($rawStep) ? $rawStep : []);
        }

        $durationMs = $validated['durationMs'] ?? null;

        return new self(
            steps: $steps,
            durationMs: is_numeric($durationMs) ? (int) $durationMs : null,
        );
    }

    public function step(int $index): ?MissionStepSubmission
    {
        return $this->steps[$index] ?? null;
    }
}
