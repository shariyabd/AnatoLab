<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

/**
 * The chrome every admin screen shares: title, breadcrumb back to /admin, and
 * a slot for the actions that belong to this screen.
 *
 * Exists so the listings stay about their own content. It is layout, not
 * behaviour — no page logic lives here.
 */
defineProps<{ title: string; description?: string }>()
</script>

<template>
  <Head :title="`${title} · Admin`" />

  <AppLayout>
    <header class="mb-6 flex flex-wrap items-end justify-between gap-4">
      <div>
        <Link
          href="/admin"
          class="text-xs font-medium text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]"
        >
          ← Admin
        </Link>
        <h1 class="mt-1 text-2xl font-semibold tracking-tight">{{ title }}</h1>
        <p v-if="description" class="mt-1 text-sm text-[var(--color-ink-muted)]">
          {{ description }}
        </p>
      </div>

      <div class="flex items-center gap-2">
        <slot name="actions" />
      </div>
    </header>

    <slot />
  </AppLayout>
</template>
