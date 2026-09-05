<script setup lang="ts">
import type { StructureDto } from '@/types/explore'

/**
 * The card that hangs off the selected hotspot.
 *
 * Presentational only. Its *position* is written imperatively by ViewerStage
 * on the animation frame — this component never sees a coordinate, so a
 * spinning model re-renders nothing here (docs/project-context.md §5.2: the
 * one thing worth reproducing from the upstream callout).
 */
defineProps<{
  structure: StructureDto
  accentColor: string
}>()

defineEmits<{
  (event: 'close'): void
  (event: 'isolate'): void
}>()
</script>

<template>
  <div
    class="w-72 rounded-lg border border-[var(--color-border-subtle)] bg-[var(--color-surface-raised)] p-4 shadow-lg"
    role="dialog"
    aria-labelledby="structure-callout-title"
  >
    <div class="flex items-start gap-2">
      <span
        class="mt-1.5 size-2.5 shrink-0 rounded-full"
        :style="{ backgroundColor: structure.markerColor ?? accentColor }"
        aria-hidden="true"
      />

      <div class="min-w-0 flex-1">
        <h3 id="structure-callout-title" class="text-sm font-semibold tracking-tight">
          {{ structure.name }}
        </h3>
        <p v-if="structure.taTerm" class="mt-0.5 text-xs italic text-[var(--color-ink-muted)]">
          {{ structure.taTerm }}
        </p>
      </div>

      <button
        type="button"
        class="-m-1 rounded-md p-1 text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]"
        aria-label="Close structure callout"
        @click="$emit('close')"
      >
        &times;
      </button>
    </div>

    <p v-if="structure.description" class="mt-3 text-xs leading-relaxed text-[var(--color-ink)]">
      {{ structure.description }}
    </p>

    <template v-if="structure.function">
      <h4
        class="mt-3 text-[0.6875rem] font-semibold uppercase tracking-wide text-[var(--color-ink-muted)]"
      >
        What it does
      </h4>
      <p class="mt-1 text-xs leading-relaxed text-[var(--color-ink)]">
        {{ structure.function }}
      </p>
    </template>

    <button
      type="button"
      class="mt-3 w-full rounded-md border border-[var(--color-border-subtle)] px-3 py-1.5 text-xs font-medium hover:bg-[var(--color-surface)]"
      @click="$emit('isolate')"
    >
      Dim everything else
    </button>
  </div>
</template>
