import { effectScope } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { deferred } from '@/api/__tests__/expenseFixtures'
import type { ApiResult } from '@/api/http'
import type { Receipt } from '@/api/types'
import { describeExpenseError } from '@/lib/expenseMessages'
import { STILL_PROCESSING_MESSAGE, failureMessage } from '@/lib/receiptMessages'
import { useReceipt } from '../useReceipt'
import type { ReceiptState } from '../useReceipt'
import {
  EXPENSE_ID,
  NETWORK,
  NOT_FOUND,
  fail,
  failedReceipt,
  ok,
  pendingReceipt,
  pngFile,
  policy,
  readyReceipt,
  settle,
} from './receiptHarness'

const receiptsApi = vi.hoisted(() => ({
  getReceipt: vi.fn(),
  createReceiptUpload: vi.fn(),
  deleteReceipt: vi.fn(),
}))
const uploadApi = vi.hoisted(() => ({ uploadReceipt: vi.fn() }))
vi.mock('@/api/receipts', () => receiptsApi)
vi.mock('@/api/upload', () => uploadApi)

async function advance(ms: number): Promise<void> {
  await vi.advanceTimersByTimeAsync(ms)
  await settle()
}
// Steps one second at a time so every timer callback finishes before the next one is armed.
async function advanceBySeconds(seconds: number): Promise<void> {
  for (let second = 0; second < seconds; second += 1) {
    await advance(1_000)
  }
}
function setVisibility(state: 'visible' | 'hidden'): void {
  Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => state })
}
function pollCount(): number {
  return receiptsApi.getReceipt.mock.calls.length
}
// Nothing is left running: no timer, and the visibilitychange listener is gone (an event sends no request).
async function expectIdle(): Promise<void> {
  const before = pollCount()
  expect(vi.getTimerCount()).toBe(0)
  setVisibility('visible')
  document.dispatchEvent(new Event('visibilitychange'))
  await settle()
  expect(pollCount()).toBe(before)
}
// start() found a pending receipt, so the page is polling; the first answer after start is `later`.
// Every state a test makes is stopped afterwards: a left-over poller keeps its visibilitychange listener on the shared document.
const created: ReceiptState[] = []
function create(): ReceiptState {
  const state = useReceipt(EXPENSE_ID)
  created.push(state)
  return state
}

async function polling(later: ApiResult<Receipt> = ok(pendingReceipt())) {
  receiptsApi.getReceipt.mockResolvedValueOnce(ok(pendingReceipt())).mockResolvedValue(later)
  const state = create()
  await state.start()
  await settle()
  return state
}

beforeEach(() => {
  vi.useFakeTimers()
  setVisibility('visible')
  Object.values(receiptsApi).forEach((mock) => mock.mockReset())
  uploadApi.uploadReceipt.mockReset()
  receiptsApi.createReceiptUpload.mockResolvedValue(policy())
  uploadApi.uploadReceipt.mockResolvedValue({ ok: true })
})
afterEach(() => {
  created.splice(0).forEach((state) => state.stop())
  vi.useRealTimers()
  vi.unstubAllGlobals()
  Reflect.deleteProperty(document, 'visibilityState')
})

describe('useReceipt: polling schedule', () => {
  it('polls at 1000, 1500, 2250, 3375 and 5000 ms intervals', async () => {
    await polling()
    const reached: number[] = []

    for (const wait of [1_000, 1_500, 2_250, 3_375, 5_000, 5_000]) {
      await advance(wait - 1)
      const before = pollCount()
      await advance(1)
      reached.push(pollCount() - before)
    }

    expect(reached).toEqual([1, 1, 1, 1, 1, 1])
    expect(pollCount()).toBe(7)
  })

  it('does not poll before the first second after an upload', async () => {
    receiptsApi.getReceipt.mockResolvedValueOnce(fail(NOT_FOUND))
    const state = create()
    await state.start()
    await state.choose(pngFile())
    await settle()

    await advance(999)

    expect(pollCount()).toBe(1)
    expect(state.phase.value).toBe('processing')
  })

  it('stops with the new receipt when it is ready', async () => {
    const done = readyReceipt()
    const state = await polling(ok(done))

    await advance(1_000)

    expect(state.phase.value).toBe('ready')
    expect(state.receipt.value).toEqual(done)
    await expectIdle()
    await advance(30_000)
    expect(pollCount()).toBe(2)
  })

  it('stops with a failure message when the check failed', async () => {
    const state = await polling(ok(failedReceipt()))

    await advance(1_000)

    expect(state.phase.value).toBe('failed')
    expect(state.receipt.value).toEqual(failedReceipt())
    expect(state.message.value).toBe(failureMessage('unreadable'))
    await expectIdle()
    await advance(30_000)
    expect(pollCount()).toBe(2)
  })

  it('goes to none with no receipt and no message on a 404', async () => {
    const state = await polling(fail(NOT_FOUND))

    await advance(1_000)

    expect(state.phase.value).toBe('none')
    expect(state.receipt.value).toBeNull()
    expect(state.message.value).toBe('')
    await expectIdle()
    await advance(30_000)
    expect(pollCount()).toBe(2)
  })

  it('stops and shows the error text for any other failed poll', async () => {
    const state = await polling(fail(NETWORK))

    await advance(1_000)

    expect(state.stalled.value).toBe(true)
    expect(state.message.value).toBe(describeExpenseError(NETWORK, false).form)
    expect(state.phase.value).toBe('processing')
    await expectIdle()
    await advance(30_000)
    expect(pollCount()).toBe(2)
  })
})

describe('useReceipt: 60 second cap and Check again', () => {
  it('shows "still processing" after 60 s of visible time and stops polling', async () => {
    const state = await polling()

    await advanceBySeconds(50)
    expect(state.stalled.value).toBe(false)
    await advanceBySeconds(20)

    expect(state.stalled.value).toBe(true)
    expect(state.message.value).toBe(STILL_PROCESSING_MESSAGE)
    expect(state.phase.value).toBe('processing')
    await expectIdle()
    const frozen = pollCount()
    await advanceBySeconds(30)
    expect(pollCount()).toBe(frozen)
  })

  it('polls once at once on checkAgain, clears the notice and gets a fresh 60 s', async () => {
    const state = await polling()
    await advanceBySeconds(70)
    const stalledAt = pollCount()

    await state.checkAgain()
    await settle()

    expect(pollCount()).toBe(stalledAt + 1)
    expect(state.stalled.value).toBe(false)
    expect(state.message.value).toBe('')
    await advanceBySeconds(50)
    expect(state.stalled.value).toBe(false)
    await advanceBySeconds(20)
    expect(state.stalled.value).toBe(true)
  })
})

describe('useReceipt: hidden tab', () => {
  it('sends no request while hidden, however long it lasts, and does not use up the budget', async () => {
    const state = await polling()
    await advance(500)

    setVisibility('hidden')
    await advanceBySeconds(120)

    expect(pollCount()).toBe(1)
    expect(state.stalled.value).toBe(false)
  })

  it('polls at once when the tab shows again and then carries on within a fresh visible budget', async () => {
    const state = await polling()
    setVisibility('hidden')
    await advanceBySeconds(120)

    setVisibility('visible')
    document.dispatchEvent(new Event('visibilitychange'))
    await settle()
    expect(pollCount()).toBe(2)
    await advanceBySeconds(30)

    expect(pollCount()).toBeGreaterThan(3)
    expect(state.stalled.value).toBe(false)
  })

  it('does not poll on visibilitychange when nothing is being polled', async () => {
    receiptsApi.getReceipt.mockResolvedValue(fail(NOT_FOUND))
    const state = create()
    await state.start()
    await settle()

    document.dispatchEvent(new Event('visibilitychange'))
    await advance(10_000)

    expect(pollCount()).toBe(1)
  })
})

describe('useReceipt: stale answers and stop', () => {
  it('drops a poll answer that arrives after a replace began', async () => {
    const late = deferred<ApiResult<Receipt>>()
    const state = await polling()
    receiptsApi.getReceipt.mockReturnValueOnce(late.promise)
    await advance(1_000)
    receiptsApi.createReceiptUpload.mockReturnValue(new Promise(() => {}))
    await state.choose(pngFile())
    await state.confirmReplace()
    await settle()

    late.resolve(ok(readyReceipt()))
    await settle()

    expect(state.phase.value).toBe('requesting')
    expect(state.receipt.value).toEqual(pendingReceipt())
    expect(state.announcement.value).not.toBe('Receipt ready')
    expect(vi.getTimerCount()).toBe(0)
  })

  it('drops a poll answer that arrives while a remove is running, and stops polling', async () => {
    const late = deferred<ApiResult<Receipt>>()
    const deletion = deferred<ApiResult<void>>()
    const state = await polling()
    receiptsApi.getReceipt.mockReturnValueOnce(late.promise)
    await advance(1_000)
    receiptsApi.deleteReceipt.mockReturnValue(deletion.promise)
    const removing = state.remove()
    await settle()

    late.resolve(ok(readyReceipt()))
    await settle()
    expect(state.phase.value).toBe('processing')
    await advance(20_000)
    expect(pollCount()).toBe(2)
    deletion.resolve(ok(undefined, 204))
    await removing

    expect(state.phase.value).toBe('none')
    expect(state.receipt.value).toBeNull()
    expect(vi.getTimerCount()).toBe(0)
  })

  it('aborts the request in flight, clears timers and ignores its late answer on stop', async () => {
    const late = deferred<ApiResult<Receipt>>()
    const state = await polling()
    receiptsApi.getReceipt.mockReturnValueOnce(late.promise)
    await advance(1_000)
    const signal = receiptsApi.getReceipt.mock.calls[1]?.[1] as AbortSignal

    state.stop()
    late.resolve(ok(readyReceipt()))
    await settle()

    expect(signal.aborted).toBe(true)
    expect(state.phase.value).toBe('processing')
    expect(state.message.value).toBe('')
    await expectIdle()
  })

  it('removes the visibility listener on stop', async () => {
    const state = await polling()

    state.stop()
    setVisibility('visible')
    document.dispatchEvent(new Event('visibilitychange'))
    await advance(60_000)

    expect(vi.getTimerCount()).toBe(0)
    expect(pollCount()).toBe(1)
  })

  it('aborts the policy request and ignores its late answer on stop', async () => {
    const held = deferred<ApiResult<unknown>>()
    receiptsApi.createReceiptUpload.mockReturnValue(held.promise)
    receiptsApi.getReceipt.mockResolvedValue(fail(NOT_FOUND))
    const state = create()
    await state.start()
    void state.choose(pngFile())
    await settle()
    const signal = receiptsApi.createReceiptUpload.mock.calls[0]?.[2] as AbortSignal

    state.stop()
    held.resolve(policy())
    await settle()

    expect(signal.aborted).toBe(true)
    expect(uploadApi.uploadReceipt).not.toHaveBeenCalled()
    expect(state.phase.value).toBe('requesting')
    expect(state.message.value).toBe('')
  })

  it('aborts the upload on stop and shows nothing when it ends as aborted', async () => {
    const running = deferred<{ ok: false; error: { kind: 'aborted' } }>()
    uploadApi.uploadReceipt.mockReturnValue(running.promise)
    receiptsApi.getReceipt.mockResolvedValue(fail(NOT_FOUND))
    const state = create()
    await state.start()
    void state.choose(pngFile())
    await settle()
    const signal = uploadApi.uploadReceipt.mock.calls[0]?.[2].signal as AbortSignal

    state.stop()
    running.resolve({ ok: false, error: { kind: 'aborted' } })
    await settle()

    expect(signal.aborted).toBe(true)
    expect([state.message.value, state.fileMessage.value]).toEqual(['', ''])
    expect(vi.getTimerCount()).toBe(0)
  })

  it('stops by itself when the owning effect scope is disposed', async () => {
    const scope = effectScope()
    receiptsApi.getReceipt.mockResolvedValue(ok(pendingReceipt()))
    const state = scope.run(() => useReceipt(EXPENSE_ID))
    await state?.start()
    await settle()
    const signal = receiptsApi.getReceipt.mock.calls[0]?.[1] as AbortSignal

    scope.stop()
    await expectIdle()
    await advance(60_000)

    expect(signal.aborted).toBe(true)
    expect(pollCount()).toBe(1)
    expect(vi.getTimerCount()).toBe(0)
  })
})
