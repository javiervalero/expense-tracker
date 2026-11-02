import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { deferred } from '@/api/__tests__/expenseFixtures'
import type { ApiResult } from '@/api/http'
import type { Receipt } from '@/api/types'
import { URL_REFRESH_MARGIN_MS } from '@/config'
import { deleteErrorMessage, LOAD_FIRST_TEXT, policyErrorMessage } from '@/lib/receiptMessages'
import { describeExpenseError } from '@/lib/expenseMessages'
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

// Review fixes of WP11: the terminal "stopped" state, the request slot of freshView, restoring the state before a failed
// action, and a failed first read.

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
function getCalls(): number {
  return receiptsApi.getReceipt.mock.calls.length
}
// After stop() nothing may be scheduled and the visibilitychange listener must be gone.
async function expectNothingRunning(getCallsBefore: number): Promise<void> {
  expect(vi.getTimerCount()).toBe(0)
  document.dispatchEvent(new Event('visibilitychange'))
  await advance(60_000)
  expect(getCalls()).toBe(getCallsBefore)
}

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
  vi.unstubAllGlobals()
})

describe('useReceipt: stop is terminal until start', () => {
  it('does nothing on checkAgain after stop: no request, no timer, no listener', async () => {
    receiptsApi.getReceipt.mockResolvedValue(ok(pendingReceipt()))
    const state = create()
    await state.start()
    await advance(70_000)
    expect(state.stalled.value).toBe(true)
    state.stop()
    const before = getCalls()

    await state.checkAgain()

    expect(getCalls()).toBe(before)
    await expectNothingRunning(before)
  })

  it.each([
    ['ready', ok(readyReceipt())],
    ['none', fail(NOT_FOUND)],
  ])('does nothing on checkAgain while the phase is %s', async (_phase, first) => {
    const state = await begin(first)

    await state.checkAgain()

    expect(getCalls()).toBe(1)
    await expectNothingRunning(1)
  })

  it('does nothing on checkAgain while polling has not given up', async () => {
    receiptsApi.getReceipt.mockResolvedValue(ok(pendingReceipt()))
    const state = create()
    await state.start()
    await settle()
    const before = getCalls()

    await state.checkAgain()

    expect(getCalls()).toBe(before)
  })

  it('refuses choose, and does nothing on confirmReplace, remove and freshView, after stop', async () => {
    const state = await begin(ok(SOON))
    expect(await state.choose(pdfFile())).toBe('needs-confirmation')
    state.stop()

    expect(await state.choose(pngFile())).toBe('rejected')
    await state.confirmReplace()
    await state.remove()
    expect(await state.freshView()).toBeNull()

    expect(getCalls()).toBe(1)
    expect(receiptsApi.createReceiptUpload).not.toHaveBeenCalled()
    expect(receiptsApi.deleteReceipt).not.toHaveBeenCalled()
    expect(state.phase.value).toBe('ready')
    await expectNothingRunning(1)
  })

  it('does nothing on retry after stop', async () => {
    uploadApi.uploadReceipt.mockResolvedValueOnce({ ok: false, error: { kind: 'expired' } })
    const state = await begin(fail(NOT_FOUND))
    await state.choose(pngFile())
    await settle()
    state.stop()

    await state.retry()
    await settle()

    expect(receiptsApi.createReceiptUpload).toHaveBeenCalledOnce()
    await expectNothingRunning(1)
  })

  it('works again after start()', async () => {
    const state = await begin(ok(readyReceipt()))
    state.stop()
    receiptsApi.getReceipt.mockResolvedValueOnce(fail(NOT_FOUND))

    await state.start()
    await settle()

    expect(state.phase.value).toBe('none')
    expect(await state.choose(pngFile())).toBe('started')
    await settle()
    expect(receiptsApi.createReceiptUpload).toHaveBeenCalledOnce()
  })
})

describe('useReceipt: freshView takes its own request slot', () => {
  it('lets the later-started read win and drops the earlier one, which is aborted', async () => {
    const first = deferred<ApiResult<Receipt>>()
    const second = deferred<ApiResult<Receipt>>()
    const newer = readyReceipt({ viewUrl: 'https://bucket.example/view?sig=new' })
    const older = readyReceipt({ viewUrl: 'https://bucket.example/view?sig=old' })
    const state = await begin(ok(SOON))
    receiptsApi.getReceipt.mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise)

    const early = state.freshView()
    const late = state.freshView()
    second.resolve(ok(newer))
    first.resolve(ok(older))

    expect(await late).toEqual(newer)
    expect(await early).toBeNull()
    expect(state.receipt.value).toEqual(newer)
    expect((receiptsApi.getReceipt.mock.calls[1]?.[1] as AbortSignal).aborted).toBe(true)
    expect((receiptsApi.getReceipt.mock.calls[2]?.[1] as AbortSignal).aborted).toBe(false)
  })

  it('drops the answer of a freshView that a later start() superseded', async () => {
    const late = deferred<ApiResult<Receipt>>()
    const state = await begin(ok(SOON))
    receiptsApi.getReceipt
      .mockReturnValueOnce(late.promise)
      .mockResolvedValueOnce(ok(pendingReceipt()))
    const viewing = state.freshView()

    await state.start()
    late.resolve(ok(readyReceipt()))

    expect(await viewing).toBeNull()
    expect(state.phase.value).toBe('processing')
  })

  it('returns the current receipt without a read, and cancels nothing, while an upload is on its way', async () => {
    receiptsApi.createReceiptUpload.mockReturnValue(new Promise(() => {}))
    const state = await begin(ok(SOON))
    await state.choose(pngFile())
    await state.confirmReplace()
    await settle()

    const view = await state.freshView()

    expect(view).toBe(state.receipt.value)
    expect(getCalls()).toBe(1)
    expect(state.phase.value).toBe('requesting')
  })

  it('returns the current receipt without a read, and lets the remove finish, while a remove is on its way', async () => {
    const running = deferred<ApiResult<void>>()
    receiptsApi.deleteReceipt.mockReturnValue(running.promise)
    const state = await begin(ok(SOON))
    void state.remove()
    await settle()

    const view = await state.freshView()
    running.resolve(ok(undefined, 204))
    await settle()

    expect(view).toBe(SOON)
    expect(getCalls()).toBe(1)
    expect(state.phase.value).toBe('none')
  })
})

describe('useReceipt: a failed action restores the state it began from', () => {
  async function uploaded(): Promise<ReceiptState> {
    const state = await begin(fail(NOT_FOUND))
    receiptsApi.getReceipt.mockResolvedValue(ok(pendingReceipt()))
    await state.choose(pngFile())
    await settle()
    expect([state.phase.value, state.receipt.value]).toEqual(['processing', null])
    return state
  }

  it('stays in processing, and polls again, when a remove fails right after an upload', async () => {
    const state = await uploaded()
    receiptsApi.deleteReceipt.mockResolvedValue(fail(NETWORK))

    await state.remove()

    expect(state.phase.value).toBe('processing')
    expect(state.receipt.value).toBeNull()
    expect(state.message.value).toBe(deleteErrorMessage(NETWORK))
    const before = getCalls()
    await advance(1_000)
    expect(getCalls()).toBe(before + 1)
  })

  it('stays in processing, and polls again, when a replace is refused right after an upload', async () => {
    const state = await uploaded()
    expect(await state.choose(pdfFile())).toBe('needs-confirmation')
    receiptsApi.createReceiptUpload.mockResolvedValue(fail(NETWORK))

    await state.confirmReplace()
    await settle()

    expect(state.phase.value).toBe('processing')
    expect(state.message.value).toBe(policyErrorMessage(NETWORK))
    const before = getCalls()
    await advance(1_000)
    expect(getCalls()).toBe(before + 1)
  })

  it('goes to none, the old receipt being gone, when the replacing upload itself fails right after an upload', async () => {
    const state = await uploaded()
    await state.choose(pdfFile())
    uploadApi.uploadReceipt.mockResolvedValue({ ok: false, error: { kind: 'expired' } })

    await state.confirmReplace()
    await settle()

    expect(state.phase.value).toBe('none')
    expect(state.receipt.value).toBeNull()
    expect(state.message.value).toBe('The upload link expired. Try again.')
  })

  it('returns to the ready receipt when a replace is refused', async () => {
    const kept = readyReceipt()
    const state = await begin(ok(kept))
    await state.choose(pdfFile())
    receiptsApi.createReceiptUpload.mockResolvedValue(fail(NETWORK))

    await state.confirmReplace()
    await settle()

    expect(state.phase.value).toBe('ready')
    expect(state.receipt.value).toBe(kept)
    expect(vi.getTimerCount()).toBe(0)
  })
})

describe('useReceipt: remove passes the abort signal', () => {
  it('sends the signal of its request slot, and aborts it on stop', async () => {
    const running = deferred<ApiResult<void>>()
    receiptsApi.deleteReceipt.mockReturnValue(running.promise)
    const state = await begin(ok(readyReceipt()))
    void state.remove()
    await settle()
    const signal = receiptsApi.deleteReceipt.mock.calls[0]?.[1] as AbortSignal
    expect(signal).toBeInstanceOf(AbortSignal)
    expect(signal.aborted).toBe(false)

    state.stop()

    expect(signal.aborted).toBe(true)
  })
})

describe('useReceipt: a failed first read', () => {
  it('rejects a file while the receipt is still loading, with the load-first text', async () => {
    receiptsApi.getReceipt.mockReturnValueOnce(new Promise(() => {}))
    const state = create()
    void state.start()

    const outcome = await state.choose(pngFile())

    expect(outcome).toBe('rejected')
    expect(state.fileMessage.value).toBe(LOAD_FIRST_TEXT)
    expect(receiptsApi.createReceiptUpload).not.toHaveBeenCalled()
  })

  it('starts with loadFailed false', () => {
    expect(useReceipt(EXPENSE_ID).loadFailed.value).toBe(false)
  })

  it('keeps the phase none, sets loadFailed and rejects a file after a failed read', async () => {
    const state = await begin(fail(NETWORK))

    expect(state.phase.value).toBe('none')
    expect(state.loadFailed.value).toBe(true)
    expect(state.message.value).toBe(describeExpenseError(NETWORK, false).form)
    expect(await state.choose(pngFile())).toBe('rejected')
    expect(state.fileMessage.value).toBe(LOAD_FIRST_TEXT)
    expect(receiptsApi.createReceiptUpload).not.toHaveBeenCalled()
  })

  it('clears loadFailed at the beginning of start() and after a successful retry', async () => {
    const state = await begin(fail(NETWORK))
    const retry = deferred<ApiResult<Receipt>>()
    receiptsApi.getReceipt.mockReturnValueOnce(retry.promise)

    const starting = state.start()
    expect(state.loadFailed.value).toBe(false)
    expect(state.message.value).toBe('')
    retry.resolve(fail(NOT_FOUND))
    await starting

    expect(state.loadFailed.value).toBe(false)
    expect(state.phase.value).toBe('none')
    expect(await state.choose(pngFile())).toBe('started')
    expect(state.fileMessage.value).toBe('')
  })

  it('keeps loadFailed false after a 404', async () => {
    const state = await begin(fail(NOT_FOUND))

    expect(state.loadFailed.value).toBe(false)
  })
})

describe('useReceipt: start() drops the state of a previous run', () => {
  it('resets stalled and the alert text', async () => {
    receiptsApi.getReceipt.mockResolvedValue(ok(pendingReceipt()))
    const state = create()
    await state.start()
    await advance(70_000)
    expect(state.stalled.value).toBe(true)
    receiptsApi.getReceipt.mockResolvedValue(fail(NOT_FOUND))

    await state.start()

    expect(state.stalled.value).toBe(false)
    expect(state.message.value).toBe('')
  })

  it('resets the text under the file input and the file waiting for confirmation', async () => {
    const state = await begin(ok(readyReceipt()))
    await state.choose(pdfFile())
    await state.choose(new File(['x'], 'a.txt', { type: 'text/plain' }))
    expect(state.pendingReplacement.value).not.toBeNull()
    expect(state.fileMessage.value).not.toBe('')
    receiptsApi.getReceipt.mockResolvedValueOnce(ok(readyReceipt()))

    await state.start()

    expect(state.pendingReplacement.value).toBeNull()
    expect(state.fileMessage.value).toBe('')
  })
})

describe('useReceipt: public state is read-only', () => {
  it('does not let the page assign to the state', () => {
    const state = useReceipt(EXPENSE_ID)

    // @ts-expect-error the page may read the phase, never set it
    const assign = () => (state.phase.value = 'none')
    // @ts-expect-error the page may read the receipt, never set it
    const assignReceipt = () => (state.receipt.value = null)

    expect([typeof assign, typeof assignReceipt]).toEqual(['function', 'function'])
    expect(URL_REFRESH_MARGIN_MS).toBeGreaterThan(0)
  })
})

describe('useReceipt: actions that must not overlap', () => {
  it('ignores retry while an upload is on its way', async () => {
    uploadApi.uploadReceipt.mockResolvedValueOnce({ ok: false, error: { kind: 'expired' } })
    const state = await begin(fail(NOT_FOUND))
    await state.choose(pngFile())
    await settle()
    receiptsApi.createReceiptUpload.mockReturnValue(new Promise(() => {}))
    await state.retry()
    await settle()
    expect(state.phase.value).toBe('requesting')

    await state.retry()
    await settle()

    expect(receiptsApi.createReceiptUpload).toHaveBeenCalledTimes(2)
    expect(state.phase.value).toBe('requesting')
  })

  it('ignores remove while the receipt is still loading', async () => {
    receiptsApi.getReceipt.mockReturnValueOnce(new Promise(() => {}))
    const state = create()
    void state.start()

    await state.remove()

    expect(receiptsApi.deleteReceipt).not.toHaveBeenCalled()
    expect(state.phase.value).toBe('loading')
  })
})
