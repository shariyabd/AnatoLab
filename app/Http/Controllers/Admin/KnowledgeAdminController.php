<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\KnowledgeSourceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreKnowledgeDocumentRequest;
use App\Http\Resources\Admin\AdminKnowledgeDocumentResource;
use App\Models\KnowledgeDocument;
use App\Models\Organ;
use App\Services\Rag\KnowledgeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * RAG source documents and their ingestion status.
 *
 * The upload itself is entirely F11's: KnowledgeService writes the bytes to a
 * private disk under a generated name and queues ProcessKnowledgeDocument.
 * This controller validates, authorizes, and calls it — the document is never
 * touched here (docs/handovers/11-rag-knowledge-base.md: "Document upload UI is
 * F13's; you provide the service it calls").
 *
 * Acceptance criterion 4 is the status column on the index: pending →
 * processing → indexed | failed, written by the job, read here.
 */
final class KnowledgeAdminController extends Controller
{
    public function __construct(private readonly KnowledgeService $knowledge) {}

    public function index(): Response
    {
        Gate::authorize('viewAny', KnowledgeDocument::class);

        return Inertia::render('Admin/Knowledge/Index', [
            'documents' => AdminKnowledgeDocumentResource::collection(
                $this->knowledge->paginateAll(),
            ),
            'options' => [
                'sourceTypes' => KnowledgeSourceType::values(),
                'organs' => Organ::query()
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(static fn (Organ $organ): array => [
                        'id' => (string) $organ->id,
                        'name' => $organ->name,
                    ])
                    ->all(),
            ],
        ]);
    }

    /**
     * Upload a file, or store pasted text.
     *
     * A rejected file never reaches the disk: StoreKnowledgeDocumentRequest
     * checks MIME, extension and size before this method runs, and the service
     * re-checks for callers that had no FormRequest (docs/engineering.md §10).
     */
    public function store(StoreKnowledgeDocumentRequest $request): RedirectResponse
    {
        $data = $request->documentData();

        $document = $request->hasFile('document')
            ? $this->knowledge->storeUpload($request->file('document'), $data)
            : $this->knowledge->storeText((string) $request->validated('text'), $data);

        return redirect()
            ->route('admin.knowledge.index')
            ->with('success', "“{$document->title}” queued for indexing.");
    }

    /**
     * Re-run the pipeline over a document already on disk.
     */
    public function reingest(KnowledgeDocument $document): RedirectResponse
    {
        Gate::authorize('reingest', $document);

        $this->knowledge->reingest($document);

        return back()->with('success', "“{$document->title}” queued for re-indexing.");
    }

    public function destroy(KnowledgeDocument $document): RedirectResponse
    {
        Gate::authorize('delete', $document);

        // Deletes the row, its chunks, the vectors, and the file — all of it is
        // the service's business, not this controller's.
        $this->knowledge->delete($document);

        return back()->with('success', "“{$document->title}” removed from the knowledge base.");
    }
}
