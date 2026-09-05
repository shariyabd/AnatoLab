<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Head } from '@inertiajs/vue3'
import TutorPanel from '@/Components/Tutor/TutorPanel.vue'
import { useTutorSubject } from '@/composables/useTutorSubject'

/**
 * The tutor on its own page.
 *
 * Its real home is the slot Handover 05 leaves in Explore.vue, where the
 * selected structure comes from the 3D viewer. Until that lands, this page
 * supplies the same two inputs — an organ and a structure — from Handover 03's
 * published data, so the tutor is demonstrable end to end without depending on
 * a lane being built in parallel (docs/handovers/parallel-execution-plan.md D3).
 *
 * It queries nothing itself; the structure lookup lives in a composable
 * (invariant 8).
 */
interface OrganSummary {
  id: string
  slug: string
  name: string
}

const props = defineProps<{ organs: OrganSummary[] }>()

const organSlug = ref<string | null>(props.organs[0]?.slug ?? null)
const structureId = ref<number | null>(null)

const { structures, isLoading, error, loadStructures } = useTutorSubject()

const organName = computed(
  () => props.organs.find((organ) => organ.slug === organSlug.value)?.name ?? null,
)

const structureName = computed(
  () =>
    structures.value.find((structure) => Number(structure.id) === structureId.value)?.name ?? null,
)

watch(
  organSlug,
  (slug) => {
    structureId.value = null
    if (slug !== null) void loadStructures(slug)
  },
  { immediate: true },
)
</script>

<template>
  <Head title="Tutor" />

  <h1 class="text-2xl font-semibold tracking-tight">Anatomy tutor</h1>

  <p class="mt-2 max-w-2xl text-[var(--color-ink-muted)]">
    Pick what you are looking at, then ask. The tutor answers at your education level and uses the
    selected structure as context.
  </p>

  <div class="mt-6 grid gap-6 lg:grid-cols-[18rem_1fr]">
    <div class="space-y-4">
      <div v-if="organs.length === 0" class="text-sm text-[var(--color-ink-muted)]">
        No organs have been published yet. The tutor still answers general anatomy questions.
      </div>

      <template v-else>
        <div>
          <label for="tutor-organ" class="block text-sm font-medium">Organ</label>
          <select
            id="tutor-organ"
            v-model="organSlug"
            class="mt-1 w-full rounded-md border border-[var(--color-border-strong)] bg-[var(--color-surface-raised)] px-3 py-2 text-sm"
          >
            <option v-for="organ in organs" :key="organ.id" :value="organ.slug">
              {{ organ.name }}
            </option>
          </select>
        </div>

        <div>
          <label for="tutor-structure" class="block text-sm font-medium">Structure</label>
          <select
            id="tutor-structure"
            v-model="structureId"
            :disabled="isLoading || structures.length === 0"
            class="mt-1 w-full rounded-md border border-[var(--color-border-strong)] bg-[var(--color-surface-raised)] px-3 py-2 text-sm disabled:opacity-50"
          >
            <option :value="null">Whole organ</option>
            <option
              v-for="structure in structures"
              :key="structure.id"
              :value="Number(structure.id)"
            >
              {{ structure.name }}
            </option>
          </select>
          <p v-if="error" class="mt-1 text-xs text-[var(--color-danger)]">{{ error }}</p>
        </div>
      </template>
    </div>

    <div class="min-h-[32rem]">
      <TutorPanel
        :organ-slug="organSlug"
        :organ-name="organName"
        :structure-id="structureId"
        :structure-name="structureName"
      />
    </div>
  </div>
</template>
