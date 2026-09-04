import { describe, expect, it } from 'vitest'
import { FIT_SIZE, MAX_CAMERA_DISTANCE_FACTOR, HOTSPOT_SURFACE_OFFSET } from './constants'

describe('viewer constants', () => {
  it('pins FIT_SIZE to 3.8', () => {
    // The PHP side asserts the same thing from the other direction
    // (tests/Unit/FitSizeParityTest.php). Both are needed: this one catches a
    // change made without running the PHP suite, that one catches drift
    // between the two languages.
    expect(FIT_SIZE).toBe(3.8)
  })

  it('keeps the camera ceiling proportional to the normalised size', () => {
    expect(MAX_CAMERA_DISTANCE_FACTOR).toBeGreaterThan(1)
  })

  it('offsets hotspots off the surface without floating them away from it', () => {
    expect(HOTSPOT_SURFACE_OFFSET).toBeGreaterThan(0)
    expect(HOTSPOT_SURFACE_OFFSET).toBeLessThan(FIT_SIZE / 10)
  })
})
