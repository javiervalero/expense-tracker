import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { createPoller } from '../receiptPolling'

// The poller's contract: `fetchOnce` and `onAnswer` do not throw (getReceipt never does). If one does anyway, the poller
// halts (no timer, no listener), calls `onError` every time, and returns normally: nothing becomes an unhandled
// rejection. A request that was superseded meanwhile is silent.

beforeEach(() => {
  vi.useFakeTimers()
})
afterEach(() => {
  vi.useRealTimers()
})

async function expectHalted(fetchOnce: ReturnType<typeof vi.fn>): Promise<void> {
  const calls = fetchOnce.mock.calls.length
  expect(vi.getTimerCount()).toBe(0)
  document.dispatchEvent(new Event('visibilitychange'))
  await vi.advanceTimersByTimeAsync(60_000)
  expect(fetchOnce.mock.calls.length).toBe(calls)
}

describe('createPoller: a throwing owner', () => {
  it('halts and calls onError when fetchOnce throws', async () => {
    const boom = new Error('boom')
    const fetchOnce = vi.fn().mockRejectedValue(boom)
    const onError = vi.fn()
    const poller = createPoller({
      fetchOnce,
      onAnswer: () => true,
      onBudgetSpent: () => {},
      onError,
    })

    await expect(poller.restart()).resolves.toBeUndefined()

    expect(onError).toHaveBeenCalledTimes(1)
    expect(onError).toHaveBeenCalledWith(boom)
    await expectHalted(fetchOnce)
  })

  it('halts and calls onError when onAnswer throws', async () => {
    const bad = new Error('bad answer')
    const fetchOnce = vi.fn().mockResolvedValue('answer')
    const onAnswer = vi.fn(() => {
      throw bad
    })
    const onError = vi.fn()
    const poller = createPoller({ fetchOnce, onAnswer, onBudgetSpent: () => {}, onError })

    await expect(poller.restart()).resolves.toBeUndefined()

    expect(onError).toHaveBeenCalledTimes(1)
    expect(onError).toHaveBeenCalledWith(bad)
    await expectHalted(fetchOnce)
  })

  it('calls onError for a timer-driven throw and leaves no unhandled rejection (vitest fails the run on one)', async () => {
    const boom = new Error('timer boom')
    const fetchOnce = vi.fn().mockRejectedValue(boom)
    const onError = vi.fn()
    const poller = createPoller({
      fetchOnce,
      onAnswer: () => true,
      onBudgetSpent: () => {},
      onError,
    })

    poller.begin()
    await vi.advanceTimersByTimeAsync(1_000)
    await vi.advanceTimersByTimeAsync(60_000)

    expect(onError).toHaveBeenCalledTimes(1)
    expect(onError).toHaveBeenCalledWith(boom)
    await expectHalted(fetchOnce)
  })

  it('stays silent, and leaves a newer session running, when a superseded request throws late', async () => {
    let rejectOld: (error: Error) => void = () => {}
    const fetchOnce = vi
      .fn()
      .mockReturnValueOnce(new Promise((_resolve, reject) => (rejectOld = reject)))
      .mockResolvedValue('answer')
    const onError = vi.fn()
    const poller = createPoller({
      fetchOnce,
      onAnswer: () => true,
      onBudgetSpent: () => {},
      onError,
    })
    const old = poller.restart()

    await poller.restart()
    rejectOld(new Error('late'))
    await old

    expect(onError).not.toHaveBeenCalled()
    expect(vi.getTimerCount()).toBe(1)
    poller.halt()
  })
})
