<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\LearningContext;
use App\Models\AnatomicalStructure;
use App\Models\Organ;
use App\Models\User;

/**
 * Assembles everything the tutor is allowed to know about who is asking and
 * where they are (docs/architecture.md §8.2, PRD §39).
 *
 * Takes the User as an argument rather than reading it from ambient state
 * (invariant 1), and returns scalars rather than models, because a
 * LearningContext is serialised
 * into a prompt and into a queued job — passing an Eloquent model would drag
 * relations, timestamps, and a password hash along with it.
 *
 * It also never carries an answer key: recent mistakes are recorded as the
 * structure that was *confused*, never as the correct one (invariant 4).
 */
final class AIContextBuilder
{
    public function build(
        User $user,
        ?Organ $organ = null,
        ?AnatomicalStructure $structure = null,
        ?string $lessonTitle = null,
    ): LearningContext {
        return new LearningContext(
            userId: (int) $user->getKey(),
            educationLevel: $user->education_level->value,
            difficultyPreference: $user->difficulty_preference->value,
            organName: $organ?->name,
            structureName: $structure?->name,
            lessonTitle: $lessonTitle,
            recentMistakes: $this->recentMistakesFor($user, $organ),
            masteryGaps: $this->masteryGapsFor($user),
        );
    }

    /**
     * The curated notes a structure carries, used to ground an answer while the
     * knowledge base does not exist yet.
     *
     * This is what "grounded in the structure's own curated metadata" means in
     * docs/architecture.md §8.2 — content an editor wrote and published, not
     * whatever the model happens to recall. It stays useful after Handover 11:
     * retrieved passages are added alongside it, not instead of it.
     *
     * @return list<string>
     */
    public function curatedNotesFor(?Organ $organ, ?AnatomicalStructure $structure): array
    {
        $notes = [];

        if ($organ instanceof Organ && is_string($organ->description) && trim($organ->description) !== '') {
            $notes[] = "{$organ->name}: {$organ->description}";
        }

        if (! $structure instanceof AnatomicalStructure) {
            return $notes;
        }

        if (is_string($structure->ta_term) && trim($structure->ta_term) !== '') {
            // The Terminologia Anatomica term is the canonical, language
            // independent identity of a structure (docs/project-context.md
            // §2.3). Giving it to the model anchors the answer on the right
            // structure even when the English name is ambiguous.
            $notes[] = "{$structure->name} (Terminologia Anatomica: {$structure->ta_term})";
        }

        foreach (['description' => 'Description', 'function' => 'Function', 'location' => 'Location'] as $field => $label) {
            $value = $structure->{$field};

            if (is_string($value) && trim($value) !== '') {
                $notes[] = "{$label} of {$structure->name}: {$value}";
            }
        }

        return $notes;
    }

    /**
     * SEAM — Handover 07 (attempts) and Handover 10 (mastery).
     *
     * `recentMistakes` is named in the frozen LearningContext contract and in
     * docs/architecture.md §8.2, but the `attempts` table it reads is owned by
     * Handover 07 and does not exist yet. Returning an empty list is the honest
     * answer: a student with no recorded attempts genuinely has no recent
     * mistakes, so the prompt degrades to "no known misconceptions" rather than
     * to something wrong.
     *
     * When `attempts` lands, this becomes:
     *
     *   Attempt::query()
     *       ->where('user_id', $user->getKey())
     *       ->where('is_correct', false)
     *       ->when($organ, fn ($q) => $q->whereRelation('structure', 'organ_id', $organ->getKey()))
     *       ->latest()->limit(5)
     *       ->with('structure:id,name')
     *       ->pluck('structure.name')->all();
     *
     * Structure NAMES only. The correct answer never enters the prompt
     * (invariant 4) — a tutor that knows the right answer is one paraphrase
     * away from giving it away.
     *
     * @return list<string>
     */
    private function recentMistakesFor(User $user, ?Organ $organ): array
    {
        unset($user, $organ);

        return [];
    }

    /**
     * SEAM — Handover 10 (`learning_mastery`).
     *
     * Body-system slugs the student scores below target on, so the tutor can
     * connect an answer back to something they are weak on. Empty until the
     * mastery table exists.
     *
     * @return list<string>
     */
    private function masteryGapsFor(User $user): array
    {
        unset($user);

        return [];
    }
}
