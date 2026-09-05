<?php

declare(strict_types=1);

namespace App\Services\Simulation;

/**
 * The closed visual vocabulary, and the only thing a simulation may ask the
 * viewer to do (docs/architecture.md §12, resources/js/anatomy/simulation.ts).
 *
 * Five directives: `highlight`, `tint`, `pulseRate`, `focus`, `crossSection`.
 * They were not chosen for expressiveness. They were chosen because a
 * **single-mesh model can actually perform them** (docs/project-context.md
 * §2.2) — there is no per-structure geometry to hide, deform, or animate, so a
 * sixth directive would be one the viewer cannot honour, and a control whose
 * label promises something it does not do is the exact failure the upstream
 * audit found (docs/architecture.md §5.3). If Handover 02 ever lands
 * per-structure models, the vocabulary is extended by a plan change, not here.
 *
 * This class is where the vocabulary is *enforced*, which is why parsing is
 * subtractive: an unknown key is dropped, and so is a known key carrying a
 * value the viewer could not use — a malformed colour, a non-numeric pulse
 * rate, an axis that is not x, y or z. Nothing invalid ever reaches the API
 * Resource, so `visual_directives` provably contains only the five.
 *
 * **Absent and null are different.** `null` means *clear this directive*, and
 * the viewer treats it that way (`'tint' in directives` versus
 * `directives.tint === null`). Absent means *leave it as it was*. Directives
 * accumulate across a run through merge(), so the payload always describes the
 * complete current view rather than a delta the client would have to fold in
 * itself — which is what keeps a replay's final visuals identical to a live
 * run's.
 *
 * Structure references are authored as **slugs** and resolved to opaque
 * structure ids by resolveStructures() before they leave the service. The
 * viewer never parses an id (docs/architecture.md §5.4 rule 4), and a
 * configuration must not embed one: ids are per-database, and a seeded
 * simulation has to survive `migrate:fresh`.
 */
final readonly class VisualDirectives
{
    /**
     * The five permitted keys, in the camelCase the API emits. Mirrors
     * VISUAL_DIRECTIVE_KEYS in resources/js/anatomy/simulation.ts.
     *
     * @var list<string>
     */
    public const KEYS = ['highlight', 'tint', 'pulseRate', 'focus', 'crossSection'];

    /** Config authors in snake_case (docs/architecture.md §12); the API emits camelCase. */
    private const CONFIG_KEYS = [
        'highlight' => 'highlight',
        'tint' => 'tint',
        'pulse_rate' => 'pulseRate',
        'focus' => 'focus',
        'cross_section' => 'crossSection',
    ];

    /** Which directives name a structure and therefore need resolving. */
    private const STRUCTURE_KEYS = ['highlight', 'focus'];

    /**
     * Resting rate is 1. Four is a hummingbird; past that the marker ring
     * reads as a flicker rather than a pulse, and a simulation that asks for
     * it is asking for something the viewer cannot usefully show.
     */
    private const MAX_PULSE_RATE = 4.0;

    private const AXES = ['x', 'y', 'z'];

    /**
     * Only present keys are stored, so array_key_exists() distinguishes
     * "clear" from "unchanged".
     *
     * @param  array<string, mixed>  $values
     */
    private function __construct(private array $values) {}

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * Parse one action's `visual` block from a configuration.
     *
     * Anything this method cannot map onto a directive the viewer honours is
     * dropped rather than approximated. That is the entire contract.
     *
     * @param  mixed  $raw
     */
    public static function fromConfig($raw): self
    {
        if (! is_array($raw)) {
            return self::none();
        }

        $values = [];

        foreach (self::CONFIG_KEYS as $configKey => $key) {
            if (! array_key_exists($configKey, $raw)) {
                continue;
            }

            $value = self::sanitise($key, $raw[$configKey]);

            // Sanitising returns the sentinel when the value is unusable —
            // distinct from null, which is a legitimate "clear this".
            if ($value !== self::REJECTED) {
                $values[$key] = $value;
            }
        }

        return new self($values);
    }

    /**
     * This step's directives laid over everything still in force.
     *
     * Later wins, key by key, including when the later value is null: an
     * action that clears a tint must beat the action that set it.
     */
    public function merge(self $other): self
    {
        return new self([...$this->values, ...$other->values]);
    }

    /**
     * Every structure this set names, ignoring nulls.
     *
     * Slugs before resolveStructures() has run and opaque ids afterwards — the
     * same two keys either way. Used by the service to resolve ids, to fill
     * SimulationState::affectedStructureIds, and by the seeder test to prove a
     * configuration never names a structure its organ does not publish.
     *
     * @return list<string>
     */
    public function structureReferences(): array
    {
        $slugs = [];

        foreach (self::STRUCTURE_KEYS as $key) {
            $value = $this->values[$key] ?? null;

            if (is_string($value) && $value !== '') {
                $slugs[] = $value;
            }
        }

        return array_values(array_unique($slugs));
    }

    /**
     * Swap authored slugs for the opaque ids the viewer round-trips.
     *
     * A slug with no published structure behind it is **dropped**, not nulled.
     * Nulling would clear a highlight the student can still see for a reason
     * that is a content error rather than a simulation event. The seeder test
     * is what stops that being silent: it asserts every slug in every shipped
     * configuration resolves.
     *
     * @param  array<string, string>  $slugToId
     */
    public function resolveStructures(array $slugToId): self
    {
        $values = $this->values;

        foreach (self::STRUCTURE_KEYS as $key) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            $slug = $values[$key];

            if ($slug === null) {
                continue;
            }

            if (is_string($slug) && isset($slugToId[$slug])) {
                $values[$key] = $slugToId[$slug];

                continue;
            }

            unset($values[$key]);
        }

        return new self($values);
    }

    /**
     * The payload, camelCase, five keys at most.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $ordered = [];

        foreach (self::KEYS as $key) {
            if (array_key_exists($key, $this->values)) {
                $ordered[$key] = $this->values[$key];
            }
        }

        return $ordered;
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /**
     * Returned when a value cannot be honoured. A sentinel object rather than
     * null, because null is itself a meaningful directive value.
     */
    private const REJECTED = "\0rejected";

    /**
     * @param  mixed  $value
     * @return mixed
     */
    private static function sanitise(string $key, $value)
    {
        if ($value === null) {
            return null;
        }

        return match ($key) {
            'highlight', 'focus' => is_string($value) && trim($value) !== ''
                ? trim($value)
                : self::REJECTED,
            'tint' => is_string($value) && preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) === 1
                ? strtolower($value)
                : self::REJECTED,
            'pulseRate' => is_int($value) || is_float($value)
                ? round(max(0.0, min(self::MAX_PULSE_RATE, (float) $value)), 3)
                : self::REJECTED,
            'crossSection' => self::sanitiseCrossSection($value),
            default => self::REJECTED,
        };
    }

    /**
     * `true`/`false` toggle the default plane; an object names the axis and
     * the offset. The offset is clamped to the normalised model's own extent —
     * every anchor_position is authored in a FIT_SIZE cube centred on the
     * origin (docs/architecture.md §5.4 rule 1), so a plane beyond half of it
     * either clips everything or nothing.
     *
     * @param  mixed  $value
     * @return mixed
     */
    private static function sanitiseCrossSection($value)
    {
        if (is_bool($value)) {
            return $value;
        }

        if (! is_array($value)) {
            return self::REJECTED;
        }

        $enabled = $value['enabled'] ?? null;

        if (! is_bool($enabled)) {
            return self::REJECTED;
        }

        $section = ['enabled' => $enabled];

        $axis = $value['axis'] ?? null;

        if (is_string($axis)) {
            if (! in_array($axis, self::AXES, true)) {
                return self::REJECTED;
            }

            $section['axis'] = $axis;
        }

        $offset = $value['offset'] ?? null;

        if (is_int($offset) || is_float($offset)) {
            $extent = ((float) config('anatomy.fit_size', 3.8)) / 2.0;
            $section['offset'] = round(max(-$extent, min($extent, (float) $offset)), 3);
        }

        return $section;
    }
}
