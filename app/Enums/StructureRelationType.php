<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How one structure relates to another.
 *
 * A closed vocabulary rather than free text because the AI context builder
 * (Handover 08) renders these as prose — "the left ventricle ejects into the
 * aorta" — and a typo in a free-text column becomes a sentence a student reads.
 */
enum StructureRelationType: string
{
    /** Physically neighbouring, with no directional flow implied. */
    case Adjacent = 'adjacent';

    /** The subject is anatomically contained within the related structure. */
    case PartOf = 'part_of';

    /** Continuous with it — flow, air, or signal passes from subject to related. */
    case FlowsInto = 'flows_into';

    /** Paired across the midline, or the functional counterpart of the subject. */
    case Counterpart = 'counterpart';

    public function label(): string
    {
        return match ($this) {
            self::Adjacent => 'Adjacent to',
            self::PartOf => 'Part of',
            self::FlowsInto => 'Flows into',
            self::Counterpart => 'Counterpart of',
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
