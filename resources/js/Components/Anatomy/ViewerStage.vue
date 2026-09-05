<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, reactive, ref, shallowRef, watch } from 'vue'
import { useAnatomyViewer } from '@/composables/useAnatomyViewer'
import StructureCallout from '@/Components/Explore/StructureCallout.vue'
import type { OrganDto, StructureDto, StructureId, ViewerLayer } from '@/types/explore'

/**
 * THE VIEWER MOUNT. Read this before wiring the viewer into a new page.
 *
 * Handovers 06, 07, 09 and 12 all put a 3D viewer on a page. They reuse this
 * component rather than repeating it: pass an `organ` and a `mode`, drive it
 * through the exposed commands, and put lane-specific chrome in the `overlay`
 * slot. Five rules make it work, and all five are load-bearing.
 *
 * 1. **`useAnatomyViewer` is the only bridge.** Nothing else in `resources/js`
 *    imports from `resources/js/anatomy/` (invariant 3, docs/architecture.md
 *    §5.1). This file imports the composable and, type-only, the DTO shapes.
 *
 * 2. **The container element has no Vue children.** `#surface` is empty and
 *    stays empty. The viewer appends its canvas and its own DOM into it, and
 *    Vue's patcher must never be asked to reconcile a subtree it did not
 *    render. Every other element here is a *sibling* of the container.
 *
 * 3. **No Three.js object is reactive.** The composable keeps the viewer in a
 *    closure variable, and nothing below puts a scene, camera, or material in
 *    a `ref` (docs/engineering.md §4). The `shallowRef`s here hold plain DTOs.
 *
 * 4. **The callout is positioned imperatively.** Its *content* is reactive —
 *    it changes once per selection. Its *position* is written straight to
 *    `style.transform` inside a `requestAnimationFrame` loop, so dragging the
 *    model costs zero Vue re-renders (docs/project-context.md §5.2). The loop
 *    only runs while something is selected.
 *
 * 5. **Disposal is automatic, and switching organs does not remount.** The
 *    composable disposes on `onBeforeUnmount` *and* `onScopeDispose`. Loading
 *    a different organ is a `loadOrgan` call on the live viewer, never a
 *    `v-if` that tears the component down — a remount drops the WebGL context
 *    and re-pays for it (docs/architecture.md §5.4 rules 6 and 7).
 */
const props = withDefaults(
  defineProps<{
    /** Null renders the empty state; the viewer is still constructed. */
    organ: OrganDto | null
    mode?: 'explore' | 'quiz' | 'mission' | 'author'
    reducedMotion?: boolean
    /** Hides the callout for lanes that render their own (quiz, mission). */
    showCallout?: boolean
  }>(),
  { mode: 'explore', reducedMotion: false, showCallout: true },
)

const emit = defineEmits<{
  (event: 'selected', structure: StructureDto | null): void
  (event: 'picked', structure: StructureDto): void
  (event: 'loaded', organ: OrganDto): void
}>()

const surface = ref<HTMLElement | null>(null)
const calloutAnchor = ref<HTMLElement | null>(null)

/** Local, because the viewer exposes no getters for these three. */
const autoRotate = ref(false)
const layer = ref<ViewerLayer>('solid')
const isolatedId = shallowRef<StructureId | null>(null)

/**
 * capability → the viewer's own explanation. Accumulated rather than replaced:
 * `degraded` on the composable holds only the most recent one, and the tool
 * rail annotates each control it applies to (docs/architecture.md §5.3).
 */
const degraded = reactive<Record<string, string>>({})

const organRef = computed(() => props.organ)

const viewer = useAnatomyViewer({
  container: surface,
  organ: organRef,
  initialMode: props.mode,
  reducedMotion: props.reducedMotion,
  on: {
    'structure:selected': ({ structure }) => emit('selected', structure),
    'structure:picked': ({ structure }) => emit('picked', structure),
    'organ:loaded': ({ organ }) => emit('loaded', organ),
    'capability:degraded': ({ capability, reason }) => {
      degraded[capability] = reason
    },
  },
})

/**
 * Destructured so the template reads `selectedStructure` rather than
 * `viewer.selectedStructure.value`: Vue unwraps a ref that is a top-level
 * setup binding, but not one reached through a property access.
 */
const { isAvailable, isLoading, progress, failure, selectedStructure } = viewer

/**
 * Organ switching.
 *
 * Not `immediate`: the composable already loads whatever `organ` held at
 * mount. Selection is cleared first and explicitly — `loadOrgan` resets the
 * viewer's internal selection without emitting, so without this the callout
 * would keep describing a structure from the organ that just left the screen.
 */
watch(
  () => props.organ,
  (organ) => {
    viewer.selectStructure(null)
    isolatedId.value = null

    if (organ === null) return
    void viewer.loadOrgan(organ)
  },
)

watch(
  () => props.mode,
  (mode) => viewer.setMode(mode),
)

/**
 * The viewer honours reduced motion at construction, and refuses auto-rotate
 * outright while it is set. Mirror that in local state so the rail does not
 * show a control as on when the viewer has declined it.
 */
watch(
  () => props.reducedMotion,
  (reduced) => {
    if (!reduced) return
    autoRotate.value = false
    viewer.setAutoRotate(false)
  },
)

// ---------------------------------------------------------------- commands

function setAutoRotate(enabled: boolean): void {
  autoRotate.value = enabled && !props.reducedMotion
  viewer.setAutoRotate(autoRotate.value)
}

function setLayer(next: ViewerLayer): void {
  layer.value = next
  viewer.setLayer(next)
}

function toggleIsolate(id: StructureId | null): void {
  const next = isolatedId.value === null && id !== null ? id : null
  isolatedId.value = next
  viewer.isolateStructure(next)
}

// -------------------------------------------------- imperative callout loop

let frame: number | null = null
let lastX = Number.NaN
let lastY = Number.NaN

function stopTracking(): void {
  if (frame === null) return
  cancelAnimationFrame(frame)
  frame = null
}

/**
 * One projection and, when it moved, one style write. No reactive state is
 * touched, so this never schedules a Vue update — which is the entire reason
 * the callout is not simply bound to a reactive `x`/`y` pair.
 */
function trackCallout(): void {
  const anchor = calloutAnchor.value
  const structure = viewer.selectedStructure.value

  if (anchor === null || structure === null) {
    stopTracking()
    return
  }

  const point = viewer.getStructureScreenPosition(structure.id)

  if (point === null) {
    anchor.style.opacity = '0'
  } else {
    if (point.x !== lastX || point.y !== lastY) {
      anchor.style.transform = `translate3d(${String(point.x)}px, ${String(point.y)}px, 0)`
      lastX = point.x
      lastY = point.y
    }

    // Behind the camera or on the far side of the organ: dimmed rather than
    // removed, so the card does not flicker in and out as the model turns.
    anchor.style.opacity = point.visible ? '1' : '0.35'
  }

  frame = requestAnimationFrame(trackCallout)
}

watch(viewer.selectedStructure, (structure) => {
  stopTracking()
  lastX = Number.NaN
  lastY = Number.NaN

  if (structure === null || !props.showCallout) return

  // The anchor is rendered by `v-if`, so it does not exist until the DOM has
  // caught up with the selection that just arrived.
  void nextTick(() => {
    if (viewer.selectedStructure.value === null) return
    if (frame === null) trackCallout()
  })
})

// The composable disposes the viewer itself. This is the loop it does not
// know about.
onBeforeUnmount(stopTracking)

const canInteract = computed(() => isAvailable.value && failure.value === null)

defineExpose({
  // state
  isAvailable,
  isLoading,
  progress,
  failure,
  selectedStructure,
  autoRotate,
  layer,
  isolatedId,
  degraded,
  canInteract,

  // commands
  selectStructure: viewer.selectStructure,
  focusStructure: viewer.focusStructure,
  highlightStructure: viewer.highlightStructure,
  flashStructure: viewer.flashStructure,
  prefetchOrgan: viewer.prefetchOrgan,
  resetView: viewer.resetView,
  zoom: viewer.zoom,
  setCrossSection: viewer.setCrossSection,
  setMode: viewer.setMode,
  triggerAnimation: viewer.triggerAnimation,
  applySimulationState: viewer.applySimulationState,
  setAutoRotate,
  setLayer,
  toggleIsolate,
})
</script>

<template>
  <div
    class="viewer-stage relative overflow-hidden rounded-lg border border-[var(--color-border-subtle)] bg-[var(--color-surface-raised)]"
  >
    <!--
      Rule 2: the viewer owns everything inside this element. It must stay
      empty in the template — no v-if, no slot, no text node.
    -->
    <div ref="surface" class="absolute inset-0" />

    <!-- Sibling, not child. Positioned by trackCallout(), never by Vue. -->
    <div
      v-if="showCallout && selectedStructure !== null"
      ref="calloutAnchor"
      class="pointer-events-none absolute left-0 top-0 z-20 will-change-transform"
    >
      <div class="pointer-events-auto -translate-x-1/2 -translate-y-full pb-4">
        <StructureCallout
          :structure="selectedStructure"
          :accent-color="organ?.accentColor ?? 'var(--color-accent)'"
          @close="viewer.selectStructure(null)"
          @isolate="toggleIsolate(selectedStructure.id)"
        />
      </div>
    </div>

    <div
      v-if="isLoading"
      class="absolute inset-x-0 top-0 z-10 h-0.5 bg-[var(--color-border-subtle)]"
      role="progressbar"
      aria-label="Loading the 3D model"
      :aria-valuenow="Math.round(progress * 100)"
    >
      <div
        class="h-full bg-[var(--color-accent)] transition-[width]"
        :style="{ width: `${String(Math.round(progress * 100))}%` }"
      />
    </div>

    <!--
      Every load path has a failure path (docs/architecture.md §5.4 rule 5).
      This explains what happened; the page's structure list and metadata carry
      on working regardless, which is why nothing here is a modal.
    -->
    <div
      v-if="!canInteract"
      class="absolute inset-0 z-10 flex items-center justify-center bg-[var(--color-surface-raised)] p-6"
      role="status"
    >
      <!--
        Opaque, not translucent. The card itself was always here; what changed
        in handover 14 is the backdrop. The viewer draws its plinth and contact
        shadow before it has anything to stand on them, so a stage with no model
        shows a large grey lens with an apology in the middle of it — and while
        the licence gate is open (docs/licence-log.md §4) that is every viewer on
        every page, which made an empty plinth the most prominent thing in the
        product. Covering it is a presentation choice and costs nothing: the
        instant a model loads, `canInteract` is true and this element is gone.
      -->
      <div
        class="max-w-sm rounded-lg border border-[var(--color-border-subtle)] bg-[var(--color-surface)] px-4 py-3 text-center shadow-sm"
      >
        <p class="text-xs leading-relaxed text-[var(--color-ink-muted)]">
          <template v-if="!isAvailable">
            This browser cannot show 3D graphics, so the model is unavailable.
          </template>
          <template v-else-if="failure">
            {{ failure.detail }}
          </template>
        </p>

        <!--
          Said once, here, rather than repeated by every page that mounts a
          stage: what still works. A failure state that only apologises leaves a
          student thinking the page is broken, when in fact everything they need
          to learn from it is beside them (PRD §31, §40).
        -->
        <p class="mt-2 text-xs leading-relaxed text-[var(--color-ink-muted)]">
          Everything else on this page works — every structure is listed, described and selectable
          without it.
        </p>
      </div>
    </div>

    <div v-else-if="organ === null" class="absolute inset-0 flex items-center justify-center p-6">
      <p class="text-xs text-[var(--color-ink-muted)]">No organ selected.</p>
    </div>

    <!-- Lane-specific chrome: the quiz bar, a mission step, a simulation dial. -->
    <slot name="overlay" />
  </div>
</template>

<style scoped>
/*
 | The viewer mirrors its markers as a real <ul> of buttons inside its own
 | container, for hosts that do not build one. This page does build one
 | (Components/Explore/StructureIndex.vue), and it is richer — TA terms, the
 | marker colour, the same list whether or not there is a canvas. Two mirrors
 | would mean two tab stops per structure and a screen reader reading every
 | name twice, so the viewer's copy is hidden here rather than duplicated.
 |
 | `:deep` because the viewer creates these nodes itself: they carry no
 | scope attribute for the compiler to match.
 */
.viewer-stage :deep(.anatomy-viewer__structures) {
  display: none;
}

.viewer-stage :deep(.anatomy-viewer__canvas) {
  display: block;
  width: 100%;
  height: 100%;
  touch-action: none;
}
</style>
