<script setup lang="ts">
import { computed } from 'vue'
import type { ViewerLayer } from '@/types/explore'

/**
 * Camera and rendering controls.
 *
 * Every label here says what the control actually does, which is the whole
 * point of this component. The audited upstream shipped an "Isolate" that
 * faded the plinth, a "Layers" that meant wireframe, and a "Compare" that was
 * a 2D drawer (docs/project-context.md §2.5). Those three names are not
 * reproduced. Where the viewer reports reduced semantics through
 * `capability:degraded`, the reason it gives is rendered next to the control
 * rather than swallowed (docs/architecture.md §5.3).
 *
 * Surface / Wireframe / Cut through is one radio group rather than a
 * "Layers" toggle plus a separate "Cross-section" toggle, because
 * `setLayer('section')` *is* the cross-section — the viewer turns clipping on
 * and off as the layer changes. Two independent switches over one piece of
 * state would let the UI show a combination the viewer cannot be in.
 *
 * Pan is documented, not buttoned. `OrbitControls` pans on right-drag and
 * two-finger drag, but the viewer exposes no programmatic pan, and a button
 * wired to nothing is exactly the failure this component exists to avoid.
 */
const props = defineProps<{
  /** The viewer is unusable — WebGL missing, or the model never arrived. */
  disabled: boolean
  autoRotate: boolean
  layer: ViewerLayer
  isolated: boolean
  /** Isolate needs something to isolate. */
  hasSelection: boolean
  reducedMotion: boolean
  /** capability → the viewer's own explanation of what it did instead. */
  degraded: Readonly<Record<string, string>>
}>()

const emit = defineEmits<{
  (event: 'update:autoRotate', value: boolean): void
  (event: 'update:layer', value: ViewerLayer): void
  (event: 'zoom', direction: 1 | -1): void
  (event: 'reset'): void
  (event: 'toggle-isolate'): void
}>()

const LAYERS: ReadonlyArray<{ value: ViewerLayer; label: string; hint: string }> = [
  { value: 'solid', label: 'Surface', hint: 'The model as it is textured.' },
  {
    value: 'wireframe',
    label: 'Wireframe',
    hint: 'Draws the mesh edges of the whole organ. Not superficial-to-deep anatomical layers.',
  },
  {
    value: 'section',
    label: 'Cut through',
    hint: 'One clipping plane across the whole organ, not a per-structure section.',
  },
]

/** Auto-rotate is refused outright under prefers-reduced-motion, by design. */
const rotateDisabled = computed(() => props.disabled || props.reducedMotion)

const notes = computed(() => Object.values(props.degraded))
</script>

<template>
  <div
    class="flex flex-wrap items-center gap-2 rounded-lg border border-[var(--color-border-subtle)] bg-[var(--color-surface-raised)] p-2"
    role="toolbar"
    aria-label="Viewer controls"
  >
    <button
      type="button"
      class="tool"
      :class="{ 'tool--on': autoRotate }"
      :disabled="rotateDisabled"
      :aria-pressed="autoRotate"
      :title="
        reducedMotion
          ? 'Unavailable: your system is set to reduce motion.'
          : 'Spin the model slowly on its vertical axis.'
      "
      @click="emit('update:autoRotate', !autoRotate)"
    >
      Auto-rotate
    </button>

    <div class="flex items-center gap-1" role="group" aria-label="Zoom">
      <button
        type="button"
        class="tool"
        :disabled="disabled"
        title="Move the camera closer."
        @click="emit('zoom', 1)"
      >
        Zoom in
      </button>
      <button
        type="button"
        class="tool"
        :disabled="disabled"
        title="Move the camera further away."
        @click="emit('zoom', -1)"
      >
        Zoom out
      </button>
    </div>

    <button
      type="button"
      class="tool"
      :disabled="disabled"
      title="Return the camera to its starting framing."
      @click="emit('reset')"
    >
      Reset view
    </button>

    <button
      type="button"
      class="tool"
      :class="{ 'tool--on': isolated }"
      :disabled="disabled || !hasSelection"
      :aria-pressed="isolated"
      :title="
        hasSelection
          ? 'Fade the organ and the other markers, and fly the camera to this structure.'
          : 'Select a structure first.'
      "
      @click="emit('toggle-isolate')"
    >
      {{ isolated ? 'Show everything' : 'Dim everything else' }}
    </button>

    <div class="flex items-center gap-1" role="radiogroup" aria-label="Rendering">
      <button
        v-for="option in LAYERS"
        :key="option.value"
        type="button"
        class="tool"
        :class="{ 'tool--on': layer === option.value }"
        role="radio"
        :aria-checked="layer === option.value"
        :disabled="disabled"
        :title="option.hint"
        @click="emit('update:layer', option.value)"
      >
        {{ option.label }}
      </button>
    </div>

    <p class="ml-auto text-xs text-[var(--color-ink-muted)]">
      Drag to rotate &middot; scroll to zoom &middot; right-drag or two-finger drag to pan
    </p>

    <!--
      The viewer's own account of what it did instead of what the label
      promises. Polite rather than assertive: it appears as a consequence of
      the student's click, so it must not interrupt them mid-sentence.
    -->
    <ul
      v-if="notes.length > 0"
      class="w-full list-none space-y-1 border-t border-[var(--color-border-subtle)] pt-2 text-xs text-[var(--color-ink-muted)]"
      aria-live="polite"
    >
      <li v-for="note in notes" :key="note">{{ note }}</li>
    </ul>
  </div>
</template>

<style scoped>
/*
 | Plain CSS, not `@apply`: Tailwind 4 resolves utilities against the sheet
 | that imports it, and a Vue scoped block is compiled on its own — using
 | `@apply` here needs an `@reference` re-parse of the whole stylesheet per
 | component, which is a build-time cost for no styling benefit.
 */
.tool {
  border-radius: 0.375rem;
  border: 1px solid var(--color-border-subtle);
  padding: 0.375rem 0.625rem;
  font-size: 0.75rem;
  line-height: 1rem;
  font-weight: 500;
  color: var(--color-ink);
  transition:
    color 150ms,
    background-color 150ms,
    border-color 150ms;
}

.tool:hover:not(:disabled) {
  background-color: var(--color-surface);
}

.tool:disabled {
  cursor: not-allowed;
  opacity: 0.4;
}

.tool--on {
  border-color: var(--color-accent);
  color: var(--color-accent);
}
</style>
