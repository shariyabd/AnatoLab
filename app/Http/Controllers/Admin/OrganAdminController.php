<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\ModelFormat;
use App\Enums\OrganStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreOrganRequest;
use App\Http\Requests\Admin\UpdateOrganRequest;
use App\Http\Resources\Admin\AdminOrganResource;
use App\Models\BodySystem;
use App\Models\Organ;
use App\Services\Anatomy\AnatomyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Organ CRUD.
 *
 * Thin (invariant 7): validate in a FormRequest, authorize against the policy,
 * delegate to AnatomyService, return a Resource. No Eloquent write happens
 * here — the service owns the anatomy tables, and its observers keep the cache
 * honest on every save (docs/architecture.md §11).
 *
 * `Gate::authorize()` on the reads, and the FormRequest's own `authorize()` on
 * the writes. The `admin` middleware on the route group is a third thing and
 * replaces neither (docs/architecture.md §14).
 */
final class OrganAdminController extends Controller
{
    public function __construct(private readonly AnatomyService $anatomy) {}

    public function index(): Response
    {
        Gate::authorize('viewAny', Organ::class);

        $organs = $this->anatomy->paginateAllOrgans();

        return Inertia::render('Admin/Organs/Index', [
            'organs' => AdminOrganResource::collection($organs),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Organ::class);

        return Inertia::render('Admin/Organs/Edit', [
            'organ' => null,
            'options' => $this->formOptions(),
        ]);
    }

    public function store(StoreOrganRequest $request): RedirectResponse
    {
        $organ = $this->anatomy->createOrgan($request->validated());

        return redirect()
            ->route('admin.organs.edit', $organ)
            ->with('success', "“{$organ->name}” created as a draft.");
    }

    public function edit(Organ $organ): Response
    {
        Gate::authorize('update', $organ);

        $organ->loadMissing('bodySystem')->loadCount('structures');

        return Inertia::render('Admin/Organs/Edit', [
            'organ' => new AdminOrganResource($organ),
            'options' => $this->formOptions(),
        ]);
    }

    public function update(UpdateOrganRequest $request, Organ $organ): RedirectResponse
    {
        $this->anatomy->updateOrgan($organ, $request->validated());

        return back()->with('success', 'Saved.');
    }

    /**
     * Publish or unpublish.
     *
     * Its own route and its own ability, so saving an edit cannot publish
     * content as a side effect.
     */
    public function status(Request $request, Organ $organ): RedirectResponse
    {
        Gate::authorize('publish', $organ);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(OrganStatus::class)],
        ]);

        $status = OrganStatus::from($validated['status']);

        $this->anatomy->setOrganStatus($organ, $status);

        return back()->with('success', $status === OrganStatus::Published
            ? "“{$organ->name}” is now visible to students."
            : "“{$organ->name}” is back to draft.");
    }

    public function destroy(Organ $organ): RedirectResponse
    {
        Gate::authorize('delete', $organ);

        $this->anatomy->deleteOrgan($organ);

        return redirect()
            ->route('admin.organs.index')
            ->with('success', "“{$organ->name}” deleted.");
    }

    /**
     * The picker values the edit form needs.
     *
     * Body systems are read here rather than through AnatomyService: they are
     * a form option list, not anatomy content, and adding a service method for
     * a five-row lookup would be the abstraction docs/engineering.md §12 warns
     * against. It is a read, and it writes nothing another lane owns.
     *
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'bodySystems' => BodySystem::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(static fn (BodySystem $system): array => [
                    'id' => (string) $system->id,
                    'name' => $system->name,
                ])
                ->all(),
            'modelFormats' => ModelFormat::values(),
            'statuses' => OrganStatus::values(),
        ];
    }
}
