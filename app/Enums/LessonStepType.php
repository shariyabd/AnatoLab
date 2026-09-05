<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The six step kinds a lesson is built from (PRD §8).
 *
 * The sequence in PRD §8 is objective → 3D exploration → guided explanation →
 * interactive activity → knowledge check → reflection. A lesson may skip a
 * kind or repeat one; the order is the author's, held by the position of the
 * step in `lessons.content`, not by this enum.
 *
 * PRD §8 lists a seventh item, "mastery update". It is not a step: nothing is
 * rendered for it and the student does nothing. Mastery is F10's, recalculated
 * from the `lesson_progress` row this lane writes.
 *
 * Each case names the Vue component that renders it. That mapping is what
 * keeps the step renderer data-driven — the page looks the type up in a table
 * instead of branching on it (invariant 8, docs/engineering.md §4).
 */
enum LessonStepType: string
{
    /** What the student will be able to do afterwards. */
    case Objective = 'objective';

    /** Named structures to find on the model, with the viewer mounted. */
    case Exploration = 'exploration';

    /** The teaching prose itself, in short titled blocks. */
    case Explanation = 'explanation';

    /** Something to do — trace a path, tick off structures as they are found. */
    case Activity = 'activity';

    /**
     * A slot for one of F07's questions.
     *
     * This lane owns the step's *placement*; the question, its options and its
     * grading belong to the assessment engine
     * (docs/handovers/06-lessons.md, "Out of scope"). The payload therefore
     * carries a reference and a prompt, never an answer.
     */
    case KnowledgeCheck = 'knowledge_check';

    /** An open question with no right answer, and nothing to grade. */
    case Reflection = 'reflection';

    public function label(): string
    {
        return match ($this) {
            self::Objective => 'Objective',
            self::Exploration => '3D exploration',
            self::Explanation => 'Explanation',
            self::Activity => 'Activity',
            self::KnowledgeCheck => 'Knowledge check',
            self::Reflection => 'Reflection',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
