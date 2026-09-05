<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a recommendation asks the student to actually do next.
 *
 * `RecommendationService` picks a *topic* from mastery and then an *activity*
 * within it (docs/architecture.md §10). This is the second half: the two kinds
 * of unattempted content that exist today, both of which are already routable
 * pages so a recommendation card is a link and not a dead end.
 *
 * Missions (Handover 09) and simulations (Handover 12) are deliberately absent
 * rather than stubbed. A case here with no route behind it would render a card
 * that 404s, and both lanes are being built in parallel with this one — adding
 * the case is theirs to do when their routes exist.
 */
enum RecommendedActivity: string
{
    case Lesson = 'lesson';
    case Quiz = 'quiz';

    public function label(): string
    {
        return match ($this) {
            self::Lesson => 'Lesson',
            self::Quiz => 'Quiz',
        };
    }
}
