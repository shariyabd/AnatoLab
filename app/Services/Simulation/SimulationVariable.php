<?php

declare(strict_types=1);

namespace App\Services\Simulation;

use Illuminate\Support\Str;

/**
 * One measurable value a simulation moves, and the limits it moves between.
 *
 * The bounds are what "clamp the state" means (docs/architecture.md §12).
 * Without them an action applied twice would drive cardiac output negative,
 * and a simulation that can reach an impossible number is not a model of
 * anything.
 *
 * `precision` is not cosmetic. Every step rounds to it after clamping, which
 * is what makes a replay reproduce a run **exactly** rather than approximately:
 * the state survives a JSON round-trip through the database and comes back
 * identical, so a comparison against a stored run is an equality check and not
 * an epsilon.
 *
 * Defaults are the normalised-fraction case the PRD's examples are written in
 * (`valve_closure`, `output`, `oxygenation` are all 0–1), so a configuration
 * only declares a variable when it needs something else.
 */
final readonly class SimulationVariable
{
    private const DEFAULT_MIN = 0.0;

    private const DEFAULT_MAX = 1.0;

    private const DEFAULT_PRECISION = 3;

    /** Enough to keep a readout honest, few enough that rounding is stable. */
    private const MAX_PRECISION = 6;

    public function __construct(
        public string $key,
        public string $label,
        public float $min,
        public float $max,
        public int $precision,
        public ?string $unit = null,
    ) {}

    /**
     * @param  mixed  $raw
     */
    public static function fromConfig(string $key, $raw): self
    {
        $declared = is_array($raw) ? $raw : [];

        $min = self::floatOr($declared['min'] ?? null, self::DEFAULT_MIN);
        $max = self::floatOr($declared['max'] ?? null, self::DEFAULT_MAX);

        // A reversed pair is an authoring slip, not a range. Swapping is
        // recoverable and keeps the clamp meaningful; honouring it as written
        // would clamp every value to a single impossible point.
        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }

        $label = $declared['label'] ?? null;
        $unit = $declared['unit'] ?? null;

        return new self(
            key: $key,
            label: is_string($label) && trim($label) !== '' ? trim($label) : Str::headline($key),
            min: $min,
            max: $max,
            precision: self::precisionOr($declared['precision'] ?? null),
            unit: is_string($unit) && trim($unit) !== '' ? trim($unit) : null,
        );
    }

    /**
     * Clamp to the declared range, then round. In that order: rounding a value
     * that is already at the boundary cannot push it back outside, whereas
     * clamping a rounded value can leave 1.0000000000000002 as 1.0 only by
     * luck.
     */
    public function normalise(float $value): float
    {
        return round(max($this->min, min($this->max, $value)), $this->precision);
    }

    /**
     * @param  mixed  $value
     */
    private static function floatOr($value, float $fallback): float
    {
        return is_int($value) || is_float($value) ? (float) $value : $fallback;
    }

    /**
     * @param  mixed  $value
     */
    private static function precisionOr($value): int
    {
        if (! is_int($value)) {
            return self::DEFAULT_PRECISION;
        }

        return max(0, min(self::MAX_PRECISION, $value));
    }
}
