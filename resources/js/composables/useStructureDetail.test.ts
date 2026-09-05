import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope, nextTick, ref } from 'vue'
import { useStructureDetail } from './useStructureDetail'
import type { StructureDetail, StructureId } from '@/types/explore'

/**
 * The info panel's one network call.
 *
 * Its failure mode matters more than its success: everything the panel shows
 * except `location` and the related links already came down with the organ, so
 * a failed request must degrade to a slightly thinner panel and never to an
 * error state.
 */
function detailFor(id: string): StructureDetail {
  return {
    id,
    slug: id,
    name: `Structure ${id}`,
    taTerm: null,
    scientificName: null,
    description: null,
    function: null,
    difficulty: 1,
    anchorPosition: [0, 0, 0],
    modelObjectName: null,
    markerColor: null,
    location: 'Somewhere',
    metadata: {},
    organ: { id: 'org_heart', slug: 'heart', name: 'Heart', accentColor: '#d1584f' },
    relatedStructures: [],
  }
}

function jsonResponse(id: string): Response {
  return {
    ok: true,
    status: 200,
    json: () => Promise.resolve({ data: detailFor(id) }),
  } as unknown as Response
}

/** Runs a composable inside a scope, the way a component would. */
function withScope<T>(run: () => T): { result: T; stop: () => void } {
  const scope = effectScope()
  const result = scope.run(run) as T
  return { result, stop: () => scope.stop() }
}

let fetchMock: ReturnType<typeof vi.fn>

beforeEach(() => {
  fetchMock = vi.fn((input: string) => {
    const id = input.split('/').at(-1) ?? ''
    return Promise.resolve(jsonResponse(id))
  })
  vi.stubGlobal('fetch', fetchMock)
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('useStructureDetail', () => {
  it('loads the detail for the selected structure', async () => {
    const id = ref<StructureId | null>('7')
    const { result, stop } = withScope(() => useStructureDetail(id))

    await vi.waitUntil(() => result.detail.value !== null)

    expect(result.detail.value?.location).toBe('Somewhere')
    expect(fetchMock).toHaveBeenCalledWith('/api/v1/anatomy/structures/7', expect.anything())

    stop()
  })

  it('serves a structure the student has already opened from memory', async () => {
    const id = ref<StructureId | null>('7')
    const { result, stop } = withScope(() => useStructureDetail(id))

    await vi.waitUntil(() => result.detail.value !== null)

    id.value = '8'
    await vi.waitUntil(() => result.detail.value?.id === '8')

    id.value = '7'
    await nextTick()

    expect(result.detail.value?.id).toBe('7')
    expect(fetchMock).toHaveBeenCalledTimes(2)

    stop()
  })

  it('ignores a response that arrives after the student moved on', async () => {
    const resolvers = new Map<string, (response: Response) => void>()
    fetchMock.mockImplementation(
      (input: string) =>
        new Promise<Response>((resolve) => {
          resolvers.set(input.split('/').at(-1) ?? '', resolve)
        }),
    )

    const id = ref<StructureId | null>('slow')
    const { result, stop } = withScope(() => useStructureDetail(id))

    id.value = 'fast'
    await nextTick()

    resolvers.get('fast')?.(jsonResponse('fast'))
    await vi.waitUntil(() => result.detail.value !== null)

    resolvers.get('slow')?.(jsonResponse('slow'))
    await nextTick()

    expect(result.detail.value?.id).toBe('fast')

    stop()
  })

  it('reports a failure without throwing, so the panel still renders', async () => {
    fetchMock.mockRejectedValue(new Error('offline'))

    const id = ref<StructureId | null>('7')
    const { result, stop } = withScope(() => useStructureDetail(id))

    await vi.waitUntil(() => result.failed.value)

    expect(result.detail.value).toBeNull()
    expect(result.isLoading.value).toBe(false)

    stop()
  })

  it('clears the detail when the selection is cleared', async () => {
    const id = ref<StructureId | null>('7')
    const { result, stop } = withScope(() => useStructureDetail(id))

    await vi.waitUntil(() => result.detail.value !== null)

    id.value = null
    await nextTick()

    expect(result.detail.value).toBeNull()

    stop()
  })
})
