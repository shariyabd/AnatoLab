<?php

declare(strict_types=1);

namespace App\Services\Simulation;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * One boundary the state can cross, and what it means when it does.
 *
 * `when` is a **single comparison** — `<variable> <operator> <number>` — and
 * that is the entire grammar. It is parsed by one regex into three parts and
 * evaluated by a `match` on the operator, which is exactly what
 * docs/engineering.md §12 asks for: "the simulation is a JSON config and a
 * match expression". There is no expression language, no `eval`, no boolean
 * algebra, and adding any of them would put student-visible behaviour inside a
 * dependency instead of inside this file.
 *
 * The grammar being closed is also what makes a bad configuration a **loud**
 * failure. An unparseable `when` throws while the configuration is being read,
 * so it surfaces to whoever authored it, rather than quietly never firing and
 * surfacing to a student as a simulation that does nothing.
 *
 * Compound conditions are expressed as separate thresholds. Every threshold is
 * evaluated on every step and all matching ones fire, so "output is low **and**
 * oxygenation is low" is two outcomes, which is more useful to explain than
 * one conjunction would have been.
 */
final readonly class SimulationThreshold
{
    /**
     * `output < 0.8`, and nothing more elaborate. Variable names match the
     * keys of `initial_state`; numbers may be negative or fractional.
     */
    private const GRAMMAR = '/^\s*([a-z][a-z0-9_]*)\s*(<=|>=|==|!=|<|>)\s*(-?\d+(?:\.\d+)?)\s*$/i';

    public function __construct(
        public string $expression,
        public string $variable,
        public string $operator,
        public float $value,
        public string $outcome,
        public string $label,
        public ?string $explainKey,
        public bool $terminal,
    ) {}

    /**
     * @param  mixed  $raw
     *
     * @throws InvalidArgumentException when the configuration is unusable
     */
    public static function fromConfig($raw): self
    {
        if (! is_array($raw)) {
            throw new InvalidArgumentException('Each entry in `thresholds` must be an object.');
        }

        $outcome = $raw['outcome'] ?? null;

        if (! is_string($outcome) || trim($outcome) === '') {
            throw new InvalidArgumentException('Every threshold needs a non-empty string `outcome`.');
        }

        $outcome = trim($outcome);
        $expression = $raw['when'] ?? null;

        if (! is_string($expression)) {
            throw new InvalidArgumentException("Threshold `{$outcome}` needs a string `when`.");
        }

        if (preg_match(self::GRAMMAR, $expression, $matches) !== 1) {
            throw new InvalidArgumentException(
                "Threshold `{$outcome}` has an unsupported condition: `{$expression}`. "
                .'The grammar is one comparison — `<variable> <op> <number>` — with op in <, <=, >, >=, ==, !=.'
            );
        }

        $label = $raw['label'] ?? null;
        $explainKey = $raw['explain_key'] ?? null;

        return new self(
            expression: trim($expression),
            variable: strtolower($matches[1]),
            operator: $matches[2],
            value: (float) $matches[3],
            outcome: $outcome,
            label: is_string($label) && trim($label) !== '' ? trim($label) : Str::headline($outcome),
            explainKey: is_string($explainKey) && trim($explainKey) !== '' ? trim($explainKey) : null,
            terminal: ($raw['terminal'] ?? false) === true,
        );
    }

    /**
     * Does this threshold fire for the given state?
     *
     * The absence guard is defensive rather than a feature.
     * SimulationConfiguration has already proved every threshold names a
     * variable in `initial_state`, and no action can create one — so a missing
     * key cannot happen through the parser. If it ever did, not firing is the
     * only reading that keeps a replay deterministic.
     *
     * @param  array<string, float>  $state
     */
    public function firesFor(array $state): bool
    {
        if (! array_key_exists($this->variable, $state)) {
            return false;
        }

        $current = $state[$this->variable];

        return match ($this->operator) {
            '<' => $current < $this->value,
            '<=' => $current <= $this->value,
            '>' => $current > $this->value,
            '>=' => $current >= $this->value,
            '==' => $current === $this->value,
            '!=' => $current !== $this->value,
            // Unreachable: the grammar above admits no other operator. Kept so
            // the match is total and PHPStan does not have to be told so.
            default => false,
        };
    }
}
