<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A vector store could not be read or written.
 *
 * Same contract as AIProviderException: the store name and upstream detail are
 * logged, never rendered. A retrieval failure degrades the tutor to an
 * ungrounded answer or a plain "I could not look that up" — it never surfaces
 * an index name or a 500 body to a student.
 */
final class VectorStoreException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $storeName,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function unavailable(string $storeName, ?Throwable $previous = null): self
    {
        return new self("Vector store [{$storeName}] is unreachable.", $storeName, $previous);
    }

    public static function dimensionMismatch(string $storeName, int $expected, int $actual): self
    {
        return new self(
            "Vector store [{$storeName}] expects {$expected}-dimension vectors, got {$actual}.",
            $storeName,
        );
    }
}
