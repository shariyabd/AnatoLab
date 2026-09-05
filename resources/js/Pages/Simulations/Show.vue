<script setup lang="ts">
import { computed, useTemplateRef, watch } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import ViewerStage from '@/Components/Anatomy/ViewerStage.vue'
import ActionList from '@/Components/Simulations/ActionList.vue'
import EventLog from '@/Components/Simulations/EventLog.vue'
import OutcomePanel from '@/Components/Simulations/OutcomePanel.vue'
import StateReadout from '@/Components/Simulations/StateReadout.vue'
import { usePrefersReducedMotion } from '@/composables/usePrefersReducedMotion'
import { useSimulation } from '@/composables/useSimulation'
import type { SimulationDto, SimulationLogEntry, SimulationStep } from '@/types/simulations'

/**
 * One run of a simulation — PRD §14, handover 12.
 *
 * The page follows the mounting pattern `ViewerStage` documents: one viewer,
 * constructed once, never remounted, driven through the exposed commands, with
 * lane chrome in the `overlay` slot. Two things are specific to this lane.
 *
 * **The viewer is told, never asked.** `applySimulationState` is called from a
 * watch on `step`, with the `visualDirectives` object the server sent, passed
 * through unchanged. This page computes no directive, maps no key, and knows
 * what none of them mean — the vocabulary is closed and the viewer is the only
 * thing that interprets it (docs/architecture.md §12).
 *
 * **The state is the server's.** Nothing here adds a delta or evaluates a
 * threshold; `useSimulation` has never seen the effects table. So a reload
 * shows the same run, and replaying the same actions shows the same numbers,
 * because there is only one place they are ever produced.
 *
 * The page is usable with no WebGL at all. The readouts, the outcomes, the
 * explanation and the action list are DOM, and the whole simulation is
 * runnable from them alone (docs/architecture.md §5.4 rule 5).
 */
const props = defineProps<{
  simulation: SimulationDto
  step: SimulationStep
  events: SimulationLogEntry[]
}>()

const stage = useTemplateRef<InstanceType<typeof ViewerStage>>('stage')
const prefersReducedMotion = usePrefersReducedMotion()

const run = useSimulation(props.simulation, props.step, props.events)

const organ = computed(() => props.simulation.organ)

/**
 * The only place the 3D view is driven, and it maps nothing.
 *
 * The directive object goes to the viewer exactly as the server built it. This
 * page does not know what `pulseRate` or `crossSection` mean, and must not:
 * the vocabulary is closed and the viewer is the only thing that interprets it
 * (docs/architecture.md §12, `resources/js/anatomy/simulation.ts`).
 */
function applyToViewer(step: SimulationStep): void {
  stage.value?.applySimulationState({
    state: step.variables,
    visualDirectives: step.visualDirectives,
    explanation: step.explanation,
  })
}

watch(run.step, applyToViewer)

/**
 * A resumed run is applied when the model arrives, not at mount.
 *
 * A student who left the page mid-run must come back to the model they left,
 * rather than to an untouched one. It cannot be an `immediate` watch: the
 * viewer needs its GLB attached before a `focus` or a `highlight` has a marker
 * to address, and a directive issued into an empty scene is a directive
 * silently dropped — the exact failure the closed vocabulary exists to prevent
 * (docs/architecture.md §5.3).
 */
function onOrganLoaded(): void {
  applyToViewer(run.step.value)
}
</script>

<template>
  <Head :title="`Simulation · ${simulation.title}`" />

  <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_22rem]">
    <div class="space-y-3">
      <div>
        <h1 class="text-xl font-semibold tracking-tight">{{ simulation.title }}</h1>
        <p class="mt-1 text-sm text-[var(--color-ink-muted)]">{{ simulation.premise }}</p>
      </div>

      <ViewerStage
        ref="stage"
        :organ="organ"
        mode="explore"
        :reduced-motion="prefersReducedMotion"
        class="aspect-[4/3] w-full"
        @loaded="onOrganLoaded"
      />

      <OutcomePanel :step="run.step.value" />
    </div>

    <div class="space-y-5">
      <ActionList
        :actions="simulation.actions"
        :disabled="run.isBusy.value"
        :accent-color="simulation.organ.accentColor"
        @apply="run.apply"
      />

      <StateReadout
        :readouts="simulation.readouts"
        :step="run.step.value"
        :accent-color="simulation.organ.accentColor"
      />

      <EventLog :events="run.events.value" />

      <div class="flex flex-wrap gap-2">
        <button
          type="button"
          :disabled="run.isBusy.value || run.step.value.sequence === 0"
          class="rounded-md border border-[var(--color-border-subtle)] px-3 py-2 text-sm font-medium disabled:cursor-not-allowed disabled:opacity-60"
          @click="run.reset"
        >
          Start again
        </button>

        <Link
          :href="`/explore/${simulation.organ.slug}`"
          class="rounded-md border border-[var(--color-border-subtle)] px-3 py-2 text-sm font-medium"
        >
          Explore the {{ simulation.organ.name.toLowerCase() }}
        </Link>
      </div>

      <p
        v-if="run.error.value"
        role="alert"
        class="rounded-md border border-[var(--color-danger)] px-3 py-2 text-xs text-[var(--color-danger)]"
      >
        {{ run.error.value }}
      </p>
    </div>
  </div>
</template>
