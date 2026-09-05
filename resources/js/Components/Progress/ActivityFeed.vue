<script setup lang="ts">
import type { ActivityEntryDto } from '@/types/progress'

/**
 * The last few things the student did (PRD §18).
 *
 * Every label arrives finished from the server. Two reasons, and the second is
 * the one that matters: a template that assembled its own sentence would need
 * the event payload, and the payload records the outcome of graded answers
 * (App\Services\Progress\ProgressService). Templates display; they do not
 * query and they do not derive (invariant 8).
 */
defineProps<{ entries: readonly ActivityEntryDto[] }>()

function when(iso: string): string {
  return new Date(iso).toLocaleString(undefined, {
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  })
}
</script>

<template>
  <ol v-if="entries.length > 0" class="space-y-2">
    <li
      v-for="(entry, index) in entries"
      :key="`${entry.type}-${entry.occurredAt}-${index}`"
      class="flex items-baseline justify-between gap-3 text-sm"
    >
      <span>{{ entry.label }}</span>
      <time :datetime="entry.occurredAt" class="shrink-0 text-xs text-[var(--color-ink-muted)]">
        {{ when(entry.occurredAt) }}
      </time>
    </li>
  </ol>

  <p v-else class="text-sm text-[var(--color-ink-muted)]">Nothing here yet.</p>
</template>
