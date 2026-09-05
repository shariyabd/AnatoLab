<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who said a stored conversation message.
 *
 * There is deliberately no `system` case. The system prompt is regenerated from
 * PromptBuilder on every request and is a property of the application's current
 * version, not of the conversation; persisting it would store a template
 * thousands of times and make a prompt change look like history being rewritten.
 */
enum MessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
