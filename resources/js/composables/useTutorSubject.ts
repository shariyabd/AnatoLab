/**
 * Resolves the structures of one organ, for the tutor page's own subject picker.
 *
 * Reads Handover 03's published organ endpoint. It exists because the tutor
 * ships on its own route while Handover 05 builds Explore
 * (docs/handovers/parallel-execution-plan.md D3): once the panel is mounted
 * inside Explore, the selected structure comes from the viewer and this
 * composable is not used there.
 *
 * Kept out of the components so no template queries anything (invariant 8).
 */

import { ref, shallowRef } from 'vue'

export interface StructureOption {
  readonly id: string
  readonly name: string
}

interface OrganPayload {
  data: {
    name: string
    structures: readonly { id: string; name: string }[]
  }
}

export function useTutorSubject() {
  const structures = shallowRef<readonly StructureOption[]>([])
  const isLoading = ref(false)
  const error = ref<string | null>(null)

  async function loadStructures(organSlug: string): Promise<void> {
    structures.value = []
    error.value = null
    isLoading.value = true

    try {
      const response = await fetch(`/api/v1/anatomy/organs/${encodeURIComponent(organSlug)}`, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      })

      if (!response.ok) {
        error.value = 'That organ could not be loaded.'
        return
      }

      const payload = (await response.json()) as OrganPayload
      structures.value = payload.data.structures.map((structure) => ({
        id: structure.id,
        name: structure.name,
      }))
    } catch {
      error.value = 'That organ could not be loaded.'
    } finally {
      isLoading.value = false
    }
  }

  return { structures, isLoading, error, loadStructures }
}
