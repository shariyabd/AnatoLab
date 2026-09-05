<script setup lang="ts">
import { computed } from 'vue'

/**
 * The end of a round.
 *
 * The score shown here is the count this round's verdicts came back with — it
 * is a tally of server decisions, not a client-side grading (invariant 4).
 * Mastery is not shown: it is recomputed in a queued job after the last
 * attempt, so any number displayed at this moment would be stale
 * (docs/architecture.md §10). Handover 10 puts the real figure on the progress
 * page.
 */
const props = defineProps<{
  correctCount: number
  total: number
}>()

const message = computed(() => {
  if (props.total === 0) return 'There are no questions in this quiz yet.'
  const ratio = props.correctCount / props.total
  if (ratio === 1) return 'Every one. Try a harder organ.'
  if (ratio >= 0.7) return 'Solid. The ones you missed are worth a second pass.'
  if (ratio >= 0.4) return 'Getting there — go back through the explanations.'
  return 'Worth exploring the organ again before the next round.'
})
</script>

<template>
  <section class="space-y-3" aria-labelledby="round-summary">
    <h2 id="round-summary" class="text-base font-semibold">Round complete</h2>

    <p class="text-sm">
      <span class="text-2xl font-semibold">{{ correctCount }}</span>
      <span class="text-[var(--color-ink-muted)]"> of {{ total }} correct</span>
    </p>

    <p class="text-xs text-[var(--color-ink-muted)]">{{ message }}</p>

    <slot />
  </section>
</template>
