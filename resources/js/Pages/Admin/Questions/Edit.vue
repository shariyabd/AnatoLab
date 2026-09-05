<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import AdminPage from '@/Components/Admin/AdminPage.vue'
import type { AdminQuestion, OrganOption, QuestionStatus, QuestionType } from '@/types/admin'

/**
 * Create and edit a question, answer key included.
 *
 * The key is editable here for the same reason the review queue displays it: a
 * reviewer who spots a wrong correct-option fixes it and approves, rather than
 * rejecting a question that was one checkbox away from correct.
 *
 * `question.options` is optional on the DTO — the Resource omits it when the
 * policy denies the ability — so the form seeds from an empty list rather than
 * assuming it is there.
 *
 * ---
 *
 * **Note for /boundary-audit.** This file contains the identifiers `is_correct`
 * and `correct_structure_id`, which the answer-key sweep greps for. They are
 * here legitimately and this is the only place in `resources/js/` that they
 * appear:
 *
 * - They are **request field names**, not response fields. The form posts what
 *   an administrator typed to StoreQuestionRequest / UpdateQuestionRequest,
 *   which validate under those names because they are the column names. A
 *   client *sending* an answer key an admin authored is not the direction
 *   invariant 4 is about.
 * - The values seeded into the form arrive through AdminQuestionResource, which
 *   asks `QuestionPolicy::viewAnswerKey()` and omits them otherwise. This page
 *   is admin-only twice over — `admin` middleware and that policy.
 * - No student-facing page contains any of these identifiers, and
 *   Tests\Feature\Admin\AnswerKeyExposureTest asserts the Resource itself
 *   withholds the key from a non-admin — a stronger check than the grep, since
 *   it tests the decision rather than the spelling.
 *
 * The chunk Vite emits for this page carries these *names*; it carries no
 * answers (docs/handovers/13-admin-content.md; docs/handovers/parallel-execution-plan.md C7).
 */
const props = defineProps<{
  question: AdminQuestion | null
  options: { organs: OrganOption[]; types: string[]; statuses: QuestionStatus[] }
}>()

interface OptionRow {
  label: string
  value: string
  is_correct: boolean
}

const form = useForm<{
  organ_id: string
  type: QuestionType
  question: string
  difficulty: number
  explanation: string
  correct_structure_id: string
  status: QuestionStatus
  options: OptionRow[]
}>({
  organ_id: '',
  type: props.question?.type ?? 'mcq',
  question: props.question?.question ?? '',
  difficulty: props.question?.difficulty ?? 1,
  explanation: props.question?.explanation ?? '',
  correct_structure_id: props.question?.correctStructureId ?? '',
  status: props.question?.status ?? 'draft',
  options: (props.question?.options ?? []).map((option) => ({
    label: option.label,
    value: option.value,
    is_correct: option.isCorrect,
  })),
})

const nextLabel = ref(['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'])

function addOption(): void {
  if (form.options.length >= 8) return
  form.options.push({
    label: nextLabel.value[form.options.length] ?? String(form.options.length + 1),
    value: '',
    is_correct: false,
  })
}

function removeOption(index: number): void {
  form.options.splice(index, 1)
}

/** Exactly one correct option, enforced in the UI as well as on the server. */
function markCorrect(index: number): void {
  form.options.forEach((option, i) => {
    option.is_correct = i === index
  })
}

function submit(): void {
  if (props.question === null) {
    form.post('/admin/questions')
    return
  }

  form.put(`/admin/questions/${props.question.id}`, { preserveScroll: true })
}
</script>

<template>
  <AdminPage :title="question === null ? 'New question' : 'Edit question'">
    <form
      class="max-w-2xl space-y-4 rounded-lg border border-[var(--color-border-subtle)] bg-[var(--color-surface-raised)] p-4"
      @submit.prevent="submit"
    >
      <label class="block text-sm">
        <span class="text-[var(--color-ink-muted)]">Organ</span>
        <select
          v-model="form.organ_id"
          required
          class="mt-1 w-full rounded-md border border-[var(--color-border-strong)] bg-transparent px-2 py-1.5"
        >
          <option value="" disabled>Choose…</option>
          <option v-for="organ in options.organs" :key="organ.id" :value="organ.id">
            {{ organ.name }}
          </option>
        </select>
      </label>
      <p v-if="form.errors.organ_id" class="text-xs text-rose-500">{{ form.errors.organ_id }}</p>

      <label class="block text-sm">
        <span class="text-[var(--color-ink-muted)]">Type</span>
        <select
          v-model="form.type"
          class="mt-1 w-full rounded-md border border-[var(--color-border-strong)] bg-transparent px-2 py-1.5"
        >
          <option v-for="type in options.types" :key="type" :value="type">{{ type }}</option>
        </select>
      </label>

      <label class="block text-sm">
        <span class="text-[var(--color-ink-muted)]">Question</span>
        <textarea
          v-model="form.question"
          rows="3"
          required
          class="mt-1 w-full rounded-md border border-[var(--color-border-strong)] bg-transparent px-2 py-1.5"
        />
      </label>
      <p v-if="form.errors.question" class="text-xs text-rose-500">{{ form.errors.question }}</p>

      <label class="block text-sm">
        <span class="text-[var(--color-ink-muted)]">Difficulty (1–5)</span>
        <input
          v-model.number="form.difficulty"
          type="number"
          min="1"
          max="5"
          class="mt-1 w-full rounded-md border border-[var(--color-border-strong)] bg-transparent px-2 py-1.5"
        />
      </label>

      <section v-if="form.type === 'mcq'">
        <div class="flex items-center justify-between">
          <h2 class="text-sm font-medium">Options</h2>
          <button type="button" class="text-xs underline" @click="addOption">Add option</button>
        </div>

        <p class="mt-1 text-xs text-[var(--color-ink-muted)]">
          Mark exactly one as correct. This is the answer key and never reaches a student.
        </p>

        <ul class="mt-2 space-y-2">
          <li v-for="(option, index) in form.options" :key="index" class="flex items-center gap-2">
            <input
              v-model="option.label"
              type="text"
              class="w-12 rounded-md border border-[var(--color-border-strong)] bg-transparent px-2 py-1 text-sm"
            />
            <input
              v-model="option.value"
              type="text"
              class="flex-1 rounded-md border border-[var(--color-border-strong)] bg-transparent px-2 py-1 text-sm"
            />
            <label class="flex items-center gap-1 text-xs">
              <input
                type="radio"
                :checked="option.is_correct"
                :name="'correct-option'"
                @change="markCorrect(index)"
              />
              correct
            </label>
            <button type="button" class="text-xs underline" @click="removeOption(index)">
              Remove
            </button>
          </li>
        </ul>

        <p v-if="form.errors.options" class="mt-1 text-xs text-rose-500">
          {{ form.errors.options }}
        </p>
      </section>

      <label v-if="form.type === 'spatial'" class="block text-sm">
        <span class="text-[var(--color-ink-muted)]">Correct structure id</span>
        <input
          v-model="form.correct_structure_id"
          type="text"
          class="mt-1 w-full rounded-md border border-[var(--color-border-strong)] bg-transparent px-2 py-1.5 font-mono text-xs"
        />
      </label>
      <p v-if="form.errors.correct_structure_id" class="text-xs text-rose-500">
        {{ form.errors.correct_structure_id }}
      </p>

      <label class="block text-sm">
        <span class="text-[var(--color-ink-muted)]">Explanation</span>
        <textarea
          v-model="form.explanation"
          rows="2"
          class="mt-1 w-full rounded-md border border-[var(--color-border-strong)] bg-transparent px-2 py-1.5"
        />
        <span class="mt-1 block text-xs text-[var(--color-ink-muted)]">
          Shown after an answer is recorded, never before.
        </span>
      </label>

      <button
        type="submit"
        :disabled="form.processing"
        class="rounded-md bg-[var(--color-accent)] px-3 py-2 text-sm font-medium text-white disabled:opacity-50"
      >
        {{ question === null ? 'Create' : 'Save' }}
      </button>
    </form>
  </AdminPage>
</template>
