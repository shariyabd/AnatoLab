<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AdminPage from '@/Components/Admin/AdminPage.vue'
import StatusBadge from '@/Components/Admin/StatusBadge.vue'
import type { AdminOrgan, Paginated } from '@/types/admin'

/**
 * The organ list, drafts included.
 *
 * Publishing is a PATCH to its own route rather than part of the edit form, so
 * saving a typo fix can never publish content as a side effect.
 */
defineProps<{ organs: Paginated<AdminOrgan> }>()

function toggleStatus(organ: AdminOrgan): void {
  router.patch(
    `/admin/organs/${organ.slug}/status`,
    { status: organ.status === 'published' ? 'draft' : 'published' },
    { preserveScroll: true },
  )
}
</script>

<template>
  <AdminPage title="Organs" description="Models, metadata and publication state.">
    <template #actions>
      <Link
        href="/admin/organs/create"
        class="rounded-md bg-[var(--color-accent)] px-3 py-2 text-sm font-medium text-white"
      >
        New organ
      </Link>
    </template>

    <div class="overflow-x-auto rounded-lg border border-[var(--color-border-subtle)]">
      <table class="w-full text-sm">
        <thead class="bg-[var(--color-surface-raised)] text-left">
          <tr>
            <th class="px-4 py-2 font-medium">Name</th>
            <th class="px-4 py-2 font-medium">System</th>
            <th class="px-4 py-2 font-medium">Structures</th>
            <th class="px-4 py-2 font-medium">Status</th>
            <th class="px-4 py-2 font-medium"><span class="sr-only">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="organ in organs.data"
            :key="organ.id"
            class="border-t border-[var(--color-border-subtle)]"
          >
            <td class="px-4 py-2">
              <p class="font-medium">{{ organ.name }}</p>
              <p class="font-mono text-xs text-[var(--color-ink-muted)]">{{ organ.slug }}</p>
            </td>
            <td class="px-4 py-2 text-[var(--color-ink-muted)]">
              {{ organ.bodySystem?.name ?? '—' }}
            </td>
            <td class="px-4 py-2 text-[var(--color-ink-muted)]">{{ organ.structureCount ?? 0 }}</td>
            <td class="px-4 py-2">
              <StatusBadge :status="organ.status" :label="organ.statusLabel" />
            </td>
            <td class="px-4 py-2">
              <div class="flex justify-end gap-3 text-xs">
                <Link
                  :href="`/admin/organs/${organ.slug}/author`"
                  class="underline hover:text-[var(--color-accent)]"
                >
                  Author hotspots
                </Link>
                <Link :href="`/admin/organs/${organ.slug}/edit`" class="underline">Edit</Link>
                <button type="button" class="underline" @click="toggleStatus(organ)">
                  {{ organ.status === 'published' ? 'Unpublish' : 'Publish' }}
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <p v-if="organs.data.length === 0" class="mt-4 text-sm text-[var(--color-ink-muted)]">
      No organs yet.
    </p>
  </AdminPage>
</template>
