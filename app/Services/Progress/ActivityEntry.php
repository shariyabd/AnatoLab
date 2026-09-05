<?php

declare(strict_types=1);

namespace App\Services\Progress;

use App\Enums\LearningEventType;
use Carbon\CarbonImmutable;

/**
 * One line of the dashboard's recent-activity list (PRD §18).
 *
 * The label is resolved server-side and arrives as finished prose. The
 * alternative — shipping the event type and the context id and letting the
 * page look up the organ's name — would put a query in a template
 * (invariant 8) or an N+1 in a Resource.
 *
 * The raw `payload` never leaves the server. It exists for the metrics in
 * PRD §30, not for display, and it carries the outcome of graded answers.
 */
final readonly class ActivityEntry
{
    public function __construct(
        public LearningEventType $type,
        public string $label,
        public CarbonImmutable $occurredAt,
    ) {}
}
