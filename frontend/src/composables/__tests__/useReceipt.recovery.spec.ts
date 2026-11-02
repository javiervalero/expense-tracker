import { effectScope } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { deferred } from '@/api/__tests__/expenseFixtures'
import type { ApiResult } from '@/api/http'
import type { Receipt } from '@/api/types'
import { describeExpenseError } from '@/lib/expenseMessages'
import { deleteErrorMessage, policyErrorMessage } from '@/lib/receiptMessages'
import { useReceipt } from '../useReceipt'
import type { ReceiptState } from '../useReceipt'
import {
  EXPENSE_ID,
  NETWORK,
  NOT_FOUND,
  fail,
  ok,
  pdfFile,
  pendingReceipt,
  pngFile,
  policy,
  readyReceipt,
  settle,
} from './receiptHarness'

// Round-2 review fixes of WP11: what a failed upload leaves behind, the failure paths of freshView, a throwing poller,
// a disposed scope, and actions that must not overlap a remove.

const receiptsApi = vi.hoisted(() => ({
  getReceipt: vi.fn(),
  createReceiptUpload: vi.fn(),
  deleteReceipt: vi.fn(),
}))
const uploadApi = vi.hoisted(() => ({ uploadReceipt: vi.fn() }))
vi.mock('@/api/receipts', () => receiptsApi)
vi.mock('@/api/upload', () => uploadApi)

const NOW = new Date('2026-11-01T12:00:00Z')
const expiresIn = (ms: number) => new Date(NOW.getTime() + ms).toISOString()
const SOON = readyReceipt({ urlsExpireAt: expiresIn(1_000) })
const GENERIC = describeExpenseError({ kind: 'server', status: 500 }, false).form

const created: ReceiptState[] = []
function create(): ReceiptState {
  const state = useReceipt(EXPENSE_ID)
  created.push(state)
  return state
}
async function begin(first: ApiResult<Receipt>): Promise<ReceiptState> {
  receiptsApi.getReceipt.mockResolvedValueOnce(first)
  const state = create()
  await state.start()
  await settle()
  return state
}
async function advance(ms: number): Promise<void> {
  await vi.advanceTimersByTimeAsync(ms)
  await settle()
}
const getCalls = () => receiptsApi.getReceipt.mock.calls.length

beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(NOW)
  Object.values(receiptsApi).forEach((mock) => mock.mockReset())
  uploadApi.uploadReceipt.mockReset()
  receiptsApi.createReceiptUpload.mockResolvedValue(policy())
  uploadApi.uploadReceipt.mockResolvedValue({ ok: true })
})
afterEach(() => {
  created.splice(0).forEach((state) => state.stop())
  vi.useRealTimers()
})

describe('useReceipt: the server replaces the receipt when the policy is issued', () => {
  it('goes to none with no receipt, and keeps the file for retry, when the S3 upload fails over a ready receipt', async () => {
    uploadApi.uploadReceipt.mockResolvedValueOnce({ ok: false, error: { kind: 'interrupted' } })
    const state = await begin(ok(readyReceipt()))
    await state.choose(pdfFile())

    await state.confirmReplace()
    await settle()

    expect(state.phase.value).toBe('none')
    expect(state.receipt.value).toBeNull()
    expect(state.hasReceipt.value).toBe(false)
    expect(state.message.value).toBe('Upload interrupted. Try again.')
    expect(vi.getTimerCount()).toBe(0)

    await state.retry()
    await settle()

    expect(receiptsApi.createReceiptUpload).toHaveBeenCalledTimes(2)
    expect(state.phase.value).toBe('processing')
    expect(state.message.value).toBe('')
  })

  it('still restores the ready receipt when the policy request is refused', async () => {
    const kept = readyReceipt()
    receiptsApi.createReceiptUpload.mockResolvedValue(fail(NETWORK))
    const state = await begin(ok(kept))
    await state.choose(pdfFile())

    await state.confirmReplace()
    await settle()

    expect(state.phase.value).toBe('ready')
    expect(state.receipt.value).toBe(kept)
    expect(state.message.value).toBe(policyErrorMessage(NETWORK))
  })
})

describe('useReceipt: freshView failure paths', () => {
  const PENDING_SOON = () => pendingReceipt()
  const pendingWithAddress = () => ({ ...PENDING_SOON(), urlsExpireAt: expiresIn(1_000) })

  it('resumes polling a pending receipt, keeps it and shows the text when the new read fails', async () => {
    const state = await begin(ok(pendingWithAddress()))
    receiptsApi.getReceipt
      .mockResolvedValueOnce(fail(NETWORK))
      .mockResolvedValue(ok(pendingReceipt()))

    const view = await state.freshView()

    expect(view).toBeNull()
    expect(state.receipt.value).toEqual(pendingWithAddress())
    expect(state.message.value).toBe(describeExpenseError(NETWORK, false).form)
    expect(vi.getTimerCount()).toBe(1)
    const before = getCalls()
    await advance(1_000)
    expect(getCalls()).toBe(before + 1)
  })

  it('goes to none, with nothing polling, when the new read says the receipt is gone', async () => {
    const state = await begin(ok(pendingWithAddress()))
    receiptsApi.getReceipt.mockResolvedValueOnce(fail(NOT_FOUND))

    const view = await state.freshView()

    expect(view).toBeNull()
    expect(state.phase.value).toBe('none')
    expect(state.receipt.value).toBeNull()
    expect(vi.getTimerCount()).toBe(0)
  })

  it('leaves a ready receipt unpolled when the new read fails', async () => {
    const state = await begin(ok(SOON))
    receiptsApi.getReceipt.mockResolvedValueOnce(fail(NETWORK))

    await state.freshView()

    expect(state.receipt.value).toEqual(SOON)
    expect(vi.getTimerCount()).toBe(0)
  })

  it('does not wipe an unrelated message when the new read succeeds', async () => {
    const renewed = readyReceipt({ urlsExpireAt: expiresIn(300_000) })
    const state = await begin(ok(SOON))
    receiptsApi.deleteReceipt.mockResolvedValue(fail(NETWORK))
    await state.remove()
    receiptsApi.getReceipt.mockResolvedValueOnce(ok(renewed))

    await state.freshView()

    expect(state.receipt.value).toEqual(renewed)
    expect(state.message.value).toBe(deleteErrorMessage(NETWORK))
  })

  it('clears its own earlier error text when a later new read succeeds', async () => {
    const renewed = readyReceipt({ urlsExpireAt: expiresIn(300_000) })
    const state = await begin(ok(SOON))
    receiptsApi.getReceipt.mockResolvedValueOnce(fail(NETWORK)).mockResolvedValueOnce(ok(renewed))
    await state.freshView()
    expect(state.message.value).not.toBe('')

    await state.freshView()

    expect(state.message.value).toBe('')
  })
})

describe('useReceipt: a throwing poller', () => {
  it('stalls with the generic text, and offers Check again, when a poll throws', async () => {
    receiptsApi.getReceipt
      .mockResolvedValueOnce(ok(pendingReceipt()))
      .mockRejectedValueOnce(new Error('boom'))
      .mockResolvedValue(ok(readyReceipt()))
    const state = create()
    await state.start()
    await settle()

    await advance(1_000)

    expect(state.stalled.value).toBe(true)
    expect(state.message.value).toBe(GENERIC)
    expect(state.phase.value).toBe('processing')
    expect(vi.getTimerCount()).toBe(0)

    await state.checkAgain()
    await settle()

    expect(state.phase.value).toBe('ready')
    expect(state.stalled.value).toBe(false)
  })
})

describe('useReceipt: start after the owning scope was disposed', () => {
  it('sends nothing and arms nothing', async () => {
    receiptsApi.getReceipt.mockResolvedValue(ok(pendingReceipt()))
    const scope = effectScope()
    const state = scope.run(() => useReceipt(EXPENSE_ID))
    scope.stop()

    await state?.start()
    await settle()

    expect(getCalls()).toBe(0)
    expect(vi.getTimerCount()).toBe(0)
  })
})

describe('useReceipt: stop then start then stop', () => {
  it('leaves no ghost poller', async () => {
    receiptsApi.getReceipt.mockResolvedValue(ok(pendingReceipt()))
    const state = create()
    await state.start()
    state.stop()
    await state.start()
    await settle()
    expect(vi.getTimerCount()).toBe(1)

    state.stop()

    expect(vi.getTimerCount()).toBe(0)
    document.dispatchEvent(new Event('visibilitychange'))
    await advance(60_000)
    expect(getCalls()).toBe(2)
  })
})

describe('useReceipt: actions that must not overlap', () => {
  it('does not send a delete while the first read failed', async () => {
    const state = await begin(fail(NETWORK))
    expect(state.loadFailed.value).toBe(true)

    await state.remove()

    expect(receiptsApi.deleteReceipt).not.toHaveBeenCalled()
  })

  it('rejects a file while a remove is in flight, and accepts one after it ended', async () => {
    const running = deferred<ApiResult<void>>()
    receiptsApi.deleteReceipt.mockReturnValue(running.promise)
    const state = await begin(ok(readyReceipt()))
    void state.remove()
    await settle()

    expect(await state.choose(pngFile())).toBe('rejected')
    expect(state.pendingReplacement.value).toBeNull()
    expect(receiptsApi.createReceiptUpload).not.toHaveBeenCalled()

    running.resolve(ok(undefined, 204))
    await settle()
    expect(await state.choose(pngFile())).toBe('started')
  })

  it('accepts a file after a remove that a stop and start left unanswered', async () => {
    receiptsApi.deleteReceipt.mockReturnValue(new Promise(() => {}))
    const state = await begin(ok(readyReceipt()))
    void state.remove()
    await settle()
    receiptsApi.getReceipt.mockResolvedValueOnce(fail(NOT_FOUND))

    await state.start()
    await settle()

    expect(await state.choose(pngFile())).toBe('started')
  })
})
