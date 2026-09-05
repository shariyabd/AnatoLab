<script setup lang="ts">
import { computed } from 'vue'
import type { SystemMasteryDto } from '@/types/progress'

/**
 * One row of PRD §15's per-system table.
 *
 * The bar is labelled with the number as well as drawn, and carries a
 * `progressbar` role: a percentage conveyed only by bar width is unreadable to
 * a screen reader and unreliable for anyone with low colour contrast
 * (docs/engineering.md §11 item 8).
 *
 * The coverage line is the honest half of the score. "51%" on its own reads as
 * a grade; "51% · 3 of 11 structures seen" reads as a map of what is left.
 */
const props = defineProps<{ system: SystemMasteryDto }>()

const percent = computed(() => Math.min(Math.max(Math.round(props.system.score), 0), 100))
</script>

<template>
  <div class="space-y-1.5">
    <div class="flex items-baseline justify-between gap-3">
      <span class="text-sm font-medium">{{ system.name }}</span>
      <span class="text-sm tabular-nums text-[var(--color-ink-muted)]">{{ percent }}%</span>
    </div>

    <div
      class="h-2 w-full overflow-hidden rounded-full bg-[var(--color-border-subtle)]"
      role="progressbar"
      :aria-label="`${system.name} mastery`"
      :aria-valuenow="percent"
      aria-valuemin="0"
      aria-valuemax="100"
    >
      <div
        class="h-full rounded-full bg-[var(--color-accent)] transition-[width]"
        :style="{ width: `${String(percent)}%` }"
      />
    </div>

    <p class="text-xs text-[var(--color-ink-muted)]">
      <template v-if="system.attempts === 0">Not started yet</template>
      <template v-else>
        {{ system.correctAttempts }} of {{ system.attempts }} answers correct ·
        {{ system.coveredStructures }} of {{ system.totalStructures }} structures seen
      </template>
    </p>
  </div>
</template>
