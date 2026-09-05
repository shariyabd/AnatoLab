<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import AdminPage from '@/Components/Admin/AdminPage.vue'
import type { AdminLesson, OrganOption } from '@/types/admin'

/**
 * Create and edit a lesson.
 *
 * `content.steps` is edited as a repeating list rather than raw JSON: the
 * server validates each step's shape, and a textarea of JSON turns a typo into
 * a validation error nobody can locate.
 */
const props = defineProps<{
  lesson: AdminLesson | null
  options: {
    organs: OrganOption[]
    difficulties: string[]
    stepTypes: string[]
    statuses: string[]
  }
}>()

interface StepRow {
  type: string
  title: string
  body: string
  structureSlug: string
}

function seedSteps(): StepRow[] {
  const steps = props.lesson?.content.steps ?? []

  return steps.map((step) => ({
    type: String(step.type ?? 'explanation'),
    title: String(step.title ?? ''),
    body: String(step.body ?? ''),
    structureSlug: String(step.structureSlug ?? ''),
  }))
}

const form = useForm({
  organ_id: '',
  slug: props.lesson?.slug ?? '',
  title: props.lesson?.title ?? '',
  description: props.lesson?.description ?? '',
  objective: props.lesson?.objective ?? '',
  difficulty: props.lesson?.difficulty ?? 'beginner',
  estimated_minutes: props.lesson?.estimatedMinutes ?? 10,
  content: { steps: seedSteps() },
})

function addStep(): void {
  form.content.steps.push({ type: 'explanation', title: '', body: '', structureSlug: '' })
}

function removeStep(index: number): void {
  form.content.steps.splice(index, 1)
}

function submit(): void {
  if (props.lesson === null) {
    form.post('/admin/lessons')
    return
  }

  form.put(`/admin/lessons/${props.lesson.slug}`, { preserveScroll: true })
}
</script>

<template>
  <AdminPage :title="lesson === null ? 'New lesson' : `Edit ${lesson.title}`">
    <form
      class="max-w-3xl space-y-4 rounded-lg border border-[var(--color-border-subtle)] bg-[var(--color-surface-raised)] p-4"
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

      <div class="grid gap-4 sm:grid-cols-2">
        <label class="block text-sm">
          <span class="text-[var(--color-ink-muted)]">Title</span>
          <input
            v-model="form.title"
            type="text"
            required
            class="mt-1 w-full rounded-md border border-[var(--color-border-strong)] bg-transparent px-2 py-1.5"
          />
        </label>

        <label class="block text-sm">
          <span class="text-[var(--color-ink-muted)]">Slug</span>
          <input
            v-model="form.slug"
            type="text"
            required
            class="mt-1 w-full rounded-md border border-[var(--color-border-strong)] bg-transparent px-2 py-1.5 font-mono text-xs"
          />
        </label>
      </div>
      <p v-if="form.errors.slug" class="text-xs text-rose-500">{{ form.errors.slug }}</p>

      <label class="block text-sm">
        <span class="text-[var(--color-ink-muted)]">Objective</span>
        <textarea
          v-model="form.objective"
          rows="2"
          required
          class="mt-1 w-full rounded-md border border-[var(--color-border-strong)] bg-transparent px-2 py-1.5"
        />
      </label>
      <p v-if="form.errors.objective" class="text-xs text-rose-500">{{ form.errors.objective }}</p>

      <label class="block text-sm">
        <span class="text-[var(--color-ink-muted)]">Description</span>
        <textarea
          v-model="form.description"
          rows="2"
          class="mt-1 w-full rounded-md border border-[var(--color-border-strong)] bg-transparent px-2 py-1.5"
        />
      </label>

      <div class="grid gap-4 sm:grid-cols-2">
        <label class="block text-sm">
          <span class="text-[var(--color-ink-muted)]">Difficulty</span>
          <select
            v-model="form.difficulty"
            class="mt-1 w-full rounded-md border border-[var(--color-border-strong)] bg-transparent px-2 py-1.5"
          >
            <option v-for="value in options.difficulties" :key="value" :value="value">
              {{ value }}
            </option>
          </select>
        </label>

        <label class="block text-sm">
          <span class="text-[var(--color-ink-muted)]">Estimated minutes</span>
          <input
            v-model.number="form.estimated_minutes"
            type="number"
            min="1"
            max="240"
            class="mt-1 w-full rounded-md border border-[var(--color-border-strong)] bg-transparent px-2 py-1.5"
          />
        </label>
      </div>

      <section>
        <div class="flex items-center justify-between">
          <h2 class="text-sm font-medium">Steps</h2>
          <button type="button" class="text-xs underline" @click="addStep">Add step</button>
        </div>

        <ul class="mt-2 space-y-3">
          <li
            v-for="(step, index) in form.content.steps"
            :key="index"
            class="rounded-md border border-[var(--color-border-subtle)] p-3"
          >
            <div class="flex gap-2">
              <select
                v-model="step.type"
                class="rounded-md border border-[var(--color-border-strong)] bg-transparent px-2 py-1 text-sm"
              >
                <option v-for="type in options.stepTypes" :key="type" :value="type">
                  {{ type }}
                </option>
              </select>
              <input
                v-model="step.title"
                type="text"
                placeholder="Step title"
                class="flex-1 rounded-md border border-[var(--color-border-strong)] bg-transparent px-2 py-1 text-sm"
              />
              <button type="button" class="text-xs underline" @click="removeStep(index)">
                Remove
              </button>
            </div>

            <textarea
              v-model="step.body"
              rows="2"
              placeholder="Body"
              class="mt-2 w-full rounded-md border border-[var(--color-border-strong)] bg-transparent px-2 py-1 text-sm"
            />

            <input
              v-model="step.structureSlug"
              type="text"
              placeholder="Structure slug (for exploration steps)"
              class="mt-2 w-full rounded-md border border-[var(--color-border-strong)] bg-transparent px-2 py-1 font-mono text-xs"
            />
          </li>
        </ul>

        <p v-if="form.errors['content.steps']" class="mt-1 text-xs text-rose-500">
          {{ form.errors['content.steps'] }}
        </p>
      </section>

      <button
        type="submit"
        :disabled="form.processing"
        class="rounded-md bg-[var(--color-accent)] px-3 py-2 text-sm font-medium text-white disabled:opacity-50"
      >
        {{ lesson === null ? 'Create as draft' : 'Save' }}
      </button>
    </form>
  </AdminPage>
</template>
