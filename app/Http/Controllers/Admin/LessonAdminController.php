<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\DifficultyPreference;
use App\Enums\LessonStatus;
use App\Enums\LessonStepType;
use App\Enums\OrganStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreLessonRequest;
use App\Http\Requests\Admin\UpdateLessonRequest;
use App\Http\Resources\Admin\AdminLessonResource;
use App\Models\Lesson;
use App\Models\Organ;
use App\Services\Lessons\LessonService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lesson CRUD and publication.
 *
 * Acceptance criterion 2: an admin publishes a lesson and it becomes visible
 * to students. `status()` is that act, and LessonObserver clears the
 * `lesson:{slug}` cache on save so the change is visible immediately rather
 * than after the hour's TTL (docs/architecture.md §11).
 */
final class LessonAdminController extends Controller
{
    public function __construct(private readonly LessonService $lessons) {}

    public function index(): Response
    {
        Gate::authorize('viewAny', Lesson::class);

        return Inertia::render('Admin/Lessons/Index', [
            'lessons' => AdminLessonResource::collection($this->lessons->paginateAll()),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Lesson::class);

        return Inertia::render('Admin/Lessons/Edit', [
            'lesson' => null,
            'options' => $this->formOptions(),
        ]);
    }

    public function store(StoreLessonRequest $request): RedirectResponse
    {
        $lesson = $this->lessons->create($request->validated());

        return redirect()
            ->route('admin.lessons.edit', $lesson)
            ->with('success', "“{$lesson->title}” created as a draft.");
    }

    public function edit(Lesson $lesson): Response
    {
        Gate::authorize('update', $lesson);

        return Inertia::render('Admin/Lessons/Edit', [
            'lesson' => new AdminLessonResource($lesson->loadMissing('organ')),
            'options' => $this->formOptions(),
        ]);
    }

    public function update(UpdateLessonRequest $request, Lesson $lesson): RedirectResponse
    {
        $this->lessons->update($lesson, $request->validated());

        return back()->with('success', 'Saved.');
    }

    public function status(Request $request, Lesson $lesson): RedirectResponse
    {
        Gate::authorize('publish', $lesson);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(LessonStatus::class)],
        ]);

        $status = LessonStatus::from($validated['status']);

        $this->lessons->setStatus($lesson, $status);

        // A published lesson on a draft organ is still hidden — listPublished()
        // requires both. Saying so here saves an admin wondering why the lesson
        // they just published is not in the library.
        $organIsDraft = $status === LessonStatus::Published
            && $lesson->loadMissing('organ')->organ->status !== OrganStatus::Published;

        return back()->with('success', match (true) {
            $organIsDraft => "“{$lesson->title}” is published, but its organ is still a draft — students will not see it yet.",
            $status === LessonStatus::Published => "“{$lesson->title}” is now visible to students.",
            default => "“{$lesson->title}” is back to draft.",
        });
    }

    public function destroy(Lesson $lesson): RedirectResponse
    {
        Gate::authorize('delete', $lesson);

        $this->lessons->delete($lesson);

        return redirect()
            ->route('admin.lessons.index')
            ->with('success', "“{$lesson->title}” deleted.");
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'organs' => Organ::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(static fn (Organ $organ): array => [
                    'id' => (string) $organ->id,
                    'name' => $organ->name,
                ])
                ->all(),
            'difficulties' => DifficultyPreference::values(),
            'stepTypes' => LessonStepType::values(),
            'statuses' => LessonStatus::values(),
        ];
    }
}
