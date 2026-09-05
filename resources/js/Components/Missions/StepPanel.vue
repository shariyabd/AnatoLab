<script setup lang="ts">
import type { MissionStep, StructureDto, StructureId } from '@/types/missions'

/**
 * The current step, and every way of answering it.
 *
 * **It holds no target.** `MissionStep` has no field that could name one — the
 * payload never carries one (invariant 4) — so this component cannot mark
 * anything itself even by accident, and there is nothing here for a
 * well-meaning refactor to start comparing.
 *
 * Clicking a marker is a 3D interaction, and every 3D interaction needs a
 * keyboard and screen-reader equivalent in the same commit that adds it
 * (docs/engineering.md §4). That equivalent is the visually-hidden structure
 * list below: real buttons, in the tab order, carrying the same `structureId`
 * the canvas would emit. It is hidden rather than absent because showing every
 * structure name on screen alongside the prompt would narrow a nine-way choice
 * to a visible list — the same reason `QuestionCard` hides its own.
 */
defineProps<{
  step: MissionStep
  index: number
  total: number
  structures: readonly StructureDto[]
  ordered: boolean
  disabled: boolean
  hintShown: boolean
  canGoBack: boolean
}>()

const emit = defineEmits<{
  (event: 'pick', structureId: StructureId): void
  (event: 'skip'): void
  (event: 'back'): void
  (event: 'show-hint'): void
}>()
</script>

<template>
  <section class="space-y-4" aria-labelledby="mission-step">
    <div>
      <p class="text-[0.6875rem] uppercase tracking-wide text-[var(--color-ink-muted)]">
        Step {{ index + 1 }} of {{ total }}
        <template v-if="ordered"> · order counts</template>
        <template v-else> · any order</template>
      </p>

      <h2 id="mission-step" class="mt-1 text-base font-semibold leading-snug">
        {{ step.prompt }}
      </h2>
    </div>

    <p class="text-xs text-[var(--color-ink-muted)]">
      Click the structure on the model. Nothing is marked right or wrong until the whole run is
      submitted.
    </p>

    <!-- The keyboard and screen-reader path for picking a structure. -->
    <ul class="sr-only">
      <li v-for="structure in structures" :key="structure.id">
        <button type="button" :disabled="disabled" @click="emit('pick', structure.id)">
          {{ structure.name }}
        </button>
      </li>
    </ul>

    <div v-if="step.hint !== null">
      <button
        v-if="!hintShown"
        type="button"
        class="text-xs font-medium text-[var(--color-accent)] underline underline-offset-2"
        :disabled="disabled"
        @click="emit('show-hint')"
      >
        Show a hint
      </button>

      <!--
        Said out loud rather than shown silently: a hinted step is worth fewer
        points, and a student should know that before tapping it, not after.
      -->
      <p v-else class="text-xs text-[var(--color-ink-muted)]">
        {{ step.hint }}
        <span class="block opacity-70">Hinted steps count for a little less.</span>
      </p>
    </div>

    <div class="flex gap-2">
      <button
        v-if="canGoBack"
        type="button"
        class="rounded-md border border-[var(--color-border-subtle)] px-3 py-2 text-sm font-medium disabled:opacity-60"
        :disabled="disabled"
        @click="emit('back')"
      >
        Back a step
      </button>

      <button
        type="button"
        class="rounded-md border border-[var(--color-border-subtle)] px-3 py-2 text-sm font-medium disabled:opacity-60"
        :disabled="disabled"
        @click="emit('skip')"
      >
        Skip this one
      </button>
    </div>
  </section>
</template>
