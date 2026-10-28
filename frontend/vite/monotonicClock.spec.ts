import { describe, expect, it } from 'vitest'

import { makeMonotonic } from './monotonicClock'

function clockOf(readings: number[]): () => number {
  let next = 0
  return () => readings[next++] as number
}

describe('makeMonotonic', () => {
  it('passes readings through while the clock only moves forward', () => {
    const now = makeMonotonic(clockOf([100, 100, 101, 250]))

    expect([now(), now(), now(), now()]).toEqual([100, 100, 101, 250])
  })

  it('never goes backwards when the system clock is stepped back', () => {
    const now = makeMonotonic(clockOf([1000, 1001, 401, 600, 1002, 1003]))

    expect([now(), now(), now(), now(), now(), now()]).toEqual([1000, 1001, 1001, 1001, 1002, 1003])
  })

  it('keeps a separate high-water mark per clock', () => {
    const first = makeMonotonic(clockOf([500, 10]))
    const second = makeMonotonic(clockOf([20]))

    expect(first()).toBe(500)
    expect(second()).toBe(20)
    expect(first()).toBe(500)
  })
})
