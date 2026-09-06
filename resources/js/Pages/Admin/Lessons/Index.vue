<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AdminPage from '@/Components/Admin/AdminPage.vue'
import StatusBadge from '@/Components/Admin/StatusBadge.vue'
import type { AdminLesson, Paginated } from '@/types/admin'

/**
 * The lesson list, drafts included.
 *
 * A lesson on a draft organ is flagged: it can be published and still be
 * invisible, because the library requires both to be published. Saying so here
 * saves an admin wondering why the lesson they just published is missing.
 */
defineProps<{ lessons: Paginated<AdminLesson> }>()

function toggleStatus(lesson: AdminLesson): void {
  router.patch(
    `/admin/lessons/${lesson.slug}/status`,
    { status: lesson.status === 'published' ? 'draft' : 'published' },
    { preserveScroll: true },
  )
}
</script>

<template>
  <AdminPage title="Lessons" description="Lesson content and publication state.">
    <template #actions>
      <Link
        href="/admin/lessons/create"
        class="rounded-md bg-[var(--color-accent)] px-3 py-2 text-sm font-medium text-white"
      >
        New lesson
      </Link>
    </template>

    <div class="overflow-x-auto rounded-lg border border-[var(--color-border-subtle)]">
      <table class="w-full text-sm">
        <thead class="bg-[var(--color-surface-raised)] text-left">
          <tr>
            <th class="px-4 py-2 font-medium">Title</th>
            <th class="px-4 py-2 font-medium">Organ</th>
            <th class="px-4 py-2 font-medium">Steps</th>
            <th class="px-4 py-2 font-medium">Status</th>
            <th class="px-4 py-2 font-medium"><span class="sr-only">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="lesson in lessons.data"
            :key="lesson.id"
            class="border-t border-[var(--color-border-subtle)]"
          >
            <td class="px-4 py-2">
              <p class="font-medium">{{ lesson.title }}</p>
              <p class="font-mono text-xs text-[var(--color-ink-muted)]">{{ lesson.slug }}</p>
            </td>
            <td class="px-4 py-2 text-[var(--color-ink-muted)]">
              {{ lesson.organ?.name ?? '—' }}
              <span v-if="lesson.organ?.status === 'draft'" class="ml-1 text-xs text-amber-600">
                (organ is draft)
              </span>
            </td>
            <td class="px-4 py-2 text-[var(--color-ink-muted)]">{{ lesson.stepCount }}</td>
            <td class="px-4 py-2">
              <StatusBadge :status="lesson.status" :label="lesson.statusLabel" />
            </td>
            <td class="px-4 py-2">
              <div class="flex justify-end gap-3 text-xs">
                <Link :href="`/admin/lessons/${lesson.slug}/edit`" class="underline">Edit</Link>
                <button type="button" class="underline" @click="toggleStatus(lesson)">
                  {{ lesson.status === 'published' ? 'Unpublish' : 'Publish' }}
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <p v-if="lessons.data.length === 0" class="mt-4 text-sm text-[var(--color-ink-muted)]">
      No lessons yet.
    </p>
  </AdminPage>
</template>
