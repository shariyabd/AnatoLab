<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The four multipliers in the mastery formula (docs/architecture.md §10).
 *
 * An enum rather than four string literals because the recommendation reason
 * is templated per factor, and the templates and the factors have to stay in
 * step: adding a fifth term to the formula should not compile until somebody
 * has written the sentence explaining it to a student.
 *
 * These values reach the client inside a recommendation, so they are contract
 * — mirrored in resources/js/types/progress.ts.
 */
enum MasteryFactor: string
{
    case Accuracy = 'accuracy';
    case Recency = 'recency';
    case HintReliance = 'hint_reliance';
    case Coverage = 'coverage';

    /**
     * What a student should read when this factor is the weakest one.
     *
     * Templated, never generated. docs/architecture.md §10 is explicit that
     * the LLM does not write recommendation reasons, and PRD §15's example
     * sentence is the shape these follow: name the strength, name the gap.
     */
    public function reason(): string
    {
        return match ($this) {
            self::Accuracy => 'Your answers here are not landing yet — work through the '
                .'explanations rather than guessing.',
            self::Recency => 'You knew this once. It has been a while, and a short '
                .'refresher will bring it back faster than starting something new.',
            self::HintReliance => 'You are getting these right, but mostly with a hint. '
                .'Try a round without opening one.',
            self::Coverage => 'You are strong on the parts you have practised, but you have '
                .'only met a few of the structures here — the rest are still unseen.',
        };
    }
}
