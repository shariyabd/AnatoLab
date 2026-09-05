<script setup lang="ts">
import { computed } from 'vue'

/**
 * Where the student is in the round, and how it is going.
 *
 * Displays only (invariant 8): every number is passed in already computed.
 */
const props = defineProps<{
  index: number
  total: number
  correctCount: number
  answeredCount: number
  accentColor: string
}>()

const position = computed(() => Math.min(props.index + 1, props.total))
const percent = computed(() => (props.total === 0 ? 0 : (props.answeredCount / props.total) * 100))
</script>

<template>
  <div>
    <div class="flex items-baseline justify-between text-xs text-[var(--color-ink-muted)]">
      <span>Question {{ position }} of {{ total }}</span>
      <span>{{ correctCount }} correct</span>
    </div>

    <!--
      aria-hidden: the bar restates the sentence above it, and a screen reader
      announcing both reads the same fact twice per question.
    -->
    <div
      aria-hidden="true"
      class="mt-2 h-1 overflow-hidden rounded-full bg-[var(--color-border-subtle)]"
    >
      <div
        class="h-full transition-[width] duration-300"
        :style="{ width: `${String(percent)}%`, backgroundColor: accentColor }"
      />
    </div>
  </div>
</template>
