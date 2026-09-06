<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import AdminPage from '@/Components/Admin/AdminPage.vue'
import type { AdminQuestion, Paginated } from '@/types/admin'

/**
 * The AI question review queue — PRD §24's control before generated content
 * becomes canonical curriculum.
 *
 * This is one of exactly two screens in the application that render an answer
 * key, and it does so because approving a question you cannot see the answer to
 * is not review. The key arrives only when
 * `QuestionPolicy::viewAnswerKey` allowed it, so `question.options` and
 * `question.correctStructureName` are **optional** — a page that assumed the
 * admin area implies the field would be asserting authorization it did not
 * check (invariant 4, docs/architecture.md §14).
 *
 * Approving is a POST to `questions.publish`, the only path a question takes to
 * `published`.
 */
defineProps<{ questions: Paginated<AdminQuestion> }>()

function approve(question: AdminQuestion): void {
  router.post(`/admin/questions/${question.id}/publish`, {}, { preserveScroll: true })
}

function sendBackToDraft(question: AdminQuestion): void {
  router.patch(
    `/admin/questions/${question.id}/status`,
    { status: 'draft' },
    { preserveScroll: true },
  )
}
</script>

<template>
  <AdminPage
    title="AI review queue"
    description="Generated questions cannot reach a student until someone here approves them."
  >
    <p v-if="questions.data.length === 0" class="text-sm text-[var(--color-ink-muted)]">
      Nothing is waiting for review.
    </p>

    <ul class="space-y-4">
      <li
        v-for="question in questions.data"
        :key="question.id"
        class="rounded-lg border border-[var(--color-border-subtle)] bg-[var(--color-surface-raised)] p-4"
      >
        <div class="flex flex-wrap items-start justify-between gap-2">
          <div>
            <p class="font-medium">{{ question.question }}</p>
            <p class="mt-1 text-xs text-[var(--color-ink-muted)]">
              {{ question.typeLabel }} · difficulty {{ question.difficulty }}
              <template v-if="question.organ"> · {{ question.organ.name }}</template>
            </p>
          </div>
          <span class="rounded-full bg-amber-500/15 px-2 py-0.5 text-xs font-medium text-amber-700">
            AI generated
          </span>
        </div>

        <!--
          The answer key. Absent — not null — when the policy denied it, so
          `v-if` is checking authorization, not emptiness.
        -->
        <div v-if="question.options !== undefined" class="mt-3">
          <p class="text-xs font-medium text-[var(--color-ink-muted)]">Options</p>
          <ul class="mt-1 space-y-1 text-sm">
            <li v-for="option in question.options" :key="option.id" class="flex gap-2">
              <span :class="option.isCorrect ? 'font-medium text-emerald-600' : ''">
                {{ option.label }}. {{ option.value }}
                <template v-if="option.isCorrect"> ✓ correct</template>
              </span>
            </li>
          </ul>
        </div>

        <p v-if="question.correctStructureName" class="mt-3 text-sm text-emerald-600">
          Answer: {{ question.correctStructureName }}
        </p>

        <p v-if="question.explanation" class="mt-3 text-sm text-[var(--color-ink-muted)]">
          {{ question.explanation }}
        </p>

        <div class="mt-4 flex gap-2">
          <button
            type="button"
            class="rounded-md bg-[var(--color-accent)] px-3 py-1.5 text-sm font-medium text-white"
            @click="approve(question)"
          >
            Approve and publish
          </button>
          <a
            :href="`/admin/questions/${question.id}/edit`"
            class="rounded-md border border-[var(--color-border-subtle)] px-3 py-1.5 text-sm"
          >
            Edit first
          </a>
          <button
            type="button"
            class="rounded-md border border-[var(--color-border-subtle)] px-3 py-1.5 text-sm"
            @click="sendBackToDraft(question)"
          >
            Send back to draft
          </button>
        </div>
      </li>
    </ul>
  </AdminPage>
</template>
