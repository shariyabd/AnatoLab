<script setup lang="ts">
import { onMounted, ref } from 'vue'
import Icon from '@/Components/Atelier/Icon.vue'

/**
 * The canvas tip note — handover 15 Phase 3.
 *
 * A sticky note in the corner of the stage, carrying the two interactions that
 * have no button: orbit and pan are `OrbitControls` gestures, and the viewer
 * exposes no command for either, so this is where they are taught rather than
 * on a rail button wired to nothing (see ToolRail).
 *
 * Dismissal persists per browser. `localStorage` can throw outright in private
 * mode or with site data blocked, so every access is guarded and a failure
 * simply means the note comes back next time — which is the harmless direction
 * to fail in.
 */
const STORAGE_KEY = 'anatolab.explore.tip-dismissed'

const dismissed = ref(true)

/*
 | Starts dismissed and is revealed on mount, not the other way round: reading
 | storage during setup would render the note for one frame on a server-rendered
 | pass and then tear it away, which reads as a flicker rather than a dismissal.
 */
onMounted(() => {
  try {
    dismissed.value = localStorage.getItem(STORAGE_KEY) === '1'
  } catch {
    dismissed.value = false
  }
})

function dismiss(): void {
  dismissed.value = true

  try {
    localStorage.setItem(STORAGE_KEY, '1')
  } catch {
    // Nothing to do: the note is gone for this page view and will return.
  }
}
</script>

<template>
  <div
    v-if="!dismissed"
    class="absolute right-3 top-3 z-10 flex max-w-[16rem] rotate-[0.6deg] items-start gap-2 rounded-tile border border-[var(--color-note-edge)] bg-[var(--color-note)] px-3 py-2.5 shadow-card"
  >
    <p class="font-body text-[0.8125rem] leading-snug text-[var(--color-ink)]">
      Drag to rotate. Scroll to zoom. Right-drag or use two fingers to pan. Click a marker to read
      what it is.
    </p>

    <button
      type="button"
      class="-mr-1 -mt-0.5 shrink-0 rounded-full p-1 text-[var(--color-ink-soft)] transition-colors hover:bg-[var(--color-note-edge)] hover:text-[var(--color-ink)]"
      aria-label="Dismiss the tip"
      @click="dismiss"
    >
      <Icon name="close" class="size-3.5" />
    </button>
  </div>
</template>
