<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import AdminPage from '@/Components/Admin/AdminPage.vue'
import type { AdminMission, OrganOption } from '@/types/admin'

/**
 * Create and edit a mission, target sequence included.
 *
 * This is the only screen in the application that renders a mission's
 * `configuration`. It arrives only when `MissionPolicy::viewTargetSequence`
 * allowed it, so the field is optional on the DTO and the form seeds from an
 * empty list rather than assuming the admin area implies it (invariant 4).
 *
 * Steps name their target by **slug**, not id: that is what the server reads,
 * and slugs survive a reseed while auto-increment ids do not.
 */
const props = defineProps<{
  mission: AdminMission | null
  options: { organs: OrganOption[]; types: string[]; statuses: string[] }
}>()

interface StepRow {
  structure_id: string
  prompt: string
  hint: string
  explanation: string
}

function seedSteps(): StepRow[] {
  const steps = props.mission?.configuration?.steps ?? []

  return steps.map((step) => ({
    structure_id: step.structure_id,
    prompt: step.prompt,
    hint: step.hint ?? '',
    explanation: step.explanation ?? '',
  }))
}

const form = useForm({
  organ_id: '',
  slug: props.mission?.slug ?? '',
  title: props.mission?.title ?? '',
  description: props.mission?.description ?? '',
  type: props.mission?.type ?? props.options.types[0] ?? 'trace_pathway',
  difficulty: props.mission?.difficulty ?? 1,
  configuration: { steps: seedSteps() },
})

function addStep(): void {
  form.configuration.steps.push({ structure_id: '', prompt: '', hint: '', explanation: '' })
}

function removeStep(index: number): void {
  form.configuration.steps.splice(index, 1)
}

function submit(): void {
  if (props.mission === null) {
    form.post('/admin/missions')
    return
  }

  form.put(`/admin/missions/${props.mission.slug}`, { preserveScroll: true })
}
</script>

<template>
  <AdminPage :title="mission === null ? 'New mission' : `Edit ${mission.title}`">
    <div
      v-if="mission !== null && mission.configuration === undefined"
      class="mb-4 rounded-lg border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm"
    >
      You are not authorized to see this mission's target sequence, so the steps cannot be edited
      here.
    </div>

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

      <div class="grid gap-4 sm:grid-cols-2">
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
          <span class="text-[var(--color-ink-muted)]">Difficulty (1–5)</span>
          <input
            v-model.number="form.difficulty"
            type="number"
            min="1"
            max="5"
            class="mt-1 w-full rounded-md border border-[var(--color-border-strong)] bg-transparent px-2 py-1.5"
          />
        </label>
      </div>

      <label class="block text-sm">
        <span class="text-[var(--color-ink-muted)]">Description</span>
        <textarea
          v-model="form.description"
          rows="2"
          class="mt-1 w-full rounded-md border border-[var(--color-border-strong)] bg-transparent px-2 py-1.5"
        />
      </label>

      <section>
        <div class="flex items-center justify-between">
          <h2 class="text-sm font-medium">Target sequence</h2>
          <button type="button" class="text-xs underline" @click="addStep">Add step</button>
        </div>

        <p class="mt-1 text-xs text-[var(--color-ink-muted)]">
          The order is the answer. It never leaves the server.
        </p>

        <ul class="mt-2 space-y-3">
          <li
            v-for="(step, index) in form.configuration.steps"
            :key="index"
            class="rounded-md border border-[var(--color-border-subtle)] p-3"
          >
            <div class="flex items-center gap-2">
              <span class="text-xs text-[var(--color-ink-muted)]">{{ index + 1 }}.</span>
              <input
                v-model="step.structure_id"
                type="text"
                placeholder="structure slug"
                class="w-48 rounded-md border border-[var(--color-border-strong)] bg-transparent px-2 py-1 font-mono text-xs"
              />
              <input
                v-model="step.prompt"
                type="text"
                placeholder="Prompt shown to the student"
                class="flex-1 rounded-md border border-[var(--color-border-strong)] bg-transparent px-2 py-1 text-sm"
              />
              <button type="button" class="text-xs underline" @click="removeStep(index)">
                Remove
              </button>
            </div>

            <input
              v-model="step.hint"
              type="text"
              placeholder="Hint (optional)"
              class="mt-2 w-full rounded-md border border-[var(--color-border-strong)] bg-transparent px-2 py-1 text-sm"
            />
            <input
              v-model="step.explanation"
              type="text"
              placeholder="Explanation, shown after the step is graded"
              class="mt-2 w-full rounded-md border border-[var(--color-border-strong)] bg-transparent px-2 py-1 text-sm"
            />
          </li>
        </ul>

        <p v-if="form.errors['configuration.steps']" class="mt-1 text-xs text-rose-500">
          {{ form.errors['configuration.steps'] }}
        </p>
      </section>

      <button
        type="submit"
        :disabled="form.processing"
        class="rounded-md bg-[var(--color-accent)] px-3 py-2 text-sm font-medium text-white disabled:opacity-50"
      >
        {{ mission === null ? 'Create as draft' : 'Save' }}
      </button>
    </form>
  </AdminPage>
</template>
