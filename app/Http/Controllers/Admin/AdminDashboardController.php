<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\QuestionStatus;
use App\Http\Controllers\Controller;
use App\Models\AnatomicalStructure;
use App\Models\KnowledgeDocument;
use App\Models\Lesson;
use App\Models\Mission;
use App\Models\Organ;
use App\Models\Question;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The admin area's landing page.
 *
 * Renders the content sections this administrator is authorized for. The `can`
 * flags come from the policies, not from `role === 'admin'` in the template: a
 * page that decides its own permissions is a second authorization system that
 * will disagree with the first one eventually (docs/engineering.md §10,
 * invariant 8 — templates display, they do not query).
 *
 * The explicit `Gate::authorize()` below is not redundant with the `admin`
 * middleware on the route. Middleware guards the area; this guards the action,
 * and it is what makes the check survive the route being moved into another
 * group (docs/architecture.md §14).
 */
final class AdminDashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        Gate::authorize('viewAny', Organ::class);

        return Inertia::render('Admin/Index', [
            'sections' => [
                [
                    'key' => 'organs',
                    'label' => 'Organs',
                    'description' => 'Models, metadata and publication state.',
                    'href' => route('admin.organs.index'),
                    'can' => Gate::allows('viewAny', Organ::class),
                    'badge' => null,
                ],
                [
                    'key' => 'structures',
                    'label' => 'Structures',
                    'description' => 'Open an organ to author its hotspots on the model.',
                    'href' => route('admin.organs.index'),
                    'can' => Gate::allows('viewAny', AnatomicalStructure::class),
                    'badge' => null,
                ],
                [
                    'key' => 'lessons',
                    'label' => 'Lessons',
                    'description' => 'Lesson content and publication state.',
                    'href' => route('admin.lessons.index'),
                    'can' => Gate::allows('viewAny', Lesson::class),
                    'badge' => null,
                ],
                [
                    'key' => 'questions',
                    'label' => 'Questions',
                    'description' => 'The question bank and the AI review queue.',
                    'href' => route('admin.questions.index'),
                    'can' => Gate::allows('viewAny', Question::class),
                    // The one number worth surfacing on a landing page: a
                    // backlog here means generated content is waiting on a
                    // human before it can reach a student (PRD §24).
                    'badge' => $this->awaitingReview(),
                ],
                [
                    'key' => 'missions',
                    'label' => 'Missions',
                    'description' => 'Mission definitions and their target sequences.',
                    'href' => route('admin.missions.index'),
                    'can' => Gate::allows('viewAny', Mission::class),
                    'badge' => null,
                ],
                [
                    'key' => 'knowledge',
                    'label' => 'Knowledge documents',
                    'description' => 'RAG sources and their ingestion status.',
                    'href' => route('admin.knowledge.index'),
                    'can' => Gate::allows('viewAny', KnowledgeDocument::class),
                    'badge' => null,
                ],
                [
                    'key' => 'analytics',
                    'label' => 'Analytics',
                    'description' => 'What students are actually doing, and where they struggle.',
                    'href' => route('admin.analytics'),
                    'can' => Gate::allows('viewAny', Question::class),
                    'badge' => null,
                ],
            ],
        ]);
    }

    /**
     * How many AI-generated questions are waiting for approval.
     *
     * Read here rather than through AssessmentService: it is one indexed count
     * for a badge, and paginateReviewQueue() would load and hydrate the rows to
     * discard all but their number.
     */
    private function awaitingReview(): ?int
    {
        $count = Question::query()
            ->where('status', QuestionStatus::Review)
            ->where('generated_by_ai', true)
            ->count();

        return $count > 0 ? $count : null;
    }
}
