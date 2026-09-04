<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Everything the tutor is allowed to know about who is asking and where they
 * are, assembled by AIContextBuilder and consumed by PromptBuilder.
 *
 * Deliberately scalars and arrays rather than Eloquent models: this object is
 * serialised into a queued job and into a prompt. Passing models would drag
 * relations, timestamps, and a password hash along with it.
 *
 * It must never carry an answer key. Recent mistakes are recorded as the
 * structure that was confused, never as the correct response (invariant 4).
 */
final readonly class LearningContext
{
    /**
     * @param  int  $userId  scoping and rate limiting; never rendered
     * @param  string  $educationLevel  drives reading level, PRD §11
     * @param  string  $difficultyPreference  beginner|intermediate|advanced
     * @param  string|null  $organName  what the student is looking at, if anything
     * @param  string|null  $structureName  what is selected, if anything
     * @param  string|null  $lessonTitle  the lesson in progress, if any
     * @param  list<string>  $recentMistakes  structure names recently confused —
     *                                        names only, so the prompt cannot leak a correct answer
     * @param  list<string>  $masteryGaps  body-system slugs scoring below target
     */
    public function __construct(
        public int $userId,
        public string $educationLevel,
        public string $difficultyPreference,
        public ?string $organName = null,
        public ?string $structureName = null,
        public ?string $lessonTitle = null,
        public array $recentMistakes = [],
        public array $masteryGaps = [],
    ) {}

    /**
     * Metadata filters for a retrieval call scoped to what is on screen.
     *
     * @return array<string, scalar|null>
     */
    public function retrievalFilters(): array
    {
        return ['education_level' => $this->educationLevel];
    }
}
