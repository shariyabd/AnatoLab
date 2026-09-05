<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import AdminPage from '@/Components/Admin/AdminPage.vue'
import type { AdminOrgan, OrganOption } from '@/types/admin'

/**
 * Create and edit an organ. One component for both, because the fields are
 * identical and two would drift.
 *
 * `status` is absent by design — publishing has its own route and its own
 * policy ability.
 */
const props = defineProps<{
  organ: AdminOrgan | null
  options: {
    bodySystems: OrganOption[]
    modelFormats: string[]
    statuses: string[]
  }
}>()

const form = useForm({
  body_system_id: props.organ?.bodySystemId ?? '',
  slug: props.organ?.slug ?? '',
  name: props.organ?.name ?? '',
  scientific_name: props.organ?.scientificName ?? '',
  description: props.organ?.description ?? '',
  model_path: props.organ?.modelPath ?? '',
  model_format: props.organ?.modelFormat ?? props.options.modelFormats[0] ?? 'glb',
  thumbnail_path: props.organ?.thumbnailPath ?? '',
  accent_color: props.organ?.accentColor ?? '#e11d48',
})

function submit(): void {
  if (props.organ === null) {
    form.post('/admin/organs')
    return
  }

  form.put(`/admin/organs/${props.organ.slug}`, { preserveScroll: true })
}
</script>

<template>
  <AdminPage :title="organ === null ? 'New organ' : `Edit ${organ.name}`">
    <form
      class="max-w-2xl space-y-4 rounded-lg border border-[var(--color-border-subtle)] bg-[var(--color-surface-raised)] p-4"
      @submit.prevent="submit"
    >
      <label class="block text-sm">
        <span class="text-[var(--color-ink-muted)]">Body system</span>
        <select
          v-model="form.body_system_id"
          required
          class="mt-1 w-full rounded-md border border-[var(--color-border-strong)] bg-transparent px-2 py-1.5"
        >
          <option value="" disabled>Choose…</option>
          <option v-for="system in options.bodySystems" :key="system.id" :value="system.id">
            {{ system.name }}
          </option>
        </select>
      </label>
      <p v-if="form.errors.body_system_id" class="text-xs text-rose-500">
        {{ form.errors.body_system_id }}
      </p>

      <label class="block text-sm">
        <span class="text-[var(--color-ink-muted)]">Name</span>
        <input
          v-model="form.name"
          type="text"
          required
          class="mt-1 w-full rounded-md border border-[var(--color-border-strong)] bg-transparent px-2 py-1.5"
        />
      </label>
      <p v-if="form.errors.name" class="text-xs text-rose-500">{{ form.errors.name }}</p>

      <label class="block text-sm">
        <span class="text-[var(--color-ink-muted)]">Slug</span>
        <input
          v-model="form.slug"
          type="text"
          required
          class="mt-1 w-full rounded-md border border-[var(--color-border-strong)] bg-transparent px-2 py-1.5 font-mono text-xs"
        />
      </label>
      <p v-if="form.errors.slug" class="text-xs text-rose-500">{{ form.errors.slug }}</p>

      <label class="block text-sm">
        <span class="text-[var(--color-ink-muted)]">Scientific name</span>
        <input
          v-model="form.scientific_name"
          type="text"
          class="mt-1 w-full rounded-md border border-[var(--color-border-strong)] bg-transparent px-2 py-1.5"
        />
      </label>

      <label class="block text-sm">
        <span class="text-[var(--color-ink-muted)]">Description</span>
        <textarea
          v-model="form.description"
          rows="3"
          class="mt-1 w-full rounded-md border border-[var(--color-border-strong)] bg-transparent px-2 py-1.5"
        />
      </label>

      <div class="grid gap-4 sm:grid-cols-2">
        <label class="block text-sm">
          <span class="text-[var(--color-ink-muted)]">Model path</span>
          <input
            v-model="form.model_path"
            type="text"
            required
            class="mt-1 w-full rounded-md border border-[var(--color-border-strong)] bg-transparent px-2 py-1.5 font-mono text-xs"
          />
        </label>

        <label class="block text-sm">
          <span class="text-[var(--color-ink-muted)]">Model format</span>
          <select
            v-model="form.model_format"
            class="mt-1 w-full rounded-md border border-[var(--color-border-strong)] bg-transparent px-2 py-1.5"
          >
            <option v-for="format in options.modelFormats" :key="format" :value="format">
              {{ format }}
            </option>
          </select>
        </label>
      </div>
      <p v-if="form.errors.model_path" class="text-xs text-rose-500">
        {{ form.errors.model_path }}
      </p>

      <div class="grid gap-4 sm:grid-cols-2">
        <label class="block text-sm">
          <span class="text-[var(--color-ink-muted)]">Thumbnail path</span>
          <input
            v-model="form.thumbnail_path"
            type="text"
            class="mt-1 w-full rounded-md border border-[var(--color-border-strong)] bg-transparent px-2 py-1.5 font-mono text-xs"
          />
        </label>

        <label class="block text-sm">
          <span class="text-[var(--color-ink-muted)]">Accent colour</span>
          <input
            v-model="form.accent_color"
            type="text"
            required
            class="mt-1 w-full rounded-md border border-[var(--color-border-strong)] bg-transparent px-2 py-1.5 font-mono text-xs"
          />
        </label>
      </div>
      <p v-if="form.errors.accent_color" class="text-xs text-rose-500">
        {{ form.errors.accent_color }}
      </p>

      <button
        type="submit"
        :disabled="form.processing"
        class="rounded-md bg-[var(--color-accent)] px-3 py-2 text-sm font-medium text-white disabled:opacity-50"
      >
        {{ organ === null ? 'Create as draft' : 'Save' }}
      </button>
    </form>
  </AdminPage>
</template>
