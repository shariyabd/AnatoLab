<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\KnowledgeDocument;
use App\Models\User;

/**
 * Who may administer a RAG source document.
 *
 * Uploads are admin-only (PRD §41, docs/architecture.md §14). KnowledgeService
 * deliberately does not check this itself — it says so in its own docblock —
 * because it is also called from a seeder and a console command where there is
 * no user. That makes the check the caller's, and this is the caller's check.
 */
final class KnowledgeDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, KnowledgeDocument $document): bool
    {
        return $user->isAdmin();
    }

    /**
     * Upload a new source document, or paste one as text.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, KnowledgeDocument $document): bool
    {
        return $user->isAdmin();
    }

    /**
     * Re-run ingestion for a document that failed or whose chunking changed.
     *
     * Its own ability because it queues embedding work that costs money at a
     * real provider, which is a different decision from editing a title.
     */
    public function reingest(User $user, KnowledgeDocument $document): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, KnowledgeDocument $document): bool
    {
        return $user->isAdmin();
    }
}
