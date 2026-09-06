<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AdminPage from '@/Components/Admin/AdminPage.vue'
import StatusBadge from '@/Components/Admin/StatusBadge.vue'
import { useAnatomyViewer } from '@/composables/useAnatomyViewer'
import type { AdminStructure, AuthoringContext, OrganDto } from '@/types/admin'

/**
 * The hotspot authoring tool — handover 13's highest-value screen.
 *
 * Click the model → the viewer raycasts and emits `author:point` with a
 * coordinate in FIT_SIZE pivot space → name the structure → it is saved to
 * `anatomical_structures.anchor_position`.
 *
 * This replaces hand-editing coordinates. The upstream tool did the same
 * raycast behind `?authoring=1` and printed a literal to copy
 * (docs/project-context.md §2.4); this one is admin-gated and writes to the
 * database.
 *
 * **The coordinate is only meaningful against one model file.** `fitSize` and
 * the model fingerprint come from the server, and any structure whose recorded
 * provenance disagrees with the model now on disk is flagged — an anchor
 * authored against a re-encoded model can point at the wrong anatomy, and
 * nothing else in the data would say so (invariant 5).
 *
 * The viewer is reached only through `useAnatomyViewer`, the single bridge
 * between Vue and `resources/js/anatomy/` (invariant 3).
 */
const props = defineProps<{
  organ: OrganDto
  structures: AdminStructure[]
  authoring: AuthoringContext
}>()

const container = ref<HTMLElement | null>(null)

/** The last point clicked on the model, in FIT_SIZE pivot space. */
const pendingPoint = ref<readonly [number, number, number] | null>(null)

/** When set, the next click moves this existing marker instead of creating one. */
const movingStructureId = ref<string | null>(null)

const organRef = computed(() => props.organ)

const viewer = useAnatomyViewer({
  container,
  organ: organRef,
  // Author mode from the first frame: markers stop responding to picks and the
  // cursor becomes a crosshair, so a click means "author here" and never
  // "select this".
  initialMode: 'author',
  on: {
    'author:point': ({ position }) => {
      const point: [number, number, number] = [position[0], position[1], position[2]]

      if (movingStructureId.value !== null) {
        moveAnchor(movingStructureId.value, point)
        return
      }

      pendingPoint.value = point
      form.anchor_position = point
    },
  },
})

const form = useForm<{
  slug: string
  name: string
  ta_term: string
  difficulty: number
  anchor_position: [number, number, number]
}>({
  slug: '',
  name: '',
  ta_term: '',
  difficulty: 1,
  anchor_position: [0, 0, 0],
})

/** Anchors the server could not confirm against the model file on disk. */
const staleAnchors = computed(() =>
  props.structures.filter((structure) => !structure.anchorMatchesCurrentModel),
)

function formatPoint(point: readonly [number, number, number]): string {
  return point.map((axis) => axis.toFixed(3)).join(', ')
}

/** Derive a slug from the name so the admin types one field, not two. */
function syncSlug(): void {
  if (form.isDirty && form.slug !== '') return

  form.slug = form.name
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '')
}

function createStructure(): void {
  form.post(`/admin/organs/${props.organ.slug}/structures`, {
    preserveScroll: true,
    onSuccess: () => {
      form.reset()
      pendingPoint.value = null
    },
  })
}

/**
 * Move an existing marker.
 *
 * A bare `router.patch` rather than a form submit, and the response is JSON
 * rather than a redirect: the model stays loaded while the dot moves, and a
 * full Inertia visit would re-download the GLB to change three numbers.
 */
function moveAnchor(structureId: string, point: [number, number, number]): void {
  router.patch(
    `/admin/structures/${structureId}/anchor`,
    { anchor_position: point },
    {
      preserveScroll: true,
      preserveState: true,
      onFinish: () => {
        movingStructureId.value = null
        router.reload({ only: ['structures'] })
      },
    },
  )
}

function togglePublished(structure: AdminStructure): void {
  router.patch(
    `/admin/structures/${structure.id}/status`,
    { is_published: !structure.isPublished },
    { preserveScroll: true },
  )
}
</script>

<template>
  <AdminPage
    :title="`Author hotspots — ${organ.name}`"
    description="Click the model to place a structure. Coordinates are stored in the model's normalised pivot space."
  >
    <template #actions>
      <a
        :href="`/admin/organs/${organ.slug}/edit`"
        class="rounded-md border border-[var(--color-border-subtle)] px-3 py-2 text-sm"
      >
        Edit organ
      </a>
    </template>

    <!--
      The provenance banner. FIT_SIZE is frozen at 3.8 and every anchor in the
      table is authored in that space; showing it is how an admin knows what the
      numbers below mean (invariant 5).
    -->
    <div
      class="mb-4 rounded-lg border border-[var(--color-border-subtle)] bg-[var(--color-surface-raised)] px-4 py-3 text-sm"
    >
      <div class="flex flex-wrap gap-x-6 gap-y-1">
        <span>
          <span class="text-[var(--color-ink-muted)]">Pivot space:</span>
          FIT_SIZE = {{ authoring.fitSize }}
        </span>
        <span>
          <span class="text-[var(--color-ink-muted)]">Model:</span>
          {{ authoring.currentModel.model_path }}
        </span>
        <span>
          <span class="text-[var(--color-ink-muted)]">Version:</span>
          {{ authoring.currentModel.model_fingerprint ?? 'file missing on disk' }}
        </span>
      </div>
    </div>

    <div
      v-if="staleAnchors.length > 0"
      class="mb-4 rounded-lg border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm"
      role="alert"
    >
      <p class="font-medium">
        {{ staleAnchors.length }}
        {{ staleAnchors.length === 1 ? 'anchor was' : 'anchors were' }} not authored against this
        model file.
      </p>
      <p class="mt-1 text-[var(--color-ink-muted)]">
        Their coordinates may no longer point at the right anatomy. Re-place them by selecting
        “Move” and clicking the correct spot.
      </p>
    </div>

    <div class="grid gap-6 lg:grid-cols-[1fr_22rem]">
      <div>
        <div
          ref="container"
          class="aspect-square w-full overflow-hidden rounded-lg border border-[var(--color-border-subtle)] bg-[var(--color-surface-sunken)]"
        />

        <p v-if="viewer.isLoading.value" class="mt-2 text-sm text-[var(--color-ink-muted)]">
          Loading the model…
        </p>

        <p v-else-if="viewer.failure.value !== null" class="mt-2 text-sm text-rose-500">
          The model could not be loaded, so nothing can be authored against it:
          {{ viewer.failure.value.detail }}
        </p>

        <p v-else-if="!viewer.isAvailable.value" class="mt-2 text-sm text-rose-500">
          This browser cannot run WebGL. Authoring needs the 3D view.
        </p>

        <p v-else class="mt-2 text-sm text-[var(--color-ink-muted)]">
          <template v-if="movingStructureId !== null">
            Click the model to move the selected marker.
          </template>
          <template v-else> Click anywhere on the model to place a new structure. </template>
        </p>
      </div>

      <div class="space-y-6">
        <section
          class="rounded-lg border border-[var(--color-border-subtle)] bg-[var(--color-surface-raised)] p-4"
        >
          <h2 class="font-medium">New structure</h2>

          <p v-if="pendingPoint === null" class="mt-2 text-sm text-[var(--color-ink-muted)]">
            Click the model to capture a coordinate.
          </p>

          <form v-else class="mt-3 space-y-3" @submit.prevent="createStructure">
            <p class="rounded bg-[var(--color-surface-sunken)] px-2 py-1 font-mono text-xs">
              [{{ formatPoint(pendingPoint) }}]
            </p>

            <label class="block text-sm">
              <span class="text-[var(--color-ink-muted)]">Name</span>
              <input
                v-model="form.name"
                type="text"
                required
                class="mt-1 w-full rounded-md border border-[var(--color-border-strong)] bg-transparent px-2 py-1.5"
                @blur="syncSlug"
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
              <span class="text-[var(--color-ink-muted)]">Terminologia Anatomica term</span>
              <input
                v-model="form.ta_term"
                type="text"
                class="mt-1 w-full rounded-md border border-[var(--color-border-strong)] bg-transparent px-2 py-1.5"
              />
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

            <p v-if="form.errors.anchor_position" class="text-xs text-rose-500">
              {{ form.errors.anchor_position }}
            </p>

            <div class="flex gap-2">
              <button
                type="submit"
                :disabled="form.processing"
                class="rounded-md bg-[var(--color-accent)] px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
              >
                Save hotspot
              </button>
              <button
                type="button"
                class="rounded-md border border-[var(--color-border-subtle)] px-3 py-1.5 text-sm"
                @click="pendingPoint = null"
              >
                Discard
              </button>
            </div>

            <p class="text-xs text-[var(--color-ink-muted)]">
              Saved unpublished. Publish it below once the metadata is right.
            </p>
          </form>
        </section>

        <section
          class="rounded-lg border border-[var(--color-border-subtle)] bg-[var(--color-surface-raised)] p-4"
        >
          <h2 class="font-medium">
            Structures
            <span class="text-sm font-normal text-[var(--color-ink-muted)]">
              ({{ structures.length }})
            </span>
          </h2>

          <ul class="mt-3 space-y-3">
            <li v-for="structure in structures" :key="structure.id" class="text-sm">
              <div class="flex items-start justify-between gap-2">
                <div>
                  <p class="font-medium">{{ structure.name }}</p>
                  <p class="font-mono text-xs text-[var(--color-ink-muted)]">
                    [{{ formatPoint(structure.anchorPosition) }}]
                  </p>
                </div>
                <StatusBadge
                  :status="structure.isPublished ? 'published' : 'draft'"
                  :label="structure.isPublished ? 'Published' : 'Draft'"
                />
              </div>

              <p v-if="!structure.anchorMatchesCurrentModel" class="mt-1 text-xs text-amber-600">
                <template v-if="structure.authoredAgainst === null">
                  No authoring record — placed before this tool existed.
                </template>
                <template v-else>
                  Authored against {{ structure.authoredAgainst.modelPath }} at FIT_SIZE
                  {{ structure.authoredAgainst.fitSize }}.
                </template>
              </p>

              <div class="mt-1 flex gap-3 text-xs">
                <button
                  type="button"
                  class="text-[var(--color-ink-muted)] underline hover:text-[var(--color-ink)]"
                  @click="
                    movingStructureId = movingStructureId === structure.id ? null : structure.id
                  "
                >
                  {{ movingStructureId === structure.id ? 'Cancel move' : 'Move' }}
                </button>
                <button
                  type="button"
                  class="text-[var(--color-ink-muted)] underline hover:text-[var(--color-ink)]"
                  @click="togglePublished(structure)"
                >
                  {{ structure.isPublished ? 'Unpublish' : 'Publish' }}
                </button>
              </div>
            </li>
          </ul>

          <p v-if="structures.length === 0" class="mt-2 text-sm text-[var(--color-ink-muted)]">
            No structures yet. Click the model to add the first one.
          </p>
        </section>
      </div>
    </div>
  </AdminPage>
</template>
