<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * An AI provider could not be reached, refused the request, or returned
 * something unusable.
 *
 * Providers wrap every upstream failure in this so the rest of the application
 * never sees a provider-specific exception — that is what keeps providers
 * swappable. The handler renders it as an educational-tone message; the
 * provider name, upstream status code, and body stay in the log
 * (docs/architecture.md §14).
 */
final class AIProviderException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $providerName,
        public readonly ?int $upstreamStatus = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function unavailable(string $providerName, ?Throwable $previous = null): self
    {
        return new self(
            message: "AI provider [{$providerName}] is unreachable.",
            providerName: $providerName,
            previous: $previous,
        );
    }

    public static function rejected(string $providerName, int $status, string $detail = ''): self
    {
        return new self(
            message: trim("AI provider [{$providerName}] returned {$status}. {$detail}"),
            providerName: $providerName,
            upstreamStatus: $status,
        );
    }

    public static function malformedResponse(string $providerName, string $detail): self
    {
        return new self(
            message: "AI provider [{$providerName}] returned an unusable response: {$detail}",
            providerName: $providerName,
        );
    }
}
