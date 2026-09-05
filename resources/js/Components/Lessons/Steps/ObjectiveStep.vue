<script setup lang="ts">
import type { LessonStep, ObjectivePayload, StructureDto } from '@/types/lessons'

/**
 * "What you will learn" — PRD §8 step 1.
 *
 * Takes `structures` it does not use, because every step component takes the
 * same two props. That uniformity is what lets StepRenderer dispatch through a
 * lookup table instead of a chain of `v-if`s in the template (invariant 8).
 */
const props = defineProps<{ step: LessonStep; structures: readonly StructureDto[] }>()

const payload = props.step.payload as ObjectivePayload
</script>

<template>
  <div class="space-y-4">
    <p class="text-sm leading-relaxed">{{ payload.body }}</p>

    <div v-if="payload.outcomes.length > 0">
      <h3
        class="mb-2 text-[0.6875rem] font-semibold uppercase tracking-wide text-[var(--color-ink-muted)]"
      >
        By the end you will be able to
      </h3>

      <ul class="space-y-1.5">
        <li
          v-for="(outcome, index) in payload.outcomes"
          :key="index"
          class="flex gap-2 text-sm leading-relaxed"
        >
          <span aria-hidden="true" class="select-none text-[var(--color-ink-muted)]">—</span>
          <span>{{ outcome }}</span>
        </li>
      </ul>
    </div>
  </div>
</template>
