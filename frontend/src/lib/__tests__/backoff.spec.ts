import { describe, expect, it } from 'vitest'

import { POLL_TOTAL_CAP_MS } from '../../config'
import { nextPollDelayMs, pollCapReached } from '../backoff'

describe('nextPollDelayMs', () => {
  it.each([
    [0, 1000],
    [1, 1500],
    [2, 2250],
    [3, 3375],
    [4, 5000],
    [5, 5000],
    [50, 5000],
    [10_000, 5000],
  ])('attempt %i waits %i ms', (attempt, expected) => {
    expect(nextPollDelayMs(attempt)).toBe(expected)
  })

  it.each([-1, -100, Number.NaN, Number.NEGATIVE_INFINITY])(
    'treats attempt %s as the first one (1000 ms) without throwing',
    (attempt) => {
      expect(nextPollDelayMs(attempt)).toBe(1000)
    },
  )

  it('caps an infinite attempt at 5000 ms', () => {
    expect(nextPollDelayMs(Number.POSITIVE_INFINITY)).toBe(5000)
  })

  it('rounds a non-integer attempt down (2.9 gives the delay of attempt 2)', () => {
    expect(nextPollDelayMs(2.9)).toBe(2250)
  })
})

describe('pollCapReached', () => {
  it('is false below the total cap', () => {
    expect(pollCapReached(0)).toBe(false)
    expect(pollCapReached(POLL_TOTAL_CAP_MS - 1)).toBe(false)
  })

  it('is true at the total cap and above', () => {
    expect(pollCapReached(POLL_TOTAL_CAP_MS)).toBe(true)
    expect(pollCapReached(POLL_TOTAL_CAP_MS + 1)).toBe(true)
  })
})
