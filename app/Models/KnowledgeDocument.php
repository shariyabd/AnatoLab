<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\KnowledgeDocumentStatus;
use App\Enums\KnowledgeSourceType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One curated educational source (PRD §10, §22).
 *
 * State only (docs/engineering.md §3). Advancing the status is the ingest
 * jobs' business and validating an upload is KnowledgeService's; a model that
 * moved its own state machine could not be driven from a queue in the order
 * docs/architecture.md §8.3 requires.
 *
 * @property int $id
 * @property string $title
 * @property string $source
 * @property KnowledgeSourceType $source_type
 * @property int $version
 * @property KnowledgeDocumentStatus $status
 * @property string|null $storage_path
 * @property string|null $original_filename
 * @property string|null $mime_type
 * @property int|null $size_bytes
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, KnowledgeChunk> $chunks
 */
class KnowledgeDocument extends Model
{
    /** @use HasFactory<\Database\Factories\KnowledgeDocumentFactory> */
    use HasFactory;

    /**
     * `storage_path` is absent on purpose. It is a generated name derived from
     * the upload by KnowledgeService, and mass-assigning it would let a crafted
     * payload point a document at a file elsewhere on the disk.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'source',
        'source_type',
        'version',
        'status',
        'original_filename',
        'mime_type',
        'size_bytes',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_type' => KnowledgeSourceType::class,
            'status' => KnowledgeDocumentStatus::class,
            'version' => 'integer',
            'size_bytes' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * The passages this document was split into, in reading order.
     *
     * @return HasMany<KnowledgeChunk, $this>
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class, 'document_id')->orderBy('chunk_index');
    }

    /**
     * The retrieval defaults every chunk of this document inherits (PRD §28).
     *
     * Kept in `metadata` rather than in columns because nothing ever filters
     * *documents* by them — `knowledge_chunks` carries the indexed copies that
     * retrieval actually uses.
     *
     * @return array{
     *     organ_id: int|null,
     *     structure_id: int|null,
     *     education_level: string|null,
     *     content_type: string|null
     * }
     */
    public function retrievalDefaults(): array
    {
        $metadata = $this->metadata ?? [];

        return [
            'organ_id' => isset($metadata['organ_id']) ? (int) $metadata['organ_id'] : null,
            'structure_id' => isset($metadata['structure_id']) ? (int) $metadata['structure_id'] : null,
            'education_level' => isset($metadata['education_level']) ? (string) $metadata['education_level'] : null,
            'content_type' => isset($metadata['content_type']) ? (string) $metadata['content_type'] : null,
        ];
    }
}
