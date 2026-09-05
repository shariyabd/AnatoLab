<script setup lang="ts">
import type { SimulationStep } from '@/types/simulations'

/**
 * What the model says is now true, why it is saying it, and who wrote the words.
 *
 * Three things here are required rather than decorative:
 *
 * - **The notice.** PRD §14 and §24: an educational model, never a diagnosis.
 *   It ships with every step and is rendered with every step, because a
 *   disclaimer that appears once at the top of a page is a disclaimer for the
 *   first screenful only.
 * - **The source.** A student is told whether an author or a model wrote the
 *   explanation, because those are different claims.
 * - **The condition.** Each outcome shows the rule that fired it — `output <
 *   0.8` — so the simulation is legible rather than oracular. It is authored
 *   content, not a mechanism the client could run.
 */
defineProps<{
  step: SimulationStep
}>()

const SOURCE_LABEL: Record<string, string> = {
  curated: 'Written for this simulation',
  tutor: 'Written by the AI tutor',
  fallback: 'Generated from the simulation’s own readings',
}
</script>

<template>
  <section
    aria-labelledby="simulation-outcome"
    aria-live="polite"
    class="rounded-lg border border-[var(--color-border-subtle)] bg-[var(--color-surface-raised)] p-4"
  >
    <h2 id="simulation-outcome" class="text-sm font-semibold">{{ step.label }}</h2>

    <ul v-if="step.outcomes.length > 0" class="mt-3 space-y-1.5">
      <li
        v-for="outcome in step.outcomes"
        :key="outcome.key"
        class="flex flex-wrap items-baseline gap-x-2 text-xs"
      >
        <span>{{ outcome.label }}</span>
        <code class="rounded bg-[var(--color-surface)] px-1 py-0.5 text-[0.65rem]">
          {{ outcome.condition }}
        </code>
      </li>
    </ul>

    <p v-if="step.explanation" class="mt-3 text-sm leading-relaxed">
      {{ step.explanation }}
    </p>

    <p v-if="step.explanationSource" class="mt-2 text-xs text-[var(--color-ink-muted)]">
      {{ SOURCE_LABEL[step.explanationSource] ?? step.explanationSource }}
    </p>

    <p
      class="mt-3 border-t border-[var(--color-border-subtle)] pt-3 text-xs text-[var(--color-ink-muted)]"
    >
      {{ step.notice }}
    </p>
  </section>
</template>
