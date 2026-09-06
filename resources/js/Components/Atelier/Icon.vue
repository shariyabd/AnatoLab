<script setup lang="ts">
import { computed } from 'vue'

/**
 * The line-icon set — handover 15 Phase 1.
 *
 * One stroked 24×24 grid, `currentColor`, no dependency. A packaged icon
 * library would be a new dependency this handover is not allowed to add, and
 * every icon the interface needs is a handful of path data.
 *
 * The seven navigation names come from `config/navigation.php`, which has
 * carried an `icon` per entry since handover 01 and had nothing rendering it.
 * The rest are the viewer rail and the information panel.
 *
 * Decorative by default. An icon that sits beside its own label adds nothing
 * for a screen reader and is hidden from it; pass `title` only where the icon
 * is the only thing naming its control.
 */
const props = withDefaults(
  defineProps<{
    name: string
    /** Accessible name. Omitted, the icon is `aria-hidden`. */
    title?: string | null
    /** Stroke width in the 24-unit grid. */
    weight?: number
  }>(),
  { title: null, weight: 1.6 },
)

/*
 | Path data only — no <svg> wrapper per icon, so stroke, size and
 | accessibility are decided once below rather than per entry.
 */
const PATHS: Record<string, readonly string[]> = {
  // Navigation
  home: ['M3 10.5 12 3l9 7.5', 'M5.5 9.5V20h13V9.5', 'M9.5 20v-6h5v6'],
  cube: ['M12 2.5 20.5 7v10L12 21.5 3.5 17V7z', 'M3.5 7 12 11.5 20.5 7', 'M12 11.5v10'],
  book: [
    'M4 4.5h6a2.5 2.5 0 0 1 2.5 2.5v12A2 2 0 0 0 10.5 17H4z',
    'M20 4.5h-6A2.5 2.5 0 0 0 11.5 7v12A2 2 0 0 1 13.5 17H20z',
  ],
  target: [
    'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18z',
    'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8z',
    'M12 11.2a.8.8 0 1 0 0 1.6.8.8 0 0 0 0-1.6z',
  ],
  route: [
    'M6.5 4.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5z',
    'M17.5 14.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5z',
    'M6.5 9.5v3a4 4 0 0 0 4 4h3',
  ],
  activity: ['M3 12h4l2.5-7 5 14 2.5-7h4'],
  settings: [
    'M12 8.5a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7z',
    'M19.4 14.5a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-2.7 1.1v.3a2 2 0 0 1-4 0v-.2a1.6 1.6 0 0 0-2.8-1.1l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0-1.1-2.7h-.3a2 2 0 0 1 0-4h.2A1.6 1.6 0 0 0 4.5 6.7l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 2.7-1.1V2.5a2 2 0 0 1 4 0v.2a1.6 1.6 0 0 0 2.7 1.1l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0 1.1 2.7h.3a2 2 0 0 1 0 4h-.2a1.6 1.6 0 0 0-1.5 1.1z',
  ],

  // Viewer rail
  'zoom-in': [
    'M11 4.5a6.5 6.5 0 1 0 0 13 6.5 6.5 0 0 0 0-13z',
    'M15.8 15.8 20.5 20.5',
    'M8.5 11h5',
    'M11 8.5v5',
  ],
  'zoom-out': [
    'M11 4.5a6.5 6.5 0 1 0 0 13 6.5 6.5 0 0 0 0-13z',
    'M15.8 15.8 20.5 20.5',
    'M8.5 11h5',
  ],
  slice: ['M4 15.5 20 8.5', 'M6.5 6.5v7.2', 'M17.5 10.3v7.2', 'M6.5 13.7 17.5 17.5'],
  grid: ['M3.5 3.5h17v17h-17z', 'M3.5 12h17', 'M12 3.5v17', 'M3.5 7.75h17', 'M7.75 3.5v17'],
  focus: [
    'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8z',
    'M12 2.5v3',
    'M12 18.5v3',
    'M2.5 12h3',
    'M18.5 12h3',
  ],
  reset: ['M4 9.5a8.5 8.5 0 1 1-.6 4.5', 'M3.5 4v5.5H9'],
  rotate: ['M12 4.5a7.5 7.5 0 1 1-7.3 9.2', 'M4.2 9.5 6.6 5.3l4.2 2.4'],
  move: [
    'M12 3v18',
    'M3 12h18',
    'M12 3 9.5 5.8M12 3l2.5 2.8',
    'M12 21l-2.5-2.8M12 21l2.5-2.8',
    'M3 12l2.8-2.5M3 12l2.8 2.5',
    'M21 12l-2.8-2.5M21 12l-2.8 2.5',
  ],

  // Information panel
  ruler: ['M3 8.5h18v7H3z', 'M7 8.5v3', 'M11 8.5v4.5', 'M15 8.5v3', 'M19 8.5v4.5'],
  weight: ['M6 8.5h12l1.5 11H4.5z', 'M9.5 8.5a2.5 2.5 0 1 1 5 0'],
  pulse: ['M3 12.5h4l2-4.5 3 9 2.5-6 1.5 3h5'],
  tissue: ['M12 3.5c4 2.5 6.5 5.3 6.5 8.6a6.5 6.5 0 0 1-13 0c0-3.3 2.5-6.1 6.5-8.6z'],
  stethoscope: [
    'M6 3.5v5a4 4 0 0 0 8 0v-5',
    'M10 16.5v-4',
    'M10 16.5a4 4 0 0 0 8 0v-2',
    'M18 10a2 2 0 1 0 0 4 2 2 0 0 0 0-4z',
  ],
  spark: ['M12 3.5 13.8 9l5.7 1.8-5.7 1.8L12 18.5l-1.8-5.9L4.5 10.8 10.2 9z'],
  close: ['M6 6l12 12', 'M18 6 6 18'],
  arrow: ['M4.5 12h15', 'M14 6.5 19.5 12 14 17.5'],
  search: ['M11 4.5a6.5 6.5 0 1 0 0 13 6.5 6.5 0 0 0 0-13z', 'M15.8 15.8 20.5 20.5'],
}

const paths = computed(() => PATHS[props.name] ?? [])
</script>

<template>
  <svg
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    :stroke-width="weight"
    stroke-linecap="round"
    stroke-linejoin="round"
    :role="title === null ? undefined : 'img'"
    :aria-hidden="title === null ? 'true' : undefined"
    :aria-label="title ?? undefined"
    focusable="false"
  >
    <path v-for="(d, index) in paths" :key="index" :d="d" />
  </svg>
</template>
