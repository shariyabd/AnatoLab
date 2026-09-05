<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a knowledge document is in the ingest pipeline
 * (docs/architecture.md §8.3).
 *
 * `pending → processing → indexed | failed`, and nothing advances it inside a
 * web request: KnowledgeService returns at `pending` and the queue does the
 * rest. `failed` is terminal until an admin re-ingests, which starts a new
 * version rather than resuming the old one — a half-chunked document is not a
 * state worth resuming from.
 */
enum KnowledgeDocumentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Indexed = 'indexed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Queued',
            self::Processing => 'Processing',
            self::Indexed => 'Indexed',
            self::Failed => 'Failed',
        };
    }

    /**
     * Whether the pipeline is still expected to move this document on.
     */
    public function isInFlight(): bool
    {
        return $this === self::Pending || $this === self::Processing;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
