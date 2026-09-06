<script setup lang="ts">
import { computed } from 'vue'

/**
 * A publication-state pill.
 *
 * `tone` is derived from the status value rather than passed in, so every
 * listing colours "draft" the same way without each page deciding.
 */
const props = defineProps<{ status: string; label?: string }>()

const tone = computed(() => {
  switch (props.status) {
    case 'published':
    case 'indexed':
      return 'bg-emerald-500/15 text-emerald-700'
    case 'review':
    case 'processing':
    case 'pending':
      return 'bg-amber-500/15 text-amber-700'
    case 'failed':
      return 'bg-rose-500/15 text-rose-700'
    default:
      return 'bg-[var(--color-surface-sunken)] text-[var(--color-ink-muted)]'
  }
})
</script>

<template>
  <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" :class="tone">
    {{ label ?? status }}
  </span>
</template>
