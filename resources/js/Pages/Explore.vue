<script setup lang="ts">
import { computed, ref, useTemplateRef, watch } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import ViewerStage from '@/Components/Anatomy/ViewerStage.vue'
import SectionLabel from '@/Components/Atelier/SectionLabel.vue'
import AutoRotateSwitch from '@/Components/Explore/AutoRotateSwitch.vue'
import ComingSoonPanel from '@/Components/Explore/ComingSoonPanel.vue'
import InfoPanel from '@/Components/Explore/InfoPanel.vue'
import OrganLibrary from '@/Components/Explore/OrganLibrary.vue'
import StructureIndex from '@/Components/Explore/StructureIndex.vue'
import TipNote from '@/Components/Explore/TipNote.vue'
import ToolRail from '@/Components/Explore/ToolRail.vue'
import TutorPanel from '@/Components/Tutor/TutorPanel.vue'
import { GUIDED_TOUR_ID } from '@/composables/useAnatomyViewer'
import { useLearningEvents } from '@/composables/useLearningEvents'
import { usePrefersReducedMotion } from '@/composables/usePrefersReducedMotion'
import { useStructureDetail } from '@/composables/useStructureDetail'
import type {
  ExploreOrganCard,
  OrganDto,
  StructureDto,
  StructureId,
  UpcomingOrganCard,
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
const props = withDefaults(
  defineProps<{
    organs: ExploreOrganCard[]
    upcoming?: UpcomingOrganCard[]
    organ: OrganDto | null
    comingSoon?: UpcomingOrganCard | null
  }>(),
  { upcoming: () => [], comingSoon: null },
)

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

  // `comingSoon` travels with `organ` on every switch. Both are null-or-set
  // states of the same question — what is on the stage — so asking for one
  // without the other leaves a deep link to /explore/stomach showing its
  // "coming soon" panel next to the heart the student just opened.
  router.visit(`/explore/${organ.slug}`, {
    only: ['organ', 'comingSoon'],
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

/**
 * Named for whatever is on the stage: an organ, a taxonomy row, or neither.
 * A deep link to a coming-soon organ that titles itself just "Explore" makes
 * the browser tab and the history entry useless for the one navigation a
 * student is most likely to want back.
 */
const pageTitle = computed(() => {
  if (props.organ !== null) return `Explore · ${props.organ.name}`
  if (props.comingSoon !== null) return `Explore · ${props.comingSoon.name} (coming soon)`

  return 'Explore'
})

/**
 * The card for the organ on screen, for the two things the `OrganDto` does not
 * carry: its body system and its thumbnail. Both already travel with the
 * library payload, so the panel gets them from there rather than from a second
 * request.
 */
const currentCard = computed(
  () => props.organs.find((card) => card.slug === props.organ?.slug) ?? null,
)

/**
 * The honest form of the handover's "Animate".
 *
 * The models carry no animation clips, so this is the viewer's scripted camera
 * and marker choreography over the organ's structures. `InfoPanel` hides the
 * control when there is nothing to choreograph, which is the same condition the
 * viewer tests before reporting the capability degraded.
 */
function playGuidedTour(): void {
  // Not tracked: `ClientEventType` is the closed set a browser is allowed to
  // assert, and adding to it means adding to App\Enums\LearningEventType —
  // the Assessment lane's file, and not something a restyle may reach into.
  void stage.value?.triggerAnimation(GUIDED_TOUR_ID)
}
</script>

<template>
  <Head :title="pageTitle" />

  <!--
    One h1, and it is not visible: the organ's name is set at display size by
    the information panel, and a second copy of it above the canvas would be
    the same words twice at two sizes. The heading still has to exist for the
    document outline and for the skip link's target to lead somewhere named.
  -->
  <h1 class="sr-only">{{ organ ? `Explore the ${organ.name}` : 'Explore' }}</h1>

  <div class="grid items-start gap-5 lg:grid-cols-[17rem_minmax(0,1fr)_22rem]">
    <!-- Navigation: the library, then what is on the model in front of you. -->
    <div class="space-y-4 lg:sticky lg:top-6 lg:max-h-[calc(100vh-3rem)] lg:overflow-y-auto">
      <OrganLibrary
        :organs="organs"
        :upcoming="upcoming"
        :current-slug="organ?.slug ?? null"
        @select="openOrgan"
        @prefetch="prefetchOrgan"
      />

      <StructureIndex
        v-if="organ"
        :structures="structures"
        :selected-id="selectedId"
        :accent-color="organ.accentColor"
        @select="openStructure"
        @hover="highlightStructure"
      />
    </div>

    <!--
      The stage. Everything that floats over the canvas goes in the overlay
      slot, so ViewerStage stays the shared mount F06, F07, F09 and F12 use and
      none of them inherits Explore's chrome.
    -->
    <ViewerStage
      ref="stage"
      :organ="organ"
      :reduced-motion="prefersReducedMotion"
      class="aspect-[4/3] w-full"
      @selected="onViewerSelected"
    >
      <template #overlay>
        <ToolRail
          :disabled="!canInteract"
          :layer="stage?.layer ?? 'solid'"
          :isolated="(stage?.isolatedId ?? null) !== null"
          :has-selection="selected !== null"
          :degraded="stage?.degraded ?? {}"
          @update:layer="changeLayer"
          @zoom="(direction: 1 | -1) => stage?.zoom(direction)"
          @reset="stage?.resetView()"
          @toggle-isolate="toggleIsolate"
        />

        <template v-if="canInteract">
          <TipNote />

          <!--
            On a scrim, not bare on the canvas. The label floats over whatever
            the model and its plinth happen to be, and warm grey on mid-grey is
            2.2:1 — a caption that is only legible against a pale specimen is
            not a caption.
          -->
          <SectionLabel
            class="absolute bottom-3 left-3 z-10 rounded-full bg-[var(--color-surface)]/85 px-2.5 py-1"
          >
            3D specimen<template v-if="organ"> &middot; {{ organ.name }}</template>
          </SectionLabel>

          <AutoRotateSwitch
            :model-value="stage?.autoRotate ?? false"
            :reduced-motion="prefersReducedMotion"
            @update:model-value="(value: boolean) => stage?.setAutoRotate(value)"
          />
        </template>
      </template>
    </ViewerStage>

    <!-- Detail, and the seat handover 08 takes. -->
    <div class="space-y-4 lg:sticky lg:top-6 lg:max-h-[calc(100vh-3rem)] lg:overflow-y-auto">
      <!--
        A slug that is in the taxonomy with no model yet. It replaces the info
        panel rather than sitting above it: with `organ` null the panel has no
        organ to describe, and two empty states stacked is not more honest than
        one that says what is going on (handover 16).
      -->
      <ComingSoonPanel v-if="comingSoon" :organ="comingSoon" />

      <InfoPanel
        v-else
        :organ="organ"
        :structure="selected"
        :detail="detail"
        :detail-failed="detailFailed"
        :selectable-ids="selectableIds"
        :body-system-name="currentCard?.bodySystem?.name ?? null"
        :thumbnail-url="currentCard?.thumbnailUrl ?? null"
        @select-related="openStructure"
        @play-tour="playGuidedTour"
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
