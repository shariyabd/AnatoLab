import { describe, expect, it } from 'vitest'
import anatomyConfig from '../../../config/anatomy.php?raw'
import { FIT_SIZE } from './constants'

/**
 * FIT_SIZE is mirrored in PHP and must agree with it.
 *
 * Every `anchor_position` in the database is authored in this pivot space. Change
 * the number and every authored coordinate silently points at the wrong part of
 * the model — and no migration can repair it, because the original authoring
 * scale is not recoverable from the stored values (docs/architecture.md §5.4
 * rule 1).
 *
 * `tests/Unit/FitSizeParityTest.php` asserts the same thing from the PHP side.
 * Both are needed: that one catches a change made without running the frontend
 * suite, this one catches a change made without running Pest. The asset
 * pipeline's third copy is checked by `scripts/verify-models.mjs`.
 */
describe('FIT_SIZE parity', () => {
  it("matches config('anatomy.fit_size')", () => {
    const match = /'fit_size'\s*=>\s*([0-9.]+)/.exec(anatomyConfig)

    expect(match, 'config/anatomy.php no longer declares fit_size').not.toBeNull()
    expect(Number(match![1])).toBe(FIT_SIZE)
  })
})
