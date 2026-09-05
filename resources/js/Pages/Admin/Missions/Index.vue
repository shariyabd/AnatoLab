<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AdminPage from '@/Components/Admin/AdminPage.vue'
import StatusBadge from '@/Components/Admin/StatusBadge.vue'
import type { AdminMission, Paginated } from '@/types/admin'

/**
 * The mission list, drafts included.
 *
 * Publishing can fail: the server refuses a configuration whose steps do not
 * resolve against the organ's published structures, because such a mission
 * dead-ends on its first step. The error surfaces as a flash message.
 */
defineProps<{ missions: Paginated<AdminMission> }>()

function toggleStatus(mission: AdminMission): void {
  router.patch(
    `/admin/missions/${mission.slug}/status`,
    { status: mission.status === 'published' ? 'draft' : 'published' },
    { preserveScroll: true },
  )
}
</script>

<template>
  <AdminPage title="Missions" description="Mission definitions and their target sequences.">
    <template #actions>
      <Link
        href="/admin/missions/create"
        class="rounded-md bg-[var(--color-accent)] px-3 py-2 text-sm font-medium text-white"
      >
        New mission
      </Link>
    </template>

    <div class="overflow-x-auto rounded-lg border border-[var(--color-border-subtle)]">
      <table class="w-full text-sm">
        <thead class="bg-[var(--color-surface-raised)] text-left">
          <tr>
            <th class="px-4 py-2 font-medium">Title</th>
            <th class="px-4 py-2 font-medium">Organ</th>
            <th class="px-4 py-2 font-medium">Type</th>
            <th class="px-4 py-2 font-medium">Steps</th>
            <th class="px-4 py-2 font-medium">Status</th>
            <th class="px-4 py-2 font-medium"><span class="sr-only">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="mission in missions.data"
            :key="mission.id"
            class="border-t border-[var(--color-border-subtle)]"
          >
            <td class="px-4 py-2">
              <p class="font-medium">{{ mission.title }}</p>
              <p class="font-mono text-xs text-[var(--color-ink-muted)]">{{ mission.slug }}</p>
            </td>
            <td class="px-4 py-2 text-[var(--color-ink-muted)]">
              {{ mission.organ?.name ?? '—' }}
            </td>
            <td class="px-4 py-2 text-[var(--color-ink-muted)]">{{ mission.type }}</td>
            <td class="px-4 py-2 text-[var(--color-ink-muted)]">{{ mission.stepCount }}</td>
            <td class="px-4 py-2">
              <StatusBadge :status="mission.status" :label="mission.statusLabel" />
            </td>
            <td class="px-4 py-2">
              <div class="flex justify-end gap-3 text-xs">
                <Link :href="`/admin/missions/${mission.slug}/edit`" class="underline">Edit</Link>
                <button type="button" class="underline" @click="toggleStatus(mission)">
                  {{ mission.status === 'published' ? 'Unpublish' : 'Publish' }}
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <p v-if="missions.data.length === 0" class="mt-4 text-sm text-[var(--color-ink-muted)]">
      No missions yet.
    </p>
  </AdminPage>
</template>
