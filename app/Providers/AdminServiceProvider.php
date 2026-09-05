<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\AnatomicalStructure;
use App\Models\KnowledgeDocument;
use App\Models\Lesson;
use App\Models\Mission;
use App\Models\Organ;
use App\Models\Question;
use App\Policies\AnatomicalStructurePolicy;
use App\Policies\KnowledgeDocumentPolicy;
use App\Policies\LessonPolicy;
use App\Policies\MissionPolicy;
use App\Policies\OrganPolicy;
use App\Policies\QuestionPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Handover 13. Registers the admin authorization map.
 *
 * Laravel 11 would discover these by naming convention, so this file is
 * technically optional — and is here anyway. The convention is silent when it
 * fails: rename a model or move a policy and every `authorize()` call starts
 * returning "no policy, deny" or, worse for an ability defined via Gate,
 * nothing at all. An explicit map turns that into a class that does not exist,
 * which is a fatal error at boot rather than a permission bug found in
 * production.
 *
 * It also puts the whole admin authorization surface on one screen, which is
 * what an audit of "middleware **and** policy" (docs/architecture.md §14) needs
 * to read.
 */
final class AdminServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    private const POLICIES = [
        Organ::class => OrganPolicy::class,
        AnatomicalStructure::class => AnatomicalStructurePolicy::class,
        Lesson::class => LessonPolicy::class,
        Question::class => QuestionPolicy::class,
        Mission::class => MissionPolicy::class,
        KnowledgeDocument::class => KnowledgeDocumentPolicy::class,
    ];

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }
}
