<script setup lang="ts">
import { computed, ref, useTemplateRef, watch } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import ViewerStage from '@/Components/Anatomy/ViewerStage.vue'
import FeedbackCard from '@/Components/Quiz/FeedbackCard.vue'
import QuestionCard from '@/Components/Quiz/QuestionCard.vue'
import QuizProgress from '@/Components/Quiz/QuizProgress.vue'
import RoundSummary from '@/Components/Quiz/RoundSummary.vue'
import { usePrefersReducedMotion } from '@/composables/usePrefersReducedMotion'
import { useQuiz } from '@/composables/useQuiz'
import type { QuizDto, StructureDto, StructureId } from '@/types/quiz'

/**
 * One round of a quiz — PRD §12 and handover 07.
 *
 * The page follows the mounting pattern `ViewerStage` documents: one viewer,
 * constructed once, never remounted, driven through the exposed commands, with
 * lane chrome in the `overlay` slot. Two things are specific to this lane.
 *
 * **The viewer holds no answer state.** It is told to flash a marker only
 * after the server has graded the attempt, never before
 * (docs/handovers/07-assessment-engine.md, constraints). `mode="quiz"` makes a
 * click report `structure:picked` without changing selection, so a wrong
 * answer leaves no sticky highlight to copy from, and the callout is off
 * because a callout naming the structure under the cursor would answer every
 * spatial question.
 *
 * **A miss reveals the answer in green.** Red on what was clicked, green on
 * what was right — the audited behaviour, and the reason it exists: a student
 * told only that they were wrong has learned nothing
 * (docs/project-context.md §2.4). Both flashes come out of one `reveal` value
 * the server produced.
 *
 * The page is usable with no WebGL at all. The question, the hint, the
 * explanation and the keyboard structure list are DOM, and a spatial question
 * is answerable from that list alone (docs/architecture.md §5.4 rule 5).
 */
const props = defineProps<{
  quiz: QuizDto
}>()

const stage = useTemplateRef<InstanceType<typeof ViewerStage>>('stage')
const prefersReducedMotion = usePrefersReducedMotion()

const round = useQuiz(props.quiz)

const organ = computed(() => props.quiz.organ)
const structures = computed<readonly StructureDto[]>(() => props.quiz.organ.structures)

/** Locally remembered so the picked option reads as selected while grading. */
const selectedOptionId = ref<string | null>(null)

const answering = computed(() => round.phase.value === 'answering')

const correctStructure = computed<StructureDto | null>(() => {
  const id = round.verdict.value?.correctStructureId
  if (id === undefined || id === null) return null
  return structures.value.find((structure) => structure.id === id) ?? null
})

/**
 * The one place the viewer is told anything about correctness, and it happens
 * strictly downstream of a server verdict.
 */
watch(round.reveal, (reveal) => {
  if (reveal === null) return

  const correct = round.verdict.value?.isCorrect ?? false

  if (reveal.picked !== null) {
    stage.value?.flashStructure(reveal.picked, correct)
  }

  // On a miss, also ring the right one green — including when the student
  // answered from the keyboard list and never picked a marker at all.
  if (!correct && reveal.correct !== null && reveal.correct !== reveal.picked) {
    stage.value?.flashStructure(reveal.correct, true)
  }
})

watch(round.current, () => {
  selectedOptionId.value = null
})

function onPicked(structure: StructureDto): void {
  if (!answering.value) return
  void round.answer({ structureId: structure.id })
}

function onPickStructure(structureId: StructureId): void {
  if (!answering.value) return
  void round.answer({ structureId })
}

function onPickOption(optionId: string): void {
  if (!answering.value) return
  selectedOptionId.value = optionId
  void round.answer({ optionId })
}

function onAnswerText(text: string): void {
  if (!answering.value) return
  void round.answer({ text })
}
</script>

<template>
  <Head :title="`Quiz · ${quiz.organ.name}`" />

  <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
    <div class="space-y-3">
      <h1 class="text-xl font-semibold tracking-tight">{{ quiz.title }}</h1>

      <ViewerStage
        ref="stage"
        :organ="organ"
        mode="quiz"
        :show-callout="false"
        :reduced-motion="prefersReducedMotion"
        class="aspect-[4/3] w-full"
        @picked="onPicked"
      >
        <template #overlay>
          <FeedbackCard
            v-if="round.verdict.value"
            :verdict="round.verdict.value"
            :correct-structure="correctStructure"
          />
        </template>
      </ViewerStage>
    </div>

    <div class="space-y-5">
      <QuizProgress
        :index="round.index.value"
        :total="round.total.value"
        :correct-count="round.correctCount.value"
        :answered-count="round.answeredCount.value"
        :accent-color="quiz.organ.accentColor"
      />

      <QuestionCard
        v-if="round.current.value"
        :question="round.current.value"
        :structures="structures"
        :disabled="!answering"
        :hint-shown="round.hintShown.value"
        :selected-option-id="selectedOptionId"
        @pick-option="onPickOption"
        @pick-structure="onPickStructure"
        @answer-text="onAnswerText"
        @show-hint="round.showHint"
      />

      <RoundSummary v-else :correct-count="round.correctCount.value" :total="round.total.value">
        <div class="flex gap-2">
          <button
            type="button"
            class="rounded-md bg-[var(--color-accent)] px-3 py-2 text-sm font-medium text-[var(--color-accent-ink)]"
            @click="round.restart"
          >
            Go again
          </button>

          <Link
            :href="`/explore/${quiz.organ.slug}`"
            class="rounded-md border border-[var(--color-border-subtle)] px-3 py-2 text-sm font-medium"
          >
            Explore the {{ quiz.organ.name.toLowerCase() }}
          </Link>
        </div>
      </RoundSummary>

      <p
        v-if="round.error.value"
        role="alert"
        class="rounded-md border border-[var(--color-danger)] px-3 py-2 text-xs text-[var(--color-danger)]"
      >
        {{ round.error.value }}
      </p>
    </div>
  </div>
</template>
