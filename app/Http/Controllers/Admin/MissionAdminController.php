<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\MissionStatus;
use App\Enums\MissionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreMissionRequest;
use App\Http\Requests\Admin\UpdateMissionRequest;
use App\Http\Resources\Admin\AdminMissionResource;
use App\Models\Mission;
use App\Models\Organ;
use App\Services\Assessment\MissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Mission CRUD and publication.
 *
 * The mission editor is the only screen that renders a target sequence.
 * AdminMissionResource emits `configuration` only when
 * MissionPolicy::viewTargetSequence() allows it, so the exposure is decided by
 * the policy rather than by which route reached the Resource
 * (invariant 4, docs/architecture.md §14).
 */
final class MissionAdminController extends Controller
{
    public function __construct(private readonly MissionService $missions) {}

    public function index(): Response
    {
        Gate::authorize('viewAny', Mission::class);

        return Inertia::render('Admin/Missions/Index', [
            'missions' => AdminMissionResource::collection($this->missions->paginateAll()),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Mission::class);

        return Inertia::render('Admin/Missions/Edit', [
            'mission' => null,
            'options' => $this->formOptions(),
        ]);
    }

    public function store(StoreMissionRequest $request): RedirectResponse
    {
        $mission = $this->missions->create($request->validated());

        return redirect()
            ->route('admin.missions.edit', $mission)
            ->with('success', "“{$mission->title}” created as a draft.");
    }

    public function edit(Mission $mission): Response
    {
        Gate::authorize('update', $mission);

        return Inertia::render('Admin/Missions/Edit', [
            'mission' => new AdminMissionResource($mission->loadMissing('organ')),
            'options' => $this->formOptions(),
        ]);
    }

    public function update(UpdateMissionRequest $request, Mission $mission): RedirectResponse
    {
        $this->missions->update($mission, $request->validated());

        return back()->with('success', 'Saved.');
    }

    /**
     * Publish or unpublish.
     *
     * The service refuses to publish a configuration it could not score — a
     * mission naming a structure the organ does not publish would dead-end on
     * its first step. That comes back as a field error rather than a 500,
     * because it is something the admin can fix in the editor they are looking
     * at.
     */
    public function status(Request $request, Mission $mission): RedirectResponse
    {
        Gate::authorize('publish', $mission);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(MissionStatus::class)],
        ]);

        $status = MissionStatus::from($validated['status']);

        try {
            $this->missions->setStatus($mission, $status);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['configuration' => $exception->getMessage()]);
        }

        return back()->with('success', $status === MissionStatus::Published
            ? "“{$mission->title}” is now available to students."
            : "“{$mission->title}” is back to draft.");
    }

    public function destroy(Mission $mission): RedirectResponse
    {
        Gate::authorize('delete', $mission);

        $this->missions->delete($mission);

        return redirect()
            ->route('admin.missions.index')
            ->with('success', "“{$mission->title}” deleted.");
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
            'types' => MissionType::values(),
            'statuses' => MissionStatus::values(),
        ];
    }
}
