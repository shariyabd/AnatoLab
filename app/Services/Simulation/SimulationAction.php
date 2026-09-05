<?php

declare(strict_types=1);

namespace App\Services\Simulation;

use InvalidArgumentException;

/**
 * One thing a student can do to a simulation (docs/architecture.md §12).
 *
 * An action is a *delta*, not a destination: `effects` are added to the
 * current state and the result is clamped, so applying the same action twice
 * is meaningful and applying its inverse afterwards returns exactly where the
 * run started. Absolute targets would make the order of a run irrelevant,
 * which is the one thing a "what happens if" is supposed to teach.
 *
 * `visual` is the closed five-directive vocabulary and nothing else; see
 * VisualDirectives, which drops anything the viewer cannot honour.
 *
 * An action carries no explanation of its own. Explanations belong to the
 * *outcome* — what the state became — because that is what the student needs
 * explained, and because two different actions can reach the same outcome.
 */
final readonly class SimulationAction
{
    /**
     * @param  array<string, float>  $effects  variable key → delta
     */
    public function __construct(
        public string $id,
        public string $label,
        public ?string $description,
        public array $effects,
        public VisualDirectives $visual,
    ) {}

    /**
     * @param  mixed  $raw
     *
     * @throws InvalidArgumentException when the configuration is unusable
     */
    public static function fromConfig($raw): self
    {
        if (! is_array($raw)) {
            throw new InvalidArgumentException('Each entry in `actions` must be an object.');
        }

        $id = $raw['id'] ?? null;

        if (! is_string($id) || trim($id) === '') {
            throw new InvalidArgumentException('Every action needs a non-empty string `id`.');
        }

        $label = $raw['label'] ?? null;

        if (! is_string($label) || trim($label) === '') {
            throw new InvalidArgumentException("Action `{$id}` needs a non-empty string `label`.");
        }

        $description = $raw['description'] ?? null;

        return new self(
            id: trim($id),
            label: trim($label),
            description: is_string($description) && trim($description) !== '' ? trim($description) : null,
            effects: self::effects($id, $raw['effects'] ?? []),
            visual: VisualDirectives::fromConfig($raw['visual'] ?? null),
        );
    }

    /**
     * @param  mixed  $raw
     * @return array<string, float>
     */
    private static function effects(string $id, $raw): array
    {
        if (! is_array($raw)) {
            throw new InvalidArgumentException("Action `{$id}`'s `effects` must be an object.");
        }

        $effects = [];

        foreach ($raw as $key => $delta) {
            if (! is_string($key)) {
                throw new InvalidArgumentException("Action `{$id}` has a non-string effect key.");
            }

            if (! is_int($delta) && ! is_float($delta)) {
                throw new InvalidArgumentException("Action `{$id}`'s effect on `{$key}` must be a number.");
            }

            $effects[$key] = (float) $delta;
        }

        return $effects;
    }
}
