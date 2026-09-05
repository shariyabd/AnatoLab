<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Lesson;
use App\Services\Lessons\LessonService;

/**
 * Keeps the `lesson:{slug}` cache honest (docs/architecture.md §11).
 *
 * Same reasoning as OrganObserver: clearing the cache at every write site
 * fails the first time an admin edits a lesson through a path nobody
 * anticipated, and F13 is going to add several.
 */
final class LessonObserver
{
    public function __construct(private readonly LessonService $lessons) {}

    public function saved(Lesson $lesson): void
    {
        $this->lessons->forgetLesson($lesson);
    }

    public function deleted(Lesson $lesson): void
    {
        $this->lessons->forgetLesson($lesson);
    }
}
