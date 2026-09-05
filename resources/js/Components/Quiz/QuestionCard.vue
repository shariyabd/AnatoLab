<script setup lang="ts">
import { computed } from 'vue'
import type { QuizOption, QuizQuestion, StructureDto, StructureId } from '@/types/quiz'

/**
 * The question, and every way of answering it.
 *
 * **It holds no answer.** `QuizQuestion` has no correctness field to hold —
 * the payload never carries one (invariant 4) — so this component cannot mark
 * anything itself even by accident. Selection is disabled once an answer is
 * away, and the verdict is rendered by `FeedbackCard`.
 *
 * The spatial branch matters for more than layout. Clicking a marker is a 3D
 * interaction, and every 3D interaction needs a keyboard and screen-reader
 * equivalent in the same commit that adds it (docs/engineering.md §4). That
 * equivalent is the visually-hidden structure list below: real buttons, in the
 * tab order, carrying the same `structureId` the canvas would emit. It is
 * hidden rather than absent because showing every structure name on screen
 * would answer the question for everyone.
 */
const props = defineProps<{
  question: QuizQuestion
  structures: readonly StructureDto[]
  disabled: boolean
  hintShown: boolean
  selectedOptionId: string | null
}>()

const emit = defineEmits<{
  (event: 'pick-option', optionId: string): void
  (event: 'pick-structure', structureId: StructureId): void
  (event: 'answer-text', text: string): void
  (event: 'show-hint'): void
}>()

const isSpatial = computed(() => props.question.type === 'spatial')
const isMcq = computed(() => props.question.type === 'mcq')
const isShortAnswer = computed(() => props.question.type === 'short_answer')

function optionClasses(option: QuizOption): string {
  return props.selectedOptionId === option.id
    ? 'border-[var(--color-accent)] bg-[var(--color-accent)]/10'
    : 'border-[var(--color-border-subtle)] hover:border-[var(--color-accent)]'
}

function submitText(event: Event): void {
  const form = event.target
  if (!(form instanceof HTMLFormElement)) return

  const field = form.elements.namedItem('answerText')
  if (!(field instanceof HTMLTextAreaElement)) return

  const text = field.value.trim()
  if (text === '') return

  emit('answer-text', text)
}
</script>

<template>
  <section class="space-y-4" aria-labelledby="quiz-question">
    <div>
      <p class="text-[0.6875rem] uppercase tracking-wide text-[var(--color-ink-muted)]">
        <template v-if="isSpatial">Find it on the model</template>
        <template v-else-if="isMcq">Multiple choice</template>
        <template v-else>Short answer</template>
        · Level {{ question.difficulty }}
      </p>

      <h2 id="quiz-question" class="mt-1 text-base font-semibold leading-snug">
        {{ question.question }}
      </h2>
    </div>

    <p v-if="isSpatial" class="text-xs text-[var(--color-ink-muted)]">
      Click the structure on the model.
    </p>

    <!-- The keyboard and screen-reader path for a spatial question. -->
    <div v-if="isSpatial">
      <ul class="sr-only">
        <li v-for="structure in structures" :key="structure.id">
          <button type="button" :disabled="disabled" @click="emit('pick-structure', structure.id)">
            {{ structure.name }}
          </button>
        </li>
      </ul>
    </div>

    <ul v-if="isMcq" class="space-y-2">
      <li v-for="option in question.options" :key="option.id">
        <button
          type="button"
          class="w-full rounded-md border px-3 py-2 text-left text-sm transition-colors disabled:opacity-60"
          :class="optionClasses(option)"
          :disabled="disabled"
          @click="emit('pick-option', option.id)"
        >
          {{ option.label }}
        </button>
      </li>
    </ul>

    <form v-if="isShortAnswer" class="space-y-2" @submit.prevent="submitText">
      <label class="block text-xs text-[var(--color-ink-muted)]" for="answerText">
        Your answer
      </label>
      <textarea
        id="answerText"
        name="answerText"
        rows="3"
        maxlength="1000"
        :disabled="disabled"
        class="w-full rounded-md border border-[var(--color-border-strong)] bg-[var(--color-surface)] px-3 py-2 text-sm"
      />
      <button
        type="submit"
        class="rounded-md bg-[var(--color-accent)] px-3 py-2 text-sm font-medium text-[var(--color-accent-ink)] disabled:opacity-60"
        :disabled="disabled"
      >
        Submit answer
      </button>
    </form>

    <div v-if="question.hint !== null">
      <button
        v-if="!hintShown"
        type="button"
        class="text-xs font-medium text-[var(--color-accent)] underline underline-offset-2"
        :disabled="disabled"
        @click="emit('show-hint')"
      >
        Show a hint
      </button>

      <!--
        Said out loud rather than shown silently: using a hint lowers the
        mastery weight of the attempt (docs/architecture.md §10), and a student
        should know that before tapping it, not after.
      -->
      <p v-else class="text-xs text-[var(--color-ink-muted)]">
        {{ question.hint }}
        <span class="block opacity-70">Hinted answers count for a little less.</span>
      </p>
    </div>
  </section>
</template>
