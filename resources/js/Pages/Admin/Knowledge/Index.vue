<script setup lang="ts">
import { useForm, router } from '@inertiajs/vue3'
import AdminPage from '@/Components/Admin/AdminPage.vue'
import StatusBadge from '@/Components/Admin/StatusBadge.vue'
import type { AdminKnowledgeDocument, OrganOption, Paginated } from '@/types/admin'

/**
 * RAG source documents: upload, and watch the ingest.
 *
 * The status column is acceptance criterion 4 — pending → processing → indexed
 * | failed. Processing happens on the `ingest` queue, so a document arrives as
 * `pending` and the page is reloaded to see it move.
 *
 * Plain text and Markdown only. Every binary format needs a parser dependency,
 * which is a human decision with a licence check (docs/engineering.md §5) — so
 * the alternative offered here is pasting the text.
 */
const props = defineProps<{
  documents: Paginated<AdminKnowledgeDocument>
  options: { sourceTypes: string[]; organs: OrganOption[] }
}>()

const form = useForm<{
  title: string
  source: string
  source_type: string
  organ_id: string
  education_level: string
  content_type: string
  document: File | null
  text: string
}>({
  title: '',
  source: '',
  source_type: props.options.sourceTypes[0] ?? 'textbook',
  organ_id: '',
  education_level: 'high_school',
  content_type: '',
  document: null,
  text: '',
})

function submit(): void {
  form.post('/admin/knowledge', {
    preserveScroll: true,
    forceFormData: true,
    onSuccess: () => form.reset(),
  })
}

function pickFile(event: Event): void {
  const input = event.target as HTMLInputElement
  form.document = input.files?.[0] ?? null
}

function reingest(document: AdminKnowledgeDocument): void {
  router.post(`/admin/knowledge/${document.id}/reingest`, {}, { preserveScroll: true })
}

function destroy(document: AdminKnowledgeDocument): void {
  router.delete(`/admin/knowledge/${document.id}`, { preserveScroll: true })
}

function formatSize(bytes: number | null): string {
  if (bytes === null) return '—'
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}
</script>

<template>
  <AdminPage title="Knowledge documents" description="RAG sources and their ingestion status.">
    <template #actions>
      <button
        type="button"
        class="rounded-md border border-[var(--color-border-subtle)] px-3 py-2 text-sm"
        @click="router.reload({ only: ['documents'] })"
      >
        Refresh status
      </button>
    </template>

    <div class="grid gap-6 lg:grid-cols-[1fr_22rem]">
      <div class="overflow-x-auto rounded-lg border border-[var(--color-border-subtle)]">
        <table class="w-full text-sm">
          <thead class="bg-[var(--color-surface-raised)] text-left">
            <tr>
              <th class="px-4 py-2 font-medium">Title</th>
              <th class="px-4 py-2 font-medium">Size</th>
              <th class="px-4 py-2 font-medium">Chunks</th>
              <th class="px-4 py-2 font-medium">Status</th>
              <th class="px-4 py-2 font-medium"><span class="sr-only">Actions</span></th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="document in documents.data"
              :key="document.id"
              class="border-t border-[var(--color-border-subtle)]"
            >
              <td class="px-4 py-2">
                <p class="font-medium">{{ document.title }}</p>
                <p class="text-xs text-[var(--color-ink-muted)]">
                  {{ document.source }} · v{{ document.version }}
                </p>
                <p v-if="document.failureReason" class="mt-1 text-xs text-rose-500">
                  {{ document.failureReason }}
                </p>
              </td>
              <td class="px-4 py-2 text-[var(--color-ink-muted)]">
                {{ formatSize(document.sizeBytes) }}
              </td>
              <td class="px-4 py-2 text-[var(--color-ink-muted)]">
                {{ document.chunkCount ?? 0 }}
              </td>
              <td class="px-4 py-2">
                <StatusBadge :status="document.status" :label="document.statusLabel" />
              </td>
              <td class="px-4 py-2">
                <div class="flex justify-end gap-3 text-xs">
                  <button type="button" class="underline" @click="reingest(document)">
                    Re-index
                  </button>
                  <button type="button" class="underline" @click="destroy(document)">Delete</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>

        <p
          v-if="documents.data.length === 0"
          class="px-4 py-6 text-sm text-[var(--color-ink-muted)]"
        >
          Nothing in the knowledge base yet.
        </p>
      </div>

      <form
        class="space-y-3 rounded-lg border border-[var(--color-border-subtle)] bg-[var(--color-surface-raised)] p-4"
        @submit.prevent="submit"
      >
        <h2 class="font-medium">Add a document</h2>

        <label class="block text-sm">
          <span class="text-[var(--color-ink-muted)]">Title</span>
          <input
            v-model="form.title"
            type="text"
            required
            class="mt-1 w-full rounded-md border border-[var(--color-border-strong)] bg-transparent px-2 py-1.5"
          />
        </label>
        <p v-if="form.errors.title" class="text-xs text-rose-500">{{ form.errors.title }}</p>

        <label class="block text-sm">
          <span class="text-[var(--color-ink-muted)]">Citation</span>
          <input
            v-model="form.source"
            type="text"
            required
            class="mt-1 w-full rounded-md border border-[var(--color-border-strong)] bg-transparent px-2 py-1.5"
          />
        </label>
        <p v-if="form.errors.source" class="text-xs text-rose-500">{{ form.errors.source }}</p>

        <label class="block text-sm">
          <span class="text-[var(--color-ink-muted)]">Source type</span>
          <select
            v-model="form.source_type"
            class="mt-1 w-full rounded-md border border-[var(--color-border-strong)] bg-transparent px-2 py-1.5"
          >
            <option v-for="type in options.sourceTypes" :key="type" :value="type">
              {{ type }}
            </option>
          </select>
        </label>

        <label class="block text-sm">
          <span class="text-[var(--color-ink-muted)]">Organ (retrieval default)</span>
          <select
            v-model="form.organ_id"
            class="mt-1 w-full rounded-md border border-[var(--color-border-strong)] bg-transparent px-2 py-1.5"
          >
            <option value="">Any</option>
            <option v-for="organ in options.organs" :key="organ.id" :value="organ.id">
              {{ organ.name }}
            </option>
          </select>
        </label>

        <label class="block text-sm">
          <span class="text-[var(--color-ink-muted)]">File (.txt or .md)</span>
          <input
            type="file"
            accept=".txt,.md,.markdown,text/plain,text/markdown"
            class="mt-1 w-full text-xs"
            @change="pickFile"
          />
        </label>
        <p v-if="form.errors.document" class="text-xs text-rose-500">{{ form.errors.document }}</p>

        <label class="block text-sm">
          <span class="text-[var(--color-ink-muted)]">…or paste the text</span>
          <textarea
            v-model="form.text"
            rows="4"
            class="mt-1 w-full rounded-md border border-[var(--color-border-strong)] bg-transparent px-2 py-1.5"
          />
        </label>
        <p v-if="form.errors.text" class="text-xs text-rose-500">{{ form.errors.text }}</p>

        <button
          type="submit"
          :disabled="form.processing"
          class="rounded-md bg-[var(--color-accent)] px-3 py-2 text-sm font-medium text-white disabled:opacity-50"
        >
          Upload and index
        </button>

        <p class="text-xs text-[var(--color-ink-muted)]">
          Indexing runs on the queue — the document appears as pending and moves to indexed when the
          job finishes.
        </p>
      </form>
    </div>
  </AdminPage>
</template>
