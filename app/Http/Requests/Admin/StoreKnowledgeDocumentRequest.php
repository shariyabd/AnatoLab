<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\KnowledgeSourceType;
use App\Models\KnowledgeDocument;
use App\Services\Rag\KnowledgeDocumentData;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `POST /admin/knowledge` — upload a source document, or paste one as text.
 *
 * Validated twice on purpose, and that is not redundancy. This class rejects a
 * bad upload before a byte is written, with a field error the admin can act
 * on; KnowledgeService::assertAcceptable() re-checks because it is also called
 * from a seeder and a console command where no FormRequest ran
 * (docs/engineering.md §10, PRD §41).
 *
 * MIME **and** extension, from the same config the service reads, so the two
 * checks cannot disagree — a file passing here and failing there would be a
 * document stored with no row, or a row with no document.
 */
final class StoreKnowledgeDocumentRequest extends AdminRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', KnowledgeDocument::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var array<string, list<string>> $allowed */
        $allowed = (array) config('ai.knowledge.allowed_types', []);

        $extensions = collect($allowed)->flatten()->unique()->values()->all();
        $mimes = array_keys($allowed);
        $maxKilobytes = (int) config('ai.knowledge.max_upload_kilobytes', 8192);

        return [
            'title' => ['required', 'string', 'max:255'],
            'source' => ['required', 'string', 'max:500'],
            'source_type' => ['required', Rule::enum(KnowledgeSourceType::class)],

            // Retrieval defaults inherited by every chunk (PRD §28).
            'organ_id' => ['nullable', 'integer', 'exists:organs,id'],
            'structure_id' => ['nullable', 'integer', 'exists:anatomical_structures,id'],
            'education_level' => ['required', 'string', 'max:32'],
            'content_type' => ['nullable', 'string', 'max:32'],

            'document' => [
                'nullable',
                'file',
                'max:'.$maxKilobytes,
                // Both, never one. `extensions` trusts nothing the uploader
                // named; `mimetypes` is what the bytes actually are.
                Rule::when($extensions !== [], ['extensions:'.implode(',', $extensions)]),
                Rule::when($mimes !== [], ['mimetypes:'.implode(',', $mimes)]),
            ],

            'text' => ['nullable', 'string', 'max:'.($maxKilobytes * 1024)],
        ];
    }

    /**
     * Exactly one of a file or pasted text.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasFile = $this->hasFile('document');
            $hasText = trim((string) $this->input('text', '')) !== '';

            if ($hasFile === $hasText) {
                $validator->errors()->add(
                    'document',
                    'Upload a file or paste the text — one or the other, not both and not neither.',
                );
            }
        });
    }

    /**
     * The validated description, as the typed object KnowledgeService takes.
     *
     * This is the invariant-7 seam: the service never sees the form's field
     * names, so renaming one here cannot reach into the RAG pipeline.
     */
    public function documentData(): KnowledgeDocumentData
    {
        return new KnowledgeDocumentData(
            title: (string) $this->validated('title'),
            source: (string) $this->validated('source'),
            sourceType: KnowledgeSourceType::from((string) $this->validated('source_type')),
            organId: $this->validated('organ_id') === null ? null : (int) $this->validated('organ_id'),
            structureId: $this->validated('structure_id') === null ? null : (int) $this->validated('structure_id'),
            educationLevel: (string) $this->validated('education_level'),
            contentType: $this->validated('content_type') === null ? null : (string) $this->validated('content_type'),
        );
    }
}
