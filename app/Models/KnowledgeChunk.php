<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\PackedVector;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One retrievable passage of a knowledge document (PRD §22).
 *
 * @property int $id
 * @property int $document_id
 * @property int $chunk_index
 * @property string $content
 * @property list<float>|null $embedding
 * @property string|null $embedding_reference
 * @property int|null $organ_id
 * @property int|null $structure_id
 * @property string|null $education_level
 * @property string|null $content_type
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read KnowledgeDocument $document
 * @property-read Organ|null $organ
 * @property-read AnatomicalStructure|null $structure
 */
class KnowledgeChunk extends Model
{
    /** @use HasFactory<\Database\Factories\KnowledgeChunkFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'document_id',
        'chunk_index',
        'content',
        'embedding',
        'embedding_reference',
        'organ_id',
        'structure_id',
        'education_level',
        'content_type',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'chunk_index' => 'integer',
            'embedding' => PackedVector::class,
            'organ_id' => 'integer',
            'structure_id' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * The id this chunk is known by inside a vector store.
     *
     * `{document}:{index}` rather than the primary key, because it is the same
     * string before and after a re-ingest: `VectorStoreInterface::upsert()` is
     * required to overwrite rather than duplicate, and an autoincrement id
     * would give the rewritten chunk 12 a new name and leave the old vector
     * behind in an external index forever.
     */
    public function vectorId(): string
    {
        return self::vectorIdFor((int) $this->document_id, (int) $this->chunk_index);
    }

    public static function vectorIdFor(int $documentId, int $chunkIndex): string
    {
        return $documentId.':'.$chunkIndex;
    }

    /**
     * Split a vector id back into the pair that addresses a row.
     *
     * Returns null for anything that is not one — an id from another store's
     * namespace, or a stale id from a document that has since been deleted.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function parseVectorId(string $vectorId): ?array
    {
        if (preg_match('/^(\d+):(\d+)$/', $vectorId, $matches) !== 1) {
            return null;
        }

        return [(int) $matches[1], (int) $matches[2]];
    }

    /**
     * @return BelongsTo<KnowledgeDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'document_id');
    }

    /**
     * @return BelongsTo<Organ, $this>
     */
    public function organ(): BelongsTo
    {
        return $this->belongsTo(Organ::class);
    }

    /**
     * @return BelongsTo<AnatomicalStructure, $this>
     */
    public function structure(): BelongsTo
    {
        return $this->belongsTo(AnatomicalStructure::class, 'structure_id');
    }
}
