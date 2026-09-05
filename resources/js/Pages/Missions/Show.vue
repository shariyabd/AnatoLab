<script setup lang="ts">
import { computed, useTemplateRef, watch } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import ViewerStage from '@/Components/Anatomy/ViewerStage.vue'
import MissionSummary from '@/Components/Missions/MissionSummary.vue'
import StepPanel from '@/Components/Missions/StepPanel.vue'
import StepTrail from '@/Components/Missions/StepTrail.vue'
import { useMission } from '@/composables/useMission'
import { usePrefersReducedMotion } from '@/composables/usePrefersReducedMotion'
import type { MissionDto, StructureDto, StructureId } from '@/types/missions'

/**
 * One run at a mission — PRD §13 and handover 09.
 *
 * The page follows the mounting pattern `ViewerStage` documents: one viewer,
 * constructed once, never remounted, driven through the exposed commands, with
 * lane chrome in the `overlay` slot. It adds no viewer method — `setMode`,
 * `focusStructure` and `flashStructure` are all in handover 04's merged
 * interface (docs/architecture.md §5.2).
 *
 * Three things are specific to this lane.
 *
 * **The viewer is told nothing during the run.** No flash, no colour, no
 * highlight on a pick — the run has not been graded, and this page could not
 * grade it: `MissionDto` carries no target
 * (docs/handovers/09-missions.md, "the rule that matters"). `mode="mission"`
 * makes a click report `structure:picked` without changing selection, so a
 * pick leaves no sticky highlight to read backwards, and the callout is off
 * because a callout naming the structure under the cursor would answer every
 * step.
 *
 * **Feedback arrives in one piece, afterwards.** When the server returns the
 * outcomes, the page walks the student back through the pathway: choosing a
 * step in the trail flies the camera to what they picked and flashes it in its
 * true colour, and on the step that broke it also rings the right structure
 * green. Red on what was clicked, green on what was right — the audited
 * behaviour, and the reason it exists: a student told only that they were wrong
 * has learned nothing (docs/project-context.md §2.4).
 *
 * **The run is playable with no WebGL at all.** The prompt, the hint, the trail
 * and the keyboard structure list are DOM, and every step is answerable from
 * that list alone (docs/architecture.md §5.4 rule 5).
 */
const props = defineProps<{
  mission: MissionDto
}>()

const stage = useTemplateRef<InstanceType<typeof ViewerStage>>('stage')
const prefersReducedMotion = usePrefersReducedMotion()

const run = useMission(props.mission)

const organ = computed(() => props.mission.organ)
const structures = computed<readonly StructureDto[]>(() => props.mission.organ.structures)
const prompts = computed<readonly string[]>(() => props.mission.steps.map((step) => step.prompt))

const running = computed(() => run.phase.value === 'running')

/**
 * The one place the viewer is told anything about correctness, and it happens
 * strictly downstream of a server verdict.
 *
 * Driven by which step is being reviewed rather than fired once on arrival, so
 * walking back and forth through the trail replays the choreography for each
 * step instead of only ever showing the first.
 */
watch(run.reviewed, (step) => {
  if (step === null) return

  const correct = step.outcome !== 'wrong'

  if (step.selectedStructureId !== null) {
    stage.value?.focusStructure(step.selectedStructureId)
    stage.value?.flashStructure(step.selectedStructureId, correct)
  }

  // On the step that broke, also ring the right one green — including when the
  // student answered from the keyboard list, or skipped it entirely and never
  // picked a marker at all.
  if (step.revealedStructureId !== null && step.revealedStructureId !== step.selectedStructureId) {
    stage.value?.focusStructure(step.revealedStructureId)
    stage.value?.flashStructure(step.revealedStructureId, true)
  }
})

function onPicked(structure: StructureDto): void {
  if (!running.value) return
  run.commit(structure.id)
}

function onPickStructure(structureId: StructureId): void {
  if (!running.value) return
  run.commit(structureId)
}
</script>

<template>
  <Head :title="`Mission · ${mission.title}`" />

  <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_22rem]">
    <div class="space-y-3">
      <div>
        <h1 class="text-xl font-semibold tracking-tight">{{ mission.title }}</h1>
        <p v-if="mission.description" class="mt-1 text-sm text-[var(--color-ink-muted)]">
          {{ mission.description }}
        </p>
      </div>

      <ViewerStage
        ref="stage"
        :organ="organ"
        mode="mission"
        :show-callout="false"
        :reduced-motion="prefersReducedMotion"
        class="aspect-[4/3] w-full"
        @picked="onPicked"
      />
    </div>

    <div class="space-y-5">
      <StepPanel
        v-if="run.current.value"
        :step="run.current.value"
        :index="run.index.value"
        :total="run.total.value"
        :structures="structures"
        :ordered="mission.ordered"
        :disabled="!running"
        :hint-shown="run.hintShown.value"
        :can-go-back="run.index.value > 0"
        @pick="onPickStructure"
        @skip="run.skip"
        @back="run.back"
        @show-hint="run.showHint"
      />

      <p
        v-else-if="run.phase.value === 'submitting'"
        role="status"
        class="text-sm text-[var(--color-ink-muted)]"
      >
        Checking the run…
      </p>

      <MissionSummary
        v-if="run.outcome.value"
        :outcome="run.outcome.value"
        :max-score="mission.maxScore"
      />

      <StepTrail
        :prompts="prompts"
        :picks="run.picks.value"
        :structures="structures"
        :outcome="run.outcome.value"
        :active-index="run.phase.value === 'running' ? run.index.value : run.reviewIndex.value"
        @select="run.review"
      />

      <div v-if="run.outcome.value" class="flex gap-2">
        <button
          type="button"
          class="rounded-md bg-[var(--color-accent)] px-3 py-2 text-sm font-medium text-[var(--color-accent-ink)]"
          @click="run.restart"
        >
          Run it again
        </button>

        <Link
          :href="`/explore/${mission.organ.slug}`"
          class="rounded-md border border-[var(--color-border-subtle)] px-3 py-2 text-sm font-medium"
        >
          Explore the {{ mission.organ.name.toLowerCase() }}
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
