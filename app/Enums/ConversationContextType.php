<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a tutor conversation is anchored to.
 *
 * Stored on `conversations.context_type` alongside a nullable `context_id`
 * rather than as a polymorphic relation: the tutor never loads the context
 * model back: it only needs to know what the student was looking at when the
 * thread started, so it can group history and pre-fill the next question.
 * A morphTo here would buy a relation nothing calls.
 */
enum ConversationContextType: string
{
    /** Anchored to an organ the student is exploring. */
    case Organ = 'organ';

    /** Anchored to one selected structure — the most common case (PRD §9). */
    case Structure = 'structure';

    /** Anchored to a lesson in progress. Handover 06 supplies the id. */
    case Lesson = 'lesson';

    /** No anchor: the student opened the tutor on its own. */
    case General = 'general';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
