<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import AdminPage from '@/Components/Admin/AdminPage.vue'

/**
 * The admin landing page.
 *
 * `can` arrives already decided by the policies (AdminDashboardController).
 * This file never compares a role — invariant 8: templates display, they do
 * not query, and that includes querying who someone is.
 */
interface AdminSection {
  key: string
  label: string
  description: string
  href: string
  can: boolean
  badge: number | null
}

defineProps<{ sections: AdminSection[] }>()
</script>

<template>
  <AdminPage
    title="Content management"
    description="Everything a student sees is published from here."
  >
    <ul class="grid gap-4 sm:grid-cols-2">
      <li v-for="section in sections.filter((s) => s.can)" :key="section.key">
        <Link
          :href="section.href"
          class="block h-full rounded-lg border border-[var(--color-border-subtle)] bg-[var(--color-surface-raised)] p-4 hover:border-[var(--color-accent)]"
        >
          <div class="flex items-start justify-between gap-2">
            <h2 class="font-medium">{{ section.label }}</h2>
            <span
              v-if="section.badge !== null"
              class="rounded-full bg-amber-500/15 px-2 py-0.5 text-xs font-medium text-amber-700 dark:text-amber-300"
            >
              {{ section.badge }} awaiting review
            </span>
          </div>
          <p class="mt-1 text-sm text-[var(--color-ink-muted)]">{{ section.description }}</p>
        </Link>
      </li>
    </ul>
  </AdminPage>
</template>
