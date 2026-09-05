<script setup lang="ts">
import { computed } from 'vue'
import type { MissionOutcome } from '@/types/missions'

/**
 * The score, and one sentence about the run.
 *
 * `feedback` is templated server-side from the outcomes — never generated —
 * for the reason docs/architecture.md §10 gives about
 * `RecommendationService`'s reason line: a sentence that summarises a score has
 * to be right every time, and it is cheaper to write it than to validate it.
 *
 * It carries correctness on purpose, and this is one of the two places allowed
 * to: the run has already been scored and written server-side, and the reveal
 * is docs/architecture.md §9's specified response.
 */
const props = defineProps<{
  outcome: MissionOutcome
  maxScore: number
}>()

const percent = computed(() =>
  props.maxScore === 0 ? 0 : Math.round((props.outcome.score / props.maxScore) * 100),
)
</script>

<template>
  <!--
    role="status": an outcome confirmation, announced when the reader reaches a
    natural break. "alert" would interrupt mid-sentence.
  -->
  <section
    role="status"
    class="rounded-lg border-2 bg-[var(--color-surface-raised)] px-4 py-3"
    :class="
      outcome.completed ? 'border-[var(--color-success)]' : 'border-[var(--color-border-subtle)]'
    "
  >
    <p
      class="text-sm font-semibold"
      :class="outcome.completed ? 'text-[var(--color-success)]' : 'text-[var(--color-ink)]'"
    >
      <template v-if="outcome.completed">Mission complete</template>
      <template v-else>Run recorded</template>
    </p>

    <p class="mt-1 text-2xl font-semibold tabular-nums">
      {{ outcome.score
      }}<span class="text-base text-[var(--color-ink-muted)]">/{{ maxScore }}</span>
    </p>

    <div
      class="mt-2 h-1.5 overflow-hidden rounded-full bg-[var(--color-border-subtle)]"
      role="progressbar"
      :aria-valuenow="percent"
      aria-valuemin="0"
      aria-valuemax="100"
      aria-label="Score"
    >
      <div
        class="h-full rounded-full bg-[var(--color-accent)]"
        :style="{ width: `${String(percent)}%` }"
      />
    </div>

    <p class="mt-2 text-sm leading-relaxed">{{ outcome.feedback }}</p>

    <p class="mt-1 text-xs text-[var(--color-ink-muted)]">
      {{ outcome.correctCount }} of {{ outcome.perStep.length }} steps correct. Pick a step below to
      walk back through it.
    </p>
  </section>
</template>
