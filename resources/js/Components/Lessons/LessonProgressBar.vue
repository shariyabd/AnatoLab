<script setup lang="ts">
import { computed } from 'vue'

/**
 * How far through a lesson the student is.
 *
 * Takes the percentage rather than deriving it: the server owns that number
 * (LessonService::recordStepProgress), and computing a second one here would
 * give the page two answers that disagree after a failed save.
 */
const props = withDefaults(defineProps<{ percent: number; label?: string; complete?: boolean }>(), {
  label: 'Lesson progress',
  complete: false,
})

const clamped = computed(() => Math.min(Math.max(Math.round(props.percent), 0), 100))
</script>

<template>
  <div
    class="h-1.5 w-full overflow-hidden rounded-full bg-[var(--color-border-subtle)]"
    role="progressbar"
    :aria-label="label"
    :aria-valuenow="clamped"
    aria-valuemin="0"
    aria-valuemax="100"
  >
    <div
      class="h-full rounded-full transition-[width]"
      :class="complete ? 'bg-[var(--color-accent)]' : 'bg-[var(--color-ink-muted)]'"
      :style="{ width: `${String(clamped)}%` }"
    />
  </div>
</template>
