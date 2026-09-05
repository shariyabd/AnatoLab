<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AdminPage from '@/Components/Admin/AdminPage.vue'
import StatusBadge from '@/Components/Admin/StatusBadge.vue'
import type { AdminQuestion, OrganOption, Paginated, QuestionStatus } from '@/types/admin'

/**
 * The question bank, in every state.
 *
 * Filtering is a server round trip with the filter in the URL, for the same
 * reason the lesson library does it: the server decides which rows exist, and a
 * client-side filter would mean shipping the whole bank to hide most of it.
 */
const props = defineProps<{
  questions: Paginated<AdminQuestion>
  filters: { status: QuestionStatus | null }
  options: { organs: OrganOption[]; types: string[]; statuses: QuestionStatus[] }
}>()

function filterByStatus(status: string): void {
  router.visit('/admin/questions', {
    data: status === '' ? {} : { status },
    preserveState: true,
    preserveScroll: true,
  })
}
</script>

<template>
  <AdminPage title="Questions" description="The question bank across every state.">
    <template #actions>
      <Link
        href="/admin/questions/review"
        class="rounded-md border border-[var(--color-border-subtle)] px-3 py-2 text-sm"
      >
        Review queue
      </Link>
      <Link
        href="/admin/questions/create"
        class="rounded-md bg-[var(--color-accent)] px-3 py-2 text-sm font-medium text-white"
      >
        New question
      </Link>
    </template>

    <label class="mb-4 inline-block text-sm">
      <span class="text-[var(--color-ink-muted)]">Status</span>
      <select
        class="ml-2 rounded-md border border-[var(--color-border-strong)] bg-transparent px-2 py-1"
        :value="props.filters.status ?? ''"
        @change="filterByStatus(($event.target as HTMLSelectElement).value)"
      >
        <option value="">All</option>
        <option v-for="status in options.statuses" :key="status" :value="status">
          {{ status }}
        </option>
      </select>
    </label>

    <div class="overflow-x-auto rounded-lg border border-[var(--color-border-subtle)]">
      <table class="w-full text-sm">
        <thead class="bg-[var(--color-surface-raised)] text-left">
          <tr>
            <th class="px-4 py-2 font-medium">Question</th>
            <th class="px-4 py-2 font-medium">Type</th>
            <th class="px-4 py-2 font-medium">Organ</th>
            <th class="px-4 py-2 font-medium">Status</th>
            <th class="px-4 py-2 font-medium"><span class="sr-only">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="question in questions.data"
            :key="question.id"
            class="border-t border-[var(--color-border-subtle)]"
          >
            <td class="max-w-md px-4 py-2">
              <p class="truncate">{{ question.question }}</p>
              <p v-if="question.generatedByAi" class="text-xs text-[var(--color-ink-muted)]">
                AI generated
              </p>
            </td>
            <td class="px-4 py-2 text-[var(--color-ink-muted)]">{{ question.typeLabel }}</td>
            <td class="px-4 py-2 text-[var(--color-ink-muted)]">
              {{ question.organ?.name ?? '—' }}
            </td>
            <td class="px-4 py-2">
              <StatusBadge :status="question.status" :label="question.statusLabel" />
            </td>
            <td class="px-4 py-2 text-right">
              <Link :href="`/admin/questions/${question.id}/edit`" class="text-xs underline">
                Edit
              </Link>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <p v-if="questions.data.length === 0" class="mt-4 text-sm text-[var(--color-ink-muted)]">
      No questions match.
    </p>
  </AdminPage>
</template>
