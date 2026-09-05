<script setup lang="ts">
import type { SimulationLogEntry } from '@/types/simulations'

/**
 * Every action this run has taken, in order.
 *
 * The list a student uses to see how they got here, and — because the log *is*
 * the run rather than a record of it — the list that would have to be identical
 * for a replay to be identical (see the `simulation_sessions` migration).
 *
 * Displays only. `snake_case` keys because this is the stored column verbatim
 * (`@/types/simulations`).
 */
defineProps<{
  events: readonly SimulationLogEntry[]
}>()
</script>

<template>
  <section aria-labelledby="simulation-log">
    <h2 id="simulation-log" class="text-sm font-semibold">What you have done</h2>

    <ol v-if="events.length > 0" class="mt-3 space-y-2">
      <li
        v-for="entry in events"
        :key="entry.sequence"
        class="flex gap-2 text-xs text-[var(--color-ink-muted)]"
      >
        <span class="tabular-nums">{{ entry.sequence }}.</span>
        <span>{{ entry.action_label ?? entry.action_id }}</span>
      </li>
    </ol>

    <p v-else class="mt-3 text-xs text-[var(--color-ink-muted)]">
      Nothing yet. Pick something from the list to change.
    </p>
  </section>
</template>
