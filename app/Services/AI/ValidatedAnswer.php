<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * The outcome of running a model response past AIResponseValidator.
 *
 * `violation` is a short reason code for the log and for message metadata. It
 * is never rendered: telling a student "your answer was blocked for
 * clinical_advice" invites them to rephrase until it is not.
 */
final readonly class ValidatedAnswer
{
    public function __construct(
        public string $answer,
        /** True when the model's text was discarded for the safe fallback. */
        public bool $replaced = false,
        /** True when the answer was cut to the length ceiling. */
        public bool $trimmed = false,
        public ?string $violation = null,
    ) {}
}
