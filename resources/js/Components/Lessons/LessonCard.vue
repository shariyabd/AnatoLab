<script setup lang="ts">
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import LessonProgressBar from '@/Components/Lessons/LessonProgressBar.vue'
import type { LessonCard } from '@/types/lessons'

/**
 * One lesson in the library.
 *
 * The status line is computed here rather than assembled in the template:
 * "Not started" / "23% through" / "Completed" is three branches, and a
 * template is for display (docs/engineering.md §4).
 */
const props = defineProps<{ lesson: LessonCard }>()

const isComplete = computed(() => props.lesson.progress?.status === 'completed')

const status = computed(() => {
  const progress = props.lesson.progress

  if (progress === null) return 'Not started'
  if (progress.status === 'completed') return 'Completed'

  return `${String(progress.progressPercent)}% through`
})

const meta = computed(() =>
  [
    props.lesson.organ?.name,
    `${String(props.lesson.estimatedMinutes)} min`,
    `${String(props.lesson.stepCount)} steps`,
  ].filter((entry): entry is string => typeof entry === 'string'),
)
</script>

<template>
  <Link
    :href="`/lessons/${lesson.slug}`"
    class="block h-full rounded-lg border border-[var(--color-border-subtle)] bg-[var(--color-surface-raised)] p-4 transition-colors hover:border-[var(--color-accent)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]"
  >
    <div class="flex items-start gap-3">
      <span
        aria-hidden="true"
        class="mt-1.5 size-2.5 shrink-0 rounded-full"
        :style="{ backgroundColor: lesson.organ?.accentColor ?? 'var(--color-accent)' }"
      />

      <div class="min-w-0 flex-1">
        <h3 class="text-sm font-semibold leading-snug">{{ lesson.title }}</h3>

        <p class="mt-1 text-xs leading-relaxed text-[var(--color-ink-muted)]">
          {{ lesson.description ?? lesson.objective }}
        </p>
      </div>

      <span
        class="shrink-0 rounded-full border border-[var(--color-border-subtle)] px-2 py-0.5 text-[0.625rem] uppercase tracking-wide text-[var(--color-ink-muted)]"
      >
        {{ lesson.difficulty }}
      </span>
    </div>

    <p class="mt-3 text-[0.6875rem] text-[var(--color-ink-muted)]">
      {{ meta.join(' · ') }}
    </p>

    <div class="mt-2 space-y-1.5">
      <LessonProgressBar
        :percent="lesson.progress?.progressPercent ?? 0"
        :complete="isComplete"
        :label="`Progress through ${lesson.title}`"
      />

      <p class="text-[0.6875rem] text-[var(--color-ink-muted)]">{{ status }}</p>
    </div>
  </Link>
</template>
