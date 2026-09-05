<script setup lang="ts">
import { computed, onMounted, ref, useTemplateRef } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import ViewerStage from '@/Components/Anatomy/ViewerStage.vue'
import LessonProgressBar from '@/Components/Lessons/LessonProgressBar.vue'
import StepRenderer from '@/Components/Lessons/StepRenderer.vue'
import { useLessonProgress } from '@/composables/useLessonProgress'
import { usePrefersReducedMotion } from '@/composables/usePrefersReducedMotion'
import type { LessonDto, StructureId } from '@/types/lessons'

/**
 * One lesson, one step at a time — PRD §8, handover 06.
 *
 * **The viewer is mounted once, here, and never torn down.** It is not inside
 * the exploration step and it is not behind a `v-if` on the step type:
 * remounting it would drop the WebGL context and re-decode the model on every
 * "next" (docs/architecture.md §5.4 rules 6 and 7). This is handover 05's
 * documented mounting pattern used verbatim — pass an `organ`, drive it
 * through the exposed commands, keep the container free of Vue children
 * (resources/js/Components/Anatomy/ViewerStage.vue).
 *
 * **The steps are not the viewer's business either.** A step asks for a
 * structure by id and the page relays it. That keeps every step usable with no
 * WebGL at all: the lists are real buttons, the prose is on the page, and the
 * lesson reads end to end whether or not the canvas ever appears (rule 5).
 *
 * **Progress is recorded server-side from the step index**, never from a
 * percentage the client computed (see `useLessonProgress`).
 */
const props = defineProps<{ lesson: LessonDto }>()

const stage = useTemplateRef<InstanceType<typeof ViewerStage>>('stage')

const prefersReducedMotion = usePrefersReducedMotion()

const {
  progress,
  failed: saveFailed,
  reachStep,
  complete,
  prime,
} = useLessonProgress(props.lesson.slug)

const steps = computed(() => props.lesson.steps)

const structures = computed(() => props.lesson.organ.structures)

/**
 * Where to open.
 *
 * A part-finished lesson resumes at the furthest step the student reached — it
 * is the whole point of persisting `progress_percent`. A finished one opens at
 * the beginning instead: re-reading a completed lesson from its last step is
 * the one place "resume" is the wrong answer.
 */
function initialIndex(): number {
  const existing = props.lesson.progress

  if (existing === null || existing.status === 'completed' || steps.value.length === 0) return 0

  const reached = Math.round((existing.progressPercent / 100) * steps.value.length) - 1

  return Math.min(Math.max(reached, 0), steps.value.length - 1)
}

const currentIndex = ref(initialIndex())

const currentStep = computed(() => steps.value[currentIndex.value] ?? null)

const isFirst = computed(() => currentIndex.value === 0)
const isLast = computed(() => currentIndex.value >= steps.value.length - 1)

const isComplete = computed(() => progress.value?.status === 'completed')

const percent = computed(() => {
  if (steps.value.length === 0) return 0

  return ((currentIndex.value + 1) / steps.value.length) * 100
})

function goToStep(index: number): void {
  if (index < 0 || index >= steps.value.length) return

  currentIndex.value = index
  // Clearing the highlight is not optional: the marker belongs to the step
  // that asked for it, and leaving it lit makes the next step look wrong.
  stage.value?.highlightStructure(null)
  reachStep(index)
}

function focusStructure(id: StructureId): void {
  stage.value?.selectStructure(id)
  stage.value?.focusStructure(id)
}

function highlightStructure(id: StructureId | null): void {
  stage.value?.highlightStructure(id)
}

async function finish(): Promise<void> {
  await complete()
}

onMounted(() => {
  prime(props.lesson.progress, steps.value.length)
  // Records the resumed position, so opening a lesson and reading nothing
  // still leaves an honest row rather than none at all.
  reachStep(currentIndex.value)
})
</script>

<template>
  <Head :title="`${lesson.title} · Lessons`" />

  <div class="space-y-4">
    <header class="space-y-2">
      <p class="text-xs text-[var(--color-ink-muted)]">
        <Link href="/lessons" class="underline underline-offset-2">Lessons</Link>
        <span aria-hidden="true"> · </span>
        <span>{{ lesson.organ.name }}</span>
        <span aria-hidden="true"> · </span>
        <span class="capitalize">{{ lesson.difficulty }}</span>
        <span aria-hidden="true"> · </span>
        <span>{{ lesson.estimatedMinutes }} min</span>
      </p>

      <h1 class="text-xl font-semibold tracking-tight">{{ lesson.title }}</h1>

      <p class="max-w-2xl text-sm leading-relaxed text-[var(--color-ink-muted)]">
        {{ lesson.objective }}
      </p>
    </header>

    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]">
      <!-- The lesson itself. -->
      <div class="space-y-4">
        <div class="space-y-2">
          <LessonProgressBar
            :percent="percent"
            :complete="isComplete"
            :label="`Progress through ${lesson.title}`"
          />

          <!--
            A real list of steps, always visible: it is the table of contents,
            the keyboard path, and the screen-reader outline in one.
          -->
          <ol v-if="steps.length > 0" class="flex flex-wrap gap-1.5" aria-label="Lesson steps">
            <li v-for="step in steps" :key="step.index">
              <button
                type="button"
                class="rounded-full border px-2.5 py-1 text-[0.6875rem] transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]"
                :class="
                  step.index === currentIndex
                    ? 'border-[var(--color-accent)] bg-[var(--color-accent)] text-[var(--color-surface)]'
                    : 'border-[var(--color-border-subtle)] text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-raised)]'
                "
                :aria-current="step.index === currentIndex ? 'step' : undefined"
                @click="goToStep(step.index)"
              >
                {{ step.index + 1 }}. {{ step.label }}
              </button>
            </li>
          </ol>
        </div>

        <section
          v-if="currentStep"
          :key="currentStep.index"
          class="rounded-lg border border-[var(--color-border-subtle)] bg-[var(--color-surface-raised)] p-4"
          aria-labelledby="lesson-step-heading"
        >
          <p class="text-[0.6875rem] uppercase tracking-wide text-[var(--color-ink-muted)]">
            Step {{ currentStep.index + 1 }} of {{ steps.length }} · {{ currentStep.label }}
          </p>

          <h2 id="lesson-step-heading" class="mb-3 mt-1 text-base font-semibold">
            {{ currentStep.title }}
          </h2>

          <StepRenderer
            :step="currentStep"
            :structures="structures"
            @focus="focusStructure"
            @highlight="highlightStructure"
          />
        </section>

        <p v-else class="text-sm text-[var(--color-ink-muted)]">This lesson has no steps yet.</p>

        <nav class="flex flex-wrap items-center gap-2" aria-label="Lesson navigation">
          <button
            type="button"
            class="rounded-md border border-[var(--color-border-subtle)] px-3 py-1.5 text-sm disabled:opacity-40"
            :disabled="isFirst"
            @click="goToStep(currentIndex - 1)"
          >
            Previous
          </button>

          <button
            v-if="!isLast"
            type="button"
            class="rounded-md bg-[var(--color-accent)] px-3 py-1.5 text-sm text-[var(--color-surface)]"
            @click="goToStep(currentIndex + 1)"
          >
            Next
          </button>

          <button
            v-else
            type="button"
            class="rounded-md bg-[var(--color-accent)] px-3 py-1.5 text-sm text-[var(--color-surface)] disabled:opacity-40"
            :disabled="isComplete"
            @click="finish"
          >
            {{ isComplete ? 'Completed' : 'Mark as complete' }}
          </button>

          <p v-if="isComplete" class="text-xs text-[var(--color-ink-muted)]">
            Finished — your progress is saved.
          </p>

          <p v-else-if="saveFailed" class="text-xs text-[var(--color-ink-muted)]">
            Your progress could not be saved just now. The lesson still works; try again when you
            are back online.
          </p>
        </nav>
      </div>

      <!--
        Mounted once for the whole lesson, alongside the steps rather than
        inside one. See the note at the top of this file.
      -->
      <div class="space-y-2">
        <ViewerStage
          ref="stage"
          :organ="lesson.organ"
          :reduced-motion="prefersReducedMotion"
          class="aspect-square w-full"
        />

        <p class="text-xs text-[var(--color-ink-muted)]">
          {{ lesson.organ.name }} — select a structure in the step to bring it into view.
        </p>
      </div>
    </div>
  </div>
</template>
