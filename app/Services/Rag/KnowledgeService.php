<?php

declare(strict_types=1);

namespace App\Services\Rag;

use App\Contracts\VectorStoreInterface;
use App\Enums\KnowledgeDocumentStatus;
use App\Jobs\ProcessKnowledgeDocument;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The front door of the ingest pipeline (docs/architecture.md §8.3).
 *
 * Accepts a document, validates it, puts the bytes somewhere private, records
 * the row, and **returns** — everything after that happens on the `ingest`
 * queue. A student asking a question while an admin uploads a textbook must not
 * wait for the textbook.
 *
 * Reads nothing from the request or the session (invariant 1): the uploaded
 * file and a KnowledgeDocumentData arrive as typed arguments, which is also
 * what lets Handover 13 call this from a controller and a seeder call it from
 * the console. Authorisation is the caller's: uploads are admin-only, enforced
 * by the route's middleware and policy, not here.
 */
final class KnowledgeService
{
    public function __construct(
        private readonly VectorStoreInterface $store,
    ) {}

    /**
     * Accept an uploaded file and queue it for processing.
     *
     * @throws ValidationException when the file is the wrong type or too large
     */
    public function storeUpload(UploadedFile $file, KnowledgeDocumentData $data): KnowledgeDocument
    {
        $extension = $this->assertAcceptable($file);

        // A generated name, never the client's. The uploaded filename is
        // attacker-controlled and is kept only as a label
        // (docs/engineering.md §10).
        $path = $this->disk()->putFileAs(
            (string) config('ai.knowledge.directory', 'knowledge'),
            $file,
            Str::uuid()->toString().'.'.$extension,
        );

        if ($path === false) {
            throw ValidationException::withMessages([
                'document' => 'The document could not be stored. Please try again.',
            ]);
        }

        return $this->queue($this->record($data, $path, [
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize() === false ? null : (int) $file->getSize(),
        ]));
    }

    /**
     * Accept text pasted or generated in-app rather than uploaded.
     *
     * Same pipeline: the text is written to the private disk so that
     * ProcessKnowledgeDocument has exactly one way to read a document, and a
     * re-ingest at version 2 reproduces version 1's input.
     */
    public function storeText(string $text, KnowledgeDocumentData $data): KnowledgeDocument
    {
        if (trim($text) === '') {
            throw ValidationException::withMessages([
                'text' => 'The document is empty.',
            ]);
        }

        $this->assertWithinSizeCap(strlen($text));

        $path = (string) config('ai.knowledge.directory', 'knowledge').'/'.Str::uuid()->toString().'.txt';

        $this->disk()->put($path, $text);

        return $this->queue($this->record($data, $path, [
            'original_filename' => null,
            'mime_type' => 'text/plain',
            'size_bytes' => strlen($text),
        ]));
    }

    /**
     * Re-run the pipeline over a document already on disk.
     *
     * Bumps `version` rather than resuming: a document that failed halfway has
     * chunks from a pass nobody trusts, and ProcessKnowledgeDocument clears
     * them before it starts.
     */
    public function reingest(KnowledgeDocument $document): KnowledgeDocument
    {
        $document->version = $document->version + 1;
        $document->save();

        return $this->queue($document);
    }

    /**
     * The document's text, as the chunker will see it.
     *
     * Plain text and Markdown only. Every binary format needs a parser
     * dependency, and adding one is a human decision with a licence check
     * (docs/engineering.md §5) — so this fails loudly rather than indexing the
     * bytes of a PDF as if they were prose.
     */
    public function extractText(KnowledgeDocument $document): string
    {
        $path = $document->storage_path;

        if ($path === null || ! $this->disk()->exists($path)) {
            throw new RuntimeException("Knowledge document [{$document->getKey()}] has no stored file.");
        }

        $contents = (string) $this->disk()->get($path);

        if (! mb_check_encoding($contents, 'UTF-8')) {
            throw new RuntimeException(
                "Knowledge document [{$document->getKey()}] is not UTF-8 text and cannot be indexed."
            );
        }

        // Strip control characters other than tab and newline: they survive a
        // UTF-8 check, mean nothing to an embedding model, and make a quoted
        // citation render as mojibake.
        return trim((string) preg_replace('/[^\P{C}\n\t]/u', '', $contents));
    }

    /**
     * Remove a document, its chunks, its vectors, and its file.
     *
     * Vectors first: a crash between the two leaves an orphaned vector that
     * still cites a title, and an answer citing a deleted source is worse than
     * a row that has to be cleaned up.
     */
    public function delete(KnowledgeDocument $document): void
    {
        $this->store->delete($this->vectorIdsFor($document));

        $path = $document->storage_path;

        if ($path !== null) {
            $this->disk()->delete($path);
        }

        // Chunks go with it: the FK is cascadeOnDelete.
        $document->delete();
    }

    /**
     * Drop the previous pass's chunks and their vectors.
     *
     * Called by ProcessKnowledgeDocument before it re-chunks, so a shorter
     * second version does not leave version 1's tail in the index.
     */
    public function clearChunks(KnowledgeDocument $document): void
    {
        $ids = $this->vectorIdsFor($document);

        if ($ids !== []) {
            $this->store->delete($ids);
        }

        $document->chunks()->delete();
    }

    public function markProcessing(KnowledgeDocument $document): void
    {
        $this->transition($document, KnowledgeDocumentStatus::Processing);
    }

    public function markIndexed(KnowledgeDocument $document): void
    {
        $this->transition($document, KnowledgeDocumentStatus::Indexed);
    }

    /**
     * Record why ingest stopped, so an admin sees a reason and not a stuck row.
     */
    public function markFailed(KnowledgeDocument $document, string $reason): void
    {
        $this->transition($document, KnowledgeDocumentStatus::Failed, ['failure_reason' => $reason]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function transition(
        KnowledgeDocument $document,
        KnowledgeDocumentStatus $status,
        array $metadata = [],
    ): void {
        $merged = [...($document->metadata ?? []), ...$metadata];

        // A document that has come back from `failed` should not still show
        // last time's reason next to a green status.
        if ($status !== KnowledgeDocumentStatus::Failed) {
            unset($merged['failure_reason']);
        }

        $document->status = $status;
        $document->metadata = $merged;
        $document->save();
    }

    /**
     * @param  array{original_filename: string|null, mime_type: string|null, size_bytes: int|null}  $file
     */
    private function record(KnowledgeDocumentData $data, string $path, array $file): KnowledgeDocument
    {
        $document = new KnowledgeDocument([
            'title' => $data->title,
            'source' => $data->source,
            'source_type' => $data->sourceType,
            'version' => 1,
            'status' => KnowledgeDocumentStatus::Pending,
            'metadata' => $data->retrievalDefaults(),
            ...$file,
        ]);

        // Assigned rather than mass-assigned: the path is generated here and is
        // deliberately absent from $fillable.
        $document->storage_path = $path;
        $document->save();

        return $document;
    }

    /**
     * Hand the document to the `ingest` queue and return.
     *
     * The id rather than the model: the job re-reads the row, so it cannot act
     * on a snapshot taken before an admin corrected the title.
     */
    private function queue(KnowledgeDocument $document): KnowledgeDocument
    {
        $this->transition($document, KnowledgeDocumentStatus::Pending);

        ProcessKnowledgeDocument::dispatch((int) $document->getKey());

        return $document;
    }

    /**
     * MIME **and** extension, both against the allowlist.
     *
     * Extension alone trusts the uploader; MIME alone trusts a header a client
     * sets. Requiring the pair means a `.txt` renamed from a `.exe` and a
     * `.md` announced as `text/plain` are both rejected
     * (docs/engineering.md §10).
     *
     * @return string the validated extension
     *
     * @throws ValidationException
     */
    private function assertAcceptable(UploadedFile $file): string
    {
        /** @var array<string, list<string>> $allowed */
        $allowed = (array) config('ai.knowledge.allowed_types', []);

        $mime = (string) $file->getMimeType();
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if (! array_key_exists($mime, $allowed) || ! in_array($extension, $allowed[$mime], true)) {
            throw ValidationException::withMessages([
                'document' => 'Only plain text (.txt) and Markdown (.md) documents can be indexed.',
            ]);
        }

        $this->assertWithinSizeCap($file->getSize() === false ? 0 : (int) $file->getSize());

        return $extension;
    }

    /**
     * @throws ValidationException
     */
    private function assertWithinSizeCap(int $bytes): void
    {
        $cap = max(1, (int) config('ai.knowledge.max_upload_kilobytes', 8192)) * 1024;

        if ($bytes > $cap) {
            throw ValidationException::withMessages([
                'document' => 'The document is larger than '.($cap / 1024 / 1024).' MB.',
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function vectorIdsFor(KnowledgeDocument $document): array
    {
        return $document->chunks()
            ->get(['id', 'document_id', 'chunk_index'])
            ->map(static fn (KnowledgeChunk $chunk): string => $chunk->vectorId())
            ->values()
            ->all();
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('ai.knowledge.disk', 'local'));
    }

    /*
    |---------------------------------------------------------------------------
    | Administration (Handover 13)
    |---------------------------------------------------------------------------
    */

    /**
     * Every document with its ingestion state, for the admin listing.
     *
     * Newest first: the row an admin wants after an upload is the one they just
     * created, and its status is the thing they came back to check.
     *
     * `withCount('chunks')` rather than loading them — a document indexes into
     * hundreds of chunks and the page shows only how many
     * (docs/engineering.md §10).
     *
     * @return LengthAwarePaginator<int, KnowledgeDocument>
     */
    public function paginateAll(int $perPage = 25): LengthAwarePaginator
    {
        return KnowledgeDocument::query()
            ->withCount('chunks')
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
