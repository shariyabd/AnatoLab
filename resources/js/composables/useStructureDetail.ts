import { ref, shallowRef, watch, type Ref } from 'vue'
import type { StructureDetail, StructureId } from '@/types/explore'

/**
 * Loads the info panel's payload for the selected structure.
 *
 * Network access belongs to the Vue layer — the viewer never fetches
 * (docs/architecture.md §5.4 rule 3). This is that layer for one endpoint:
 * `GET /api/v1/anatomy/structures/{id}`, which returns the structure plus its
 * `location`, its organ, and its related structures. Only the related
 * structures are unavailable from the `OrganDto` the page already holds, so a
 * failed request degrades to "no related structures", never to a blank panel.
 *
 * Results are memoised for the life of the page. A student clicking between
 * eight structures on one organ makes eight requests, not eighty, and going
 * back to a structure is instant.
 */
export function useStructureDetail(structureId: Ref<StructureId | null>) {
  const cache = new Map<StructureId, StructureDetail>()
  const detail = shallowRef<StructureDetail | null>(null)
  const isLoading = ref(false)
  /** Non-fatal by construction: the panel renders without it. */
  const failed = ref(false)

  /**
   * Guards against an out-of-order response. Clicking A then B quickly must
   * not leave A's detail on screen because A's request finished second.
   */
  let requestToken = 0

  async function load(id: StructureId): Promise<void> {
    const cached = cache.get(id)

    if (cached !== undefined) {
      detail.value = cached
      failed.value = false
      return
    }

    const token = ++requestToken
    isLoading.value = true
    failed.value = false

    try {
      const response = await fetch(`/api/v1/anatomy/structures/${encodeURIComponent(id)}`, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
      })

      if (!response.ok) throw new Error(`HTTP ${String(response.status)}`)

      const body = (await response.json()) as { data: StructureDetail }
      cache.set(id, body.data)

      if (token === requestToken) detail.value = body.data
    } catch {
      // Swallowed on purpose: the caller has already rendered everything this
      // request would have added except the related-structure links, and a
      // console stack trace on a panel that still works is noise
      // (docs/engineering.md §11 item 7).
      if (token === requestToken) failed.value = true
    } finally {
      if (token === requestToken) isLoading.value = false
    }
  }

  watch(
    structureId,
    (id) => {
      if (id === null) {
        requestToken += 1
        detail.value = null
        failed.value = false
        isLoading.value = false
        return
      }

      void load(id)
    },
    { immediate: true },
  )

  return { detail, isLoading, failed }
}
