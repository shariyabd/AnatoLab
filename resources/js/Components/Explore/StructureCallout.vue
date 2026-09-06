<script setup lang="ts">
import Icon from '@/Components/Atelier/Icon.vue'
import type { StructureDto } from '@/types/explore'

/**
 * The card that hangs off the selected hotspot — restyled by handover 15.
 *
 * Presentational only. Its *position* is written imperatively by ViewerStage on
 * the animation frame — this component never sees a coordinate, so a spinning
 * model re-renders nothing here (docs/project-context.md §5.2: the one thing
 * worth reproducing from the upstream callout).
 *
 * The leader line is drawn here rather than by the viewer for the same reason:
 * it hangs off the bottom of the card, so it travels with the card's existing
 * transform and costs no second thing to position.
 *
 * The card carries a one-line summary, not the structure's full text. The
 * information panel is where the description lives; a callout that grows to
 * paragraph height covers the model it is pointing at.
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
  <div class="flex flex-col items-center">
    <div
      class="w-64 rounded-card bg-[var(--color-surface)] p-4 shadow-card"
      role="dialog"
      aria-labelledby="structure-callout-title"
    >
      <div class="flex items-start gap-2">
        <span
          class="mt-[0.4rem] size-2.5 shrink-0 rounded-full"
          :style="{ backgroundColor: structure.markerColor ?? accentColor }"
          aria-hidden="true"
        />

        <div class="min-w-0 flex-1">
          <h3
            id="structure-callout-title"
            class="font-display text-[1.125rem] leading-tight font-semibold"
          >
            {{ structure.name }}
          </h3>
          <p
            v-if="structure.taTerm"
            class="mt-0.5 font-body text-[0.8125rem] italic text-[var(--color-ink-muted)]"
          >
            {{ structure.taTerm }}
          </p>
        </div>

        <button
          type="button"
          class="-m-1 shrink-0 rounded-full p-1.5 text-[var(--color-ink-muted)] transition-colors hover:bg-[var(--color-surface-sunk)] hover:text-[var(--color-ink)]"
          aria-label="Close structure callout"
          @click="$emit('close')"
        >
          <Icon name="close" class="size-3.5" />
        </button>
      </div>

      <p
        v-if="structure.function"
        class="mt-2.5 font-body text-[0.9375rem] leading-relaxed text-[var(--color-ink-soft)]"
      >
        {{ structure.function }}
      </p>
      <p
        v-else-if="structure.description"
        class="mt-2.5 font-body text-[0.9375rem] leading-relaxed text-[var(--color-ink-soft)]"
      >
        {{ structure.description }}
      </p>

      <button
        type="button"
        class="mt-3 w-full rounded-tile border border-[var(--color-hairline-strong)] px-3 py-2 text-ui font-medium transition-colors hover:bg-[var(--color-surface-sunk)]"
        @click="$emit('isolate')"
      >
        Focus
      </button>
    </div>

    <!-- Thin leader down to the marker the card belongs to. -->
    <span class="h-4 w-px bg-[var(--color-hairline-strong)]" aria-hidden="true" />
  </div>
</template>
