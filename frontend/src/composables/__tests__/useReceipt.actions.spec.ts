import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { URL_REFRESH_MARGIN_MS } from '@/config'
import { describeExpenseError } from '@/lib/expenseMessages'
import { deleteErrorMessage } from '@/lib/receiptMessages'
import { useReceipt } from '../useReceipt'
import { deferred } from '@/api/__tests__/expenseFixtures'
import {
  EXPENSE_ID,
  NETWORK,
  NOT_FOUND,
  fail,
  ok,
  pendingReceipt,
  pngFile,
  readyReceipt,
  settle,
} from './receiptHarness'
import type { ApiResult } from '@/api/http'
import type { Receipt } from '@/api/types'

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

async function begin(first: ApiResult<Receipt>) {
  receiptsApi.getReceipt.mockResolvedValueOnce(first)
  const state = useReceipt(EXPENSE_ID)
  await state.start()
  await settle()
  return state
}

beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(NOW)
  Object.values(receiptsApi).forEach((mock) => mock.mockReset())
  uploadApi.uploadReceipt.mockReset()
})
afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

describe('useReceipt: remove', () => {
  it('deletes the receipt of the expense and goes to none', async () => {
    receiptsApi.deleteReceipt.mockResolvedValue(ok(undefined, 204))
    const state = await begin(ok(readyReceipt()))

    await state.remove()

    expect(receiptsApi.deleteReceipt.mock.calls[0]?.[0]).toBe(EXPENSE_ID)
    expect(state.phase.value).toBe('none')
    expect(state.receipt.value).toBeNull()
    expect(state.message.value).toBe('')
  })

  it('goes to none and says "Already removed." when the receipt was already gone', async () => {
    receiptsApi.deleteReceipt.mockResolvedValue(fail(NOT_FOUND))
    const state = await begin(ok(readyReceipt()))

    await state.remove()

    expect(state.phase.value).toBe('none')
    expect(state.receipt.value).toBeNull()
    expect(state.message.value).toBe('Already removed.')
  })

  it('keeps the phase and the receipt, and shows the text, when the delete fails', async () => {
    receiptsApi.deleteReceipt.mockResolvedValue(fail(NETWORK))
    const kept = readyReceipt()
    const state = await begin(ok(kept))

    await state.remove()

    expect(state.phase.value).toBe('ready')
    expect(state.receipt.value).toEqual(kept)
    expect(state.message.value).toBe(deleteErrorMessage(NETWORK))
  })

  it('ignores remove while an upload is on its way', async () => {
    receiptsApi.createReceiptUpload.mockReturnValue(new Promise(() => {}))
    const state = await begin(fail(NOT_FOUND))
    void state.choose(pngFile())
    await settle()

    await state.remove()

    expect(receiptsApi.deleteReceipt).not.toHaveBeenCalled()
    expect(state.phase.value).toBe('requesting')
  })

  it('changes nothing when the page stops before the delete is answered', async () => {
    const deletion = deferred<ApiResult<void>>()
    receiptsApi.deleteReceipt.mockReturnValue(deletion.promise)
    const state = await begin(ok(readyReceipt()))
    const removing = state.remove()

    state.stop()
    deletion.resolve(ok(undefined, 204))
    await removing

    expect(state.phase.value).toBe('ready')
    expect(state.receipt.value).not.toBeNull()
  })
})

describe('useReceipt: freshView', () => {
  it('returns null and calls nothing when there is no receipt', async () => {
    const state = await begin(fail(NOT_FOUND))

    expect(await state.freshView()).toBeNull()
    expect(receiptsApi.getReceipt).toHaveBeenCalledOnce()
  })

  it('returns the receipt as it is while its addresses are valid for more than the margin', async () => {
    const current = readyReceipt({ urlsExpireAt: expiresIn(URL_REFRESH_MARGIN_MS + 1) })
    const state = await begin(ok(current))

    const view = await state.freshView()

    expect(view).toBe(state.receipt.value)
    expect(view).toEqual(current)
    expect(receiptsApi.getReceipt).toHaveBeenCalledOnce()
  })

  it('returns a pending receipt, which has no addresses, as it is', async () => {
    const state = await begin(ok(pendingReceipt()))

    const view = await state.freshView()

    expect(view).toEqual(pendingReceipt())
    expect(receiptsApi.getReceipt).toHaveBeenCalledOnce()
  })

  it.each([
    ['exactly at the margin', URL_REFRESH_MARGIN_MS],
    ['inside the margin', 30_000],
    ['already past', -1_000],
  ])('fetches the receipt again when the addresses expire %s', async (_name, offset) => {
    const renewed = readyReceipt({
      viewUrl: 'https://bucket.example/view?sig=2',
      urlsExpireAt: expiresIn(300_000),
    })
    const state = await begin(ok(readyReceipt({ urlsExpireAt: expiresIn(offset) })))
    receiptsApi.getReceipt.mockResolvedValueOnce(ok(renewed))

    const view = await state.freshView()

    expect(receiptsApi.getReceipt).toHaveBeenCalledTimes(2)
    expect(view).toEqual(renewed)
    expect(state.receipt.value).toEqual(renewed)
  })

  it('returns null and shows the error text when the new read fails', async () => {
    const state = await begin(ok(readyReceipt({ urlsExpireAt: expiresIn(1_000) })))
    receiptsApi.getReceipt.mockResolvedValueOnce(fail(NETWORK))

    const view = await state.freshView()

    expect(view).toBeNull()
    expect(state.message.value).toBe(describeExpenseError(NETWORK, false).form)
  })

  it('returns null and changes nothing when the page stops before the new read is answered', async () => {
    const late = deferred<ApiResult<Receipt>>()
    const state = await begin(ok(readyReceipt({ urlsExpireAt: expiresIn(1_000) })))
    receiptsApi.getReceipt.mockReturnValueOnce(late.promise)
    const viewing = state.freshView()

    state.stop()
    late.resolve(ok(readyReceipt({ viewUrl: 'https://bucket.example/view?sig=3' })))

    expect(await viewing).toBeNull()
    expect(state.receipt.value?.viewUrl).toBe('https://bucket.example/view?sig=1')
  })
})
