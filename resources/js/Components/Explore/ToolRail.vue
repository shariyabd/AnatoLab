<script setup lang="ts">
import { computed } from 'vue'
import Icon from '@/Components/Atelier/Icon.vue'
import type { ViewerLayer } from '@/types/explore'

/**
 * The viewer tool rail — handover 05, rebuilt by handover 15 Phase 3.
 *
 * A floating vertical pill column over the canvas. Every caption says what the
 * control actually does, which is the whole point of this component: the
 * audited upstream shipped an "Isolate" that faded the plinth, a "Layers" that
 * meant wireframe, and a "Compare" that was a 2D drawer
 * (docs/project-context.md §2.5). None of those three names is reproduced, and
 * Compare is not here at all.
 *
 * **Rotate and Pan are gestures, not buttons.** The handover's rail lists both.
 * `OrbitControls` orbits on drag and pans on right-drag or two-finger drag —
 * both work — but the viewer exposes no programmatic `pan()` or orbit command,
 * and adding one is a change to the §5.2 interface that neither branch of this
 * handover is allowed to make. A button wired to nothing is exactly the failure
 * this component exists to prevent, so the two gestures are stated in the
 * canvas tip note instead. See the delivery report.
 *
 * **Wireframe and Cross-section are one piece of state wearing two buttons.**
 * The viewer holds a single `layer`, and `setLayer('section')` *is* the
 * cross-section. Each button toggles its own layer against `solid`, so turning
 * one on turns the other off — which is the truth about the viewer, rather than
 * two independent switches that could ask for a state it cannot be in.
 *
 * **Hidden, not disabled.** A control the viewer cannot honour is removed, not
 * greyed out: Focus with nothing selected is gone until something is selected,
 * and the whole rail disappears when there is no usable viewer under it.
 *
 * The same rule is applied to `capability:degraded`, with one qualification the
 * handover does not make and docs/architecture.md §5.3 does. §5.3 defines the
 * reduced semantics of `isolate`, `layers` and `crossSection` as the *contract*
 * rather than as a fault, and the viewer emits `capability:degraded` on every
 * successful use of all three. Hiding on that signal alone would empty the rail
 * after one click. So a tool is removed when its capability is reported
 * degraded and its caption does not already state the reduction; all three here
 * do state it, so they stay and the viewer's explanation is surfaced as a note
 * beside the canvas. See the delivery report.
 */
const props = defineProps<{
  /** The viewer is unusable — WebGL missing, or the model never arrived. */
  disabled: boolean
  layer: ViewerLayer
  isolated: boolean
  /** Focus needs something to focus on. */
  hasSelection: boolean
  /** capability → the viewer's own explanation of what it did instead. */
  degraded: Readonly<Record<string, string>>
}>()

const emit = defineEmits<{
  (event: 'update:layer', value: ViewerLayer): void
  (event: 'zoom', direction: 1 | -1): void
  (event: 'reset'): void
  (event: 'toggle-isolate'): void
}>()

interface Tool {
  readonly key: string
  readonly icon: string
  readonly caption: string
  readonly hint: string
  /** The viewer capability this control drives, where it has one. */
  readonly capability?: 'isolate' | 'layers' | 'crossSection'
  /**
   * Whether `caption` already tells the truth about the reduced behaviour. A
   * control that does is kept when the viewer reports it degraded; one that
   * does not is removed rather than shown with an excuse attached.
   */
  readonly captionStatesReduction?: boolean
  readonly pressed?: boolean
  /** The viewer has nothing to apply this to right now. Removed, not disabled. */
  readonly unavailable?: boolean
  readonly run: () => void
}

const tools = computed<Tool[]>(() => [
  {
    key: 'zoom-in',
    icon: 'zoom-in',
    caption: 'Zoom in',
    hint: 'Move the camera closer.',
    run: () => emit('zoom', 1),
  },
  {
    key: 'zoom-out',
    icon: 'zoom-out',
    caption: 'Zoom out',
    hint: 'Move the camera further away.',
    run: () => emit('zoom', -1),
  },
  {
    key: 'focus',
    icon: 'focus',
    caption: 'Focus',
    hint: 'Fade the organ and the other markers, and fly the camera to this structure.',
    capability: 'isolate',
    captionStatesReduction: true,
    pressed: props.isolated,
    unavailable: !props.hasSelection,
    run: () => emit('toggle-isolate'),
  },
  {
    key: 'wireframe',
    icon: 'grid',
    caption: 'Wireframe',
    hint: 'Draws the mesh edges of the whole organ. Not superficial-to-deep anatomical layers.',
    capability: 'layers',
    captionStatesReduction: true,
    pressed: props.layer === 'wireframe',
    run: () => emit('update:layer', props.layer === 'wireframe' ? 'solid' : 'wireframe'),
  },
  {
    key: 'section',
    icon: 'slice',
    caption: 'Cross-section',
    hint: 'One clipping plane across the whole organ, not a per-structure section.',
    capability: 'crossSection',
    captionStatesReduction: true,
    pressed: props.layer === 'section',
    run: () => emit('update:layer', props.layer === 'section' ? 'solid' : 'section'),
  },
  {
    key: 'reset',
    icon: 'reset',
    caption: 'Reset',
    hint: 'Return the camera to its starting framing.',
    run: () => emit('reset'),
  },
])

const visible = computed(() =>
  tools.value.filter((tool) => {
    if (tool.unavailable === true) return false
    if (tool.capability === undefined) return true
    if (props.degraded[tool.capability] === undefined) return true

    return tool.captionStatesReduction === true
  }),
)

const notes = computed(() => Object.values(props.degraded))
</script>

<template>
  <div v-if="!disabled" class="pointer-events-none absolute inset-y-0 left-3 flex items-center">
    <div
      class="pointer-events-auto flex w-[4.5rem] flex-col gap-0.5 rounded-full bg-[var(--color-surface)] p-1.5 shadow-rail"
      role="toolbar"
      aria-label="Viewer controls"
      aria-orientation="vertical"
    >
      <button
        v-for="tool in visible"
        :key="tool.key"
        type="button"
        class="flex flex-col items-center gap-1 rounded-full px-1 py-2 transition-colors"
        :class="
          tool.pressed === true
            ? 'bg-[var(--color-accent-soft)] text-[var(--color-accent-ink)]'
            : 'text-[var(--color-ink-soft)] hover:bg-[var(--color-surface-sunk)] hover:text-[var(--color-ink)]'
        "
        :aria-pressed="tool.pressed === undefined ? undefined : tool.pressed"
        :title="tool.hint"
        @click="tool.run()"
      >
        <Icon :name="tool.icon" class="size-5" />
        <span class="text-[0.625rem] leading-[1.15] font-medium">{{ tool.caption }}</span>
      </button>
    </div>
  </div>

  <!--
    The viewer's own account of what it did instead of what the caption
    promises. Polite rather than assertive: it appears as a consequence of the
    student's click, so it must not interrupt them mid-sentence. Outside the
    rail because the rail is a fixed-width column and these are sentences.
  -->
  <ul
    v-if="!disabled && notes.length > 0"
    class="pointer-events-none absolute inset-x-3 bottom-3 z-10 list-none space-y-1 sm:left-24 sm:right-40"
    aria-live="polite"
  >
    <li
      v-for="note in notes"
      :key="note"
      class="pointer-events-auto rounded-tile bg-[var(--color-surface)] px-3 py-2 text-xs leading-snug text-[var(--color-ink-soft)] shadow-card"
    >
      {{ note }}
    </li>
  </ul>
</template>
