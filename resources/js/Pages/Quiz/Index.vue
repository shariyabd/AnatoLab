<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import type { QuizCard } from '@/types/quiz'

/**
 * The quiz picker.
 *
 * Every card here is an organ that can currently ask at least one answerable
 * question — `AssessmentService::listQuizzes()` builds the list from the same
 * query that serves the round, so a card can never open onto a 404.
 */
defineProps<{
  quizzes: QuizCard[]
}>()
</script>

<template>
  <Head title="Quizzes" />

  <div class="space-y-6">
    <div>
      <h1 class="text-xl font-semibold tracking-tight">Quizzes</h1>
      <p class="mt-1 text-sm text-[var(--color-ink-muted)]">
        Answer by clicking the structure on the model, or by choosing from a list. Every answer is
        checked and recorded.
      </p>
    </div>

    <ul v-if="quizzes.length > 0" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
      <li v-for="quiz in quizzes" :key="quiz.slug">
        <Link
          :href="`/quizzes/${quiz.slug}`"
          class="block h-full rounded-lg border border-[var(--color-border-subtle)] bg-[var(--color-surface-raised)] p-4 transition-colors hover:border-[var(--color-accent)]"
        >
          <span
            class="block h-1 w-10 rounded-full"
            :style="{ backgroundColor: quiz.accentColor }"
            aria-hidden="true"
          />

          <span class="mt-3 block text-sm font-semibold">{{ quiz.name }}</span>

          <span
            v-if="quiz.scientificName"
            class="block text-xs italic text-[var(--color-ink-muted)]"
          >
            {{ quiz.scientificName }}
          </span>

          <span class="mt-2 block text-xs text-[var(--color-ink-muted)]">
            {{ quiz.questionCount }}
            {{ quiz.questionCount === 1 ? 'question' : 'questions' }}
          </span>
        </Link>
      </li>
    </ul>

    <p v-else class="text-sm text-[var(--color-ink-muted)]">No quizzes have been published yet.</p>
  </div>
</template>
