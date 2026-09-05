<script setup lang="ts">
import { computed, ref, useTemplateRef, watch } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import ViewerStage from '@/Components/Anatomy/ViewerStage.vue'
import InfoPanel from '@/Components/Explore/InfoPanel.vue'
import OrganLibrary from '@/Components/Explore/OrganLibrary.vue'
import StructureIndex from '@/Components/Explore/StructureIndex.vue'
import ToolRail from '@/Components/Explore/ToolRail.vue'
import TutorPanel from '@/Components/Tutor/TutorPanel.vue'
import { useLearningEvents } from '@/composables/useLearningEvents'
import { usePrefersReducedMotion } from '@/composables/usePrefersReducedMotion'
import { useStructureDetail } from '@/composables/useStructureDetail'
import type {
  ExploreOrganCard,
  OrganDto,
  StructureDto,
  StructureId,
  ViewerLayer,
} from '@/types/explore'

/**
 * Explore — PRD §6 (§5.1) and handover 05.
 *
 * The page owns the layout and the data; `ViewerStage` owns the viewer. The
 * mounting rules that matter to F06, F07, F09 and F12 are documented on that
 * component; two page-level ones live here:
 *
 * - **Switching organs is a partial reload, not a navigation.** `router.visit`
 *   asks for the `organ` prop alone with `preserveState`, so the Vue tree —
 *   and with it the WebGL context, the decoded model cache, and the render
 *   loop — survives. Rendering `<ViewerStage v-if="organ">` or keying it by
 *   slug would throw all three away on every click.
 * - **The structure list is not a fallback.** It is always on screen, so the
 *   keyboard path and the no-WebGL path are the same path and neither can rot
 *   (docs/architecture.md §5.4 rule 5).
 * - **Selection is page state, mirrored into the viewer — not read out of it.**
 *   With no WebGL, or a model that 404s, there are no hotspots and the viewer
 *   cannot hold a selection at all. Reading selection off the viewer would
 *   make the structure list, the callout text, and the info panel go dead
 *   exactly when they are the only thing left (PRD §31, §40).
 *
 * Handover 10 added the analytics calls. These four events happen only in the
 * browser, so nothing on the server can observe them; `useLearningEvents`
 * batches them and flushes to POST /api/v1/events (PRD §29,
 * docs/architecture.md §13). They are fire-and-forget — no branch in this file
 * depends on one, and the viewer itself neither knows about them nor fetches
 * (invariant 3).
 */
const props = defineProps<{
  organs: ExploreOrganCard[]
  organ: OrganDto | null
}>()

const stage = useTemplateRef<InstanceType<typeof ViewerStage>>('stage')

const { track } = useLearningEvents()

const prefersReducedMotion = usePrefersReducedMotion()

const selectedId = ref<StructureId | null>(null)

const structures = computed(() => props.organ?.structures ?? [])

const selected = computed<StructureDto | null>(
  () => structures.value.find((structure) => structure.id === selectedId.value) ?? null,
)

/** The other direction: a marker clicked in the canvas. */
function onViewerSelected(structure: StructureDto | null): void {
  selectedId.value = structure?.id ?? null

  if (structure !== null) {
    track('structure_selected', { contextType: 'structure', contextId: structure.id })
  }
}

// Switching organs invalidates the selection before the new `organ` prop's
// structures arrive, and an id from the previous organ would resolve to null
// on the next render anyway. Clearing explicitly keeps the panel from
// flickering through a stale name.
watch(
  () => props.organ?.slug,
  () => {
    selectedId.value = null
  },
)

// Reported from the prop rather than from the click, so a deep link to
// /explore/heart counts as a view and a click counts exactly once. `immediate`
// covers the first render, which is the deep-link case.
watch(
  () => props.organ?.id,
  (id) => {
    if (id !== undefined) {
      track('organ_viewed', { contextType: 'organ', contextId: id })
    }
  },
  { immediate: true },
)

/** Adds `location` and the related-structure links; never gates the panel. */
const { detail, failed: detailFailed } = useStructureDetail(selectedId)

const selectableIds = computed(() => structures.value.map((structure) => structure.id))

const canInteract = computed(() => stage.value?.canInteract ?? false)

function openOrgan(organ: ExploreOrganCard): void {
  if (organ.slug === props.organ?.slug) return

  router.visit(`/explore/${organ.slug}`, {
    only: ['organ'],
    preserveState: true,
    preserveScroll: true,
  })
}

/**
 * Warm the GLB while the pointer is still on the card. A miss costs nothing —
 * `prefetchOrgan` is fire-and-forget by contract.
 */
function prefetchOrgan(organ: ExploreOrganCard): void {
  if (organ.slug === props.organ?.slug) return
  stage.value?.prefetchOrgan(organ.modelUrl)
}

/**
 * Select, then ask the camera to follow. In that order: the panel and the
 * structure list must respond even when there is nothing for the camera to
 * fly to, which is the case for every organ until the licence gate closes and
 * real models land (docs/licence-log.md §3).
 */
function openStructure(id: StructureId): void {
  selectedId.value = id
  stage.value?.focusStructure(id)

  track('structure_selected', { contextType: 'structure', contextId: id })
}

/**
 * Isolate and layer changes, reported because the viewer cannot report them.
 *
 * Wrapped rather than tracked inside `ToolRail`: the component is a set of
 * controls and knows nothing about the organ they act on, and an event with no
 * context is an event nobody can group.
 */
function toggleIsolate(): void {
  stage.value?.toggleIsolate(selectedId.value)

  if (selectedId.value !== null) {
    track('structure_isolated', { contextType: 'structure', contextId: selectedId.value })
  }
}

function changeLayer(layer: ViewerLayer): void {
  stage.value?.setLayer(layer)

  track('layer_changed', { payload: { layer } })
}

function highlightStructure(id: StructureId | null): void {
  stage.value?.highlightStructure(id)
}
</script>

<template>
  <Head :title="organ ? `Explore · ${organ.name}` : 'Explore'" />

  <div class="grid gap-4 lg:grid-cols-[13rem_minmax(0,1fr)_17rem]">
    <!-- Organ library and structure index: one column of navigation. -->
    <div class="space-y-6">
      <section aria-labelledby="organ-library-heading">
        <h2
          id="organ-library-heading"
          class="mb-2 text-[0.6875rem] font-semibold uppercase tracking-wide text-[var(--color-ink-muted)]"
        >
          Organs
        </h2>

        <OrganLibrary
          :organs="organs"
          :current-slug="organ?.slug ?? null"
          @select="openOrgan"
          @prefetch="prefetchOrgan"
        />

        <p v-if="organs.length === 0" class="text-xs text-[var(--color-ink-muted)]">
          No organs have been published yet.
        </p>
      </section>

      <section v-if="organ" aria-labelledby="structure-index-heading">
        <h2
          id="structure-index-heading"
          class="mb-2 text-[0.6875rem] font-semibold uppercase tracking-wide text-[var(--color-ink-muted)]"
        >
          Structures
        </h2>

        <StructureIndex
          :structures="structures"
          :selected-id="selectedId"
          :accent-color="organ.accentColor"
          @select="openStructure"
          @hover="highlightStructure"
        />
      </section>
    </div>

    <!-- The viewer and its controls. -->
    <div class="space-y-3">
      <h1 class="text-xl font-semibold tracking-tight">
        {{ organ ? organ.name : 'Explore' }}
      </h1>

      <ViewerStage
        ref="stage"
        :organ="organ"
        :reduced-motion="prefersReducedMotion"
        class="aspect-[4/3] w-full"
        @selected="onViewerSelected"
      />

      <ToolRail
        :disabled="!canInteract"
        :auto-rotate="stage?.autoRotate ?? false"
        :layer="stage?.layer ?? 'solid'"
        :isolated="(stage?.isolatedId ?? null) !== null"
        :has-selection="selected !== null"
        :reduced-motion="prefersReducedMotion"
        :degraded="stage?.degraded ?? {}"
        @update:auto-rotate="(value: boolean) => stage?.setAutoRotate(value)"
        @update:layer="changeLayer"
        @zoom="(direction: 1 | -1) => stage?.zoom(direction)"
        @reset="stage?.resetView()"
        @toggle-isolate="toggleIsolate"
      />
    </div>

    <!-- Detail, and the seat handover 08 takes. -->
    <div class="space-y-6">
      <InfoPanel
        :organ="organ"
        :structure="selected"
        :detail="detail"
        :detail-failed="detailFailed"
        :selectable-ids="selectableIds"
        @select-related="openStructure"
      />

      <!--
        AI tutor panel — the seat handover 05 left and handover 08 built for
        (D3 in docs/handovers/parallel-execution-plan.md). Handover 14 wired
        it: both lanes shipped their half and deferred the join to the other,
        so the panel existed only on its own /tutor route and PRD §2.3 step 6
        — asking about the structure you just selected — had no home.

        Note the `Number()`: `StructureId` is an opaque string on this side of
        the contract (docs/architecture.md §5.4 rule 4) and the panel's prop is
        typed as an int. Converting at the boundary is correct; widening
        StructureId is not.
      -->
      <div id="explore-tutor-slot" data-slot="ai-tutor" class="min-h-[26rem]">
        <TutorPanel
          :organ-slug="organ?.slug ?? null"
          :organ-name="organ?.name ?? null"
          :structure-id="selectedId === null ? null : Number(selectedId)"
          :structure-name="selected?.name ?? null"
        />
      </div>
    </div>
  </div>
</template>
