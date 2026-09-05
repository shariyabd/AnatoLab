<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\KnowledgeDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One knowledge document and where it is in the ingest pipeline.
 *
 * `storage_path` is deliberately absent. It is a generated path on a private
 * disk, and putting it in a page prop would publish the layout of a directory
 * that is deliberately outside the web root (docs/engineering.md §10).
 * `original_filename` is what a human needs, and it is only a label.
 *
 * @mixin KnowledgeDocument
 */
final class AdminKnowledgeDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'title' => $this->title,
            'source' => $this->source,
            'sourceType' => $this->source_type->value,
            'version' => $this->version,
            'status' => $this->status->value,
            'statusLabel' => $this->status->label(),
            'originalFilename' => $this->original_filename,
            'mimeType' => $this->mime_type,
            'sizeBytes' => $this->size_bytes,
            'chunkCount' => $this->whenCounted('chunks'),
            // Set by KnowledgeService::markFailed(). The reason an ingest
            // failed is the whole value of the admin listing when one does.
            'failureReason' => $this->metadata['failure_reason'] ?? null,
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
