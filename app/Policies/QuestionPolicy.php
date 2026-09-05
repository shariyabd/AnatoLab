<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Question;
use App\Models\User;

/**
 * Who may administer a question — and who may see its answer key.
 *
 * Invariant 4 says no answer key reaches the client. The admin review queue is
 * the single sanctioned exception: an admin cannot approve a question whose
 * correct option they cannot see. Because it is the only exception, it gets its
 * own ability rather than riding on view(), so the exception is one grep away
 * and a future listing that adds `is_correct` to a payload has to name
 * `viewAnswerKey` to do it (docs/handovers/13-admin-content.md,
 * docs/engineering.md §1 invariant 4).
 *
 * `admin` middleware is not what protects this. Middleware guards an area;
 * this guards the field (docs/architecture.md §14).
 */
final class QuestionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Question $question): bool
    {
        return $user->isAdmin();
    }

    /**
     * See `is_correct` / `correct_option_id` / `correct_structure_id` for this
     * question.
     *
     * Separate from view() so that stripping the key stays the default and
     * exposing it stays a decision. A student is false here even if some future
     * change makes them true for view().
     */
    public function viewAnswerKey(User $user, Question $question): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Question $question): bool
    {
        return $user->isAdmin();
    }

    /**
     * Move a question out of `review` and into `published`.
     *
     * PRD §24 requires AI-generated content to be reviewable before it becomes
     * canonical curriculum; this is that control. Named `review` rather than
     * folded into publish() because approving generated content is the
     * judgement PRD §24 asks a human to make, and it should be visible in an
     * authorization audit as its own line.
     */
    public function review(User $user, Question $question): bool
    {
        return $user->isAdmin();
    }

    public function publish(User $user, Question $question): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Question $question): bool
    {
        return $user->isAdmin();
    }
}
