<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Every event the learning log records (PRD §29, docs/architecture.md §13).
 *
 * The important method here is `isClientReportable()`. Four of these events
 * happen only in the browser — the student rotated to an organ, picked a
 * structure, isolated it, changed the layer — and nothing on the server can
 * observe them, so the client batches and posts them. The other nine happen on
 * the server, where they are recorded at the point they occur.
 *
 * That split is a security boundary, not a tidiness one. If the client could
 * post `question_answered` or `mission_completed`, a student could manufacture
 * the evidence that XP, badges and the learning metrics in PRD §30 are
 * computed from. `App\Http\Requests\Progress\RecordEventsRequest` validates
 * against `clientReportable()` and nothing else.
 *
 * Values are the strings in `learning_events.event_type` and in the batch the
 * browser sends, so they are contract — mirrored in
 * resources/js/types/progress.ts.
 */
enum LearningEventType: string
{
    // Client-reported: viewer interactions with no server-side trace.
    case OrganViewed = 'organ_viewed';
    case StructureSelected = 'structure_selected';
    case StructureIsolated = 'structure_isolated';
    case LayerChanged = 'layer_changed';

    // Server-recorded: written where they happen.
    case LessonStarted = 'lesson_started';
    case LessonCompleted = 'lesson_completed';
    case QuestionAnswered = 'question_answered';
    case MissionStarted = 'mission_started';
    case MissionCompleted = 'mission_completed';
    case HintRequested = 'hint_requested';
    case AiQuestionAsked = 'ai_question_asked';
    case SimulationStarted = 'simulation_started';
    case SimulationCompleted = 'simulation_completed';

    /**
     * May the browser assert this event about itself?
     *
     * True only where the server has no other way to know. Everything that
     * feeds a score, a badge or a completion metric answers false.
     */
    public function isClientReportable(): bool
    {
        return match ($this) {
            self::OrganViewed,
            self::StructureSelected,
            self::StructureIsolated,
            self::LayerChanged => true,
            default => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function clientReportable(): array
    {
        return array_values(array_map(
            static fn (self $case): string => $case->value,
            array_filter(self::cases(), static fn (self $case): bool => $case->isClientReportable()),
        ));
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
