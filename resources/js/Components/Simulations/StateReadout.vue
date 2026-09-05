<script setup lang="ts">
import { computed } from 'vue'
import type { SimulationReadout, SimulationStep } from '@/types/simulations'

/**
 * The dials: what each variable is now, and how far that is from its range.
 *
 * Displays only. Every value was computed in PHP and clamped to the bounds
 * shown here (docs/architecture.md §12); the bar's width is the one number
 * derived locally, and it is a percentage of a range the server declared.
 *
 * The numeric value is the reading a screen reader gets. The bar is
 * `aria-hidden` for the reason `QuizProgress` gives — it restates the number
 * beside it, and announcing both reads the same fact twice.
 */
const props = defineProps<{
  readouts: readonly SimulationReadout[]
  step: SimulationStep
  accentColor: string
}>()

interface Dial {
  readonly key: string
  readonly label: string
  readonly text: string
  readonly percent: number
  readonly unit: string | null
}

const dials = computed<readonly Dial[]>(() =>
  props.readouts.map((readout) => {
    const value = props.step.variables[readout.key] ?? readout.min
    const span = readout.max - readout.min

    return {
      key: readout.key,
      label: readout.label,
      text: value.toFixed(readout.precision),
      percent: span <= 0 ? 0 : Math.round(((value - readout.min) / span) * 100),
      unit: readout.unit,
    }
  }),
)
</script>

<template>
  <section aria-labelledby="simulation-readouts">
    <h2 id="simulation-readouts" class="text-sm font-semibold">Readings</h2>

    <dl class="mt-3 space-y-3">
      <div v-for="dial in dials" :key="dial.key">
        <div class="flex items-baseline justify-between gap-3">
          <dt class="text-xs text-[var(--color-ink-muted)]">{{ dial.label }}</dt>
          <dd class="text-sm font-medium tabular-nums">
            {{ dial.text }}
            <span v-if="dial.unit" class="text-xs font-normal text-[var(--color-ink-muted)]">
              {{ dial.unit }}
            </span>
          </dd>
        </div>

        <div
          aria-hidden="true"
          class="mt-1.5 h-1 overflow-hidden rounded-full bg-[var(--color-border-subtle)]"
        >
          <div
            class="h-full transition-[width] duration-500"
            :style="{ width: `${String(dial.percent)}%`, backgroundColor: accentColor }"
          />
        </div>
      </div>
    </dl>
  </section>
</template>
