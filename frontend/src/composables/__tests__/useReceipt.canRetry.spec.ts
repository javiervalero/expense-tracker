import { effectScope } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { ApiResult } from '@/api/http'
import type { Receipt } from '@/api/types'
import { useReceipt } from '../useReceipt'
import type { ReceiptState } from '../useReceipt'
import {
  EXPENSE_ID,
  NOT_FOUND,
  fail,
  ok,
  pendingReceipt,
  pngFile,
  policy,
  readyReceipt,
  settle,
} from './receiptHarness'

// `canRetry`: true only while the phase is none, a file is held from a failed upload (retry() would act) and the
// composable is neither stopped nor disposed. The panel shows its Retry button from it.

const receiptsApi = vi.hoisted(() => ({
  getReceipt: vi.fn(),
  createReceiptUpload: vi.fn(),
  deleteReceipt: vi.fn(),
}))
const uploadApi = vi.hoisted(() => ({ uploadReceipt: vi.fn() }))
vi.mock('@/api/receipts', () => receiptsApi)
vi.mock('@/api/upload', () => uploadApi)

const EXPIRED = { ok: false, error: { kind: 'expired' } }
const created: ReceiptState[] = []

beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(new Date('2026-11-01T12:00:00Z'))
  Object.values(receiptsApi).forEach((mock) => mock.mockReset())
  uploadApi.uploadReceipt.mockReset()
  receiptsApi.createReceiptUpload.mockResolvedValue(policy())
  receiptsApi.deleteReceipt.mockResolvedValue(ok(undefined, 204))
})
afterEach(() => {
  created.splice(0).forEach((state) => state.stop())
  vi.useRealTimers()
})

async function begin(first: ApiResult<Receipt>): Promise<ReceiptState> {
  receiptsApi.getReceipt.mockResolvedValueOnce(first)
  const state = useReceipt(EXPENSE_ID)
  created.push(state)
  await state.start()
  await settle()
  return state
}
async function failedUpload(): Promise<ReceiptState> {
  uploadApi.uploadReceipt.mockResolvedValueOnce(EXPIRED)
  const state = await begin(fail(NOT_FOUND))
  await state.choose(pngFile())
  await settle()
  return state
}

describe('useReceipt: canRetry is false', () => {
  it('before start() and while the first read is pending', () => {
    receiptsApi.getReceipt.mockReturnValue(new Promise(() => {}))
    const state = useReceipt(EXPENSE_ID)
    created.push(state)
    expect(state.canRetry.value).toBe(false)

    void state.start()

    expect(state.phase.value).toBe('loading')
    expect(state.canRetry.value).toBe(false)
  })

  it.each([
    ['no receipt', fail(NOT_FOUND)],
    ['a ready receipt', ok(readyReceipt())],
    ['a pending receipt', ok(pendingReceipt())],
  ])('after a read that found %s', async (_name, first) => {
    const state = await begin(first)

    expect(state.canRetry.value).toBe(false)
  })

  it('while an upload is on its way', async () => {
    uploadApi.uploadReceipt.mockReturnValue(new Promise(() => {}))
    const state = await begin(fail(NOT_FOUND))

    await state.choose(pngFile())
    await settle()

    expect(state.phase.value).toBe('uploading')
    expect(state.canRetry.value).toBe(false)
  })

  it('after a successful upload', async () => {
    uploadApi.uploadReceipt.mockResolvedValue({ ok: true })
    receiptsApi.getReceipt.mockResolvedValue(ok(pendingReceipt()))
    const state = await begin(fail(NOT_FOUND))

    await state.choose(pngFile())
    await settle()

    expect(state.phase.value).toBe('processing')
    expect(state.canRetry.value).toBe(false)
  })

  it('when a refused policy put a ready receipt back', async () => {
    receiptsApi.createReceiptUpload.mockResolvedValue(
      fail({ kind: 'conflict', status: 409, detail: 'x' }),
    )
    const state = await begin(ok(readyReceipt()))
    await state.choose(pngFile())
    await state.confirmReplace()
    await settle()

    expect(state.phase.value).toBe('ready')
    expect(state.canRetry.value).toBe(false)
  })

  it('when a refused policy leaves no earlier receipt (the refused file is not retried)', async () => {
    receiptsApi.createReceiptUpload.mockResolvedValue(
      fail({ kind: 'validation', status: 422, fieldErrors: { size: 'Too big.' }, formErrors: [] }),
    )
    const state = await begin(fail(NOT_FOUND))
    await state.choose(pngFile())
    await settle()

    expect(state.phase.value).toBe('none')
    expect(state.fileMessage.value).toBe('Too big.')
    expect(state.canRetry.value).toBe(false)
    await state.retry()
    await settle()
    expect(receiptsApi.createReceiptUpload).toHaveBeenCalledOnce()
  })

  it('after a successful Retry', async () => {
    const state = await failedUpload()
    uploadApi.uploadReceipt.mockResolvedValueOnce({ ok: true })
    receiptsApi.getReceipt.mockResolvedValue(ok(pendingReceipt()))

    await state.retry()
    await settle()

    expect(state.canRetry.value).toBe(false)
  })

  it('after remove()', async () => {
    const state = await failedUpload()
    expect(state.canRetry.value).toBe(true)

    await state.remove()
    await settle()

    expect(state.canRetry.value).toBe(false)
    await state.retry()
    await settle()
    expect(receiptsApi.createReceiptUpload).toHaveBeenCalledOnce()
  })

  it('after start() has read again', async () => {
    const state = await failedUpload()
    receiptsApi.getReceipt.mockResolvedValueOnce(fail(NOT_FOUND))

    await state.start()
    await settle()

    expect(state.phase.value).toBe('none')
    expect(state.canRetry.value).toBe(false)
    await state.retry()
    await settle()
    expect(receiptsApi.createReceiptUpload).toHaveBeenCalledOnce()
  })

  it('after stop()', async () => {
    const state = await failedUpload()
    expect(state.canRetry.value).toBe(true)

    state.stop()

    expect(state.canRetry.value).toBe(false)
  })

  it('after start() following a stop(), while it reads', async () => {
    const state = await failedUpload()
    state.stop()
    receiptsApi.getReceipt.mockReturnValue(new Promise(() => {}))

    void state.start()

    expect(state.canRetry.value).toBe(false)
  })

  it('when the owning scope is disposed', async () => {
    const scope = effectScope()
    uploadApi.uploadReceipt.mockResolvedValueOnce(EXPIRED)
    receiptsApi.getReceipt.mockResolvedValueOnce(fail(NOT_FOUND))
    const state = scope.run(() => useReceipt(EXPENSE_ID)) as ReceiptState
    await state.start()
    await settle()
    await state.choose(pngFile())
    await settle()
    expect(state.canRetry.value).toBe(true)

    scope.stop()

    expect(state.canRetry.value).toBe(false)
  })
})

describe('useReceipt: canRetry is true', () => {
  it.each([
    ['expired', EXPIRED],
    ['interrupted', { ok: false, error: { kind: 'network' } }],
  ])('after the bucket upload failed (%s)', async (_name, result) => {
    uploadApi.uploadReceipt.mockResolvedValueOnce(result)
    const state = await begin(fail(NOT_FOUND))

    await state.choose(pngFile())
    await settle()

    expect(state.phase.value).toBe('none')
    expect(state.canRetry.value).toBe(true)
  })

  it('after a failed upload over an old receipt (which the server already replaced)', async () => {
    uploadApi.uploadReceipt.mockResolvedValueOnce(EXPIRED)
    const state = await begin(ok(readyReceipt()))
    await state.choose(pngFile())
    await state.confirmReplace()
    await settle()

    expect(state.phase.value).toBe('none')
    expect(state.canRetry.value).toBe(true)
  })

  it('again after a second failure of Retry, and Retry asks for a new policy each time', async () => {
    const state = await failedUpload()
    uploadApi.uploadReceipt.mockResolvedValueOnce(EXPIRED)

    await state.retry()
    await settle()

    expect(state.canRetry.value).toBe(true)
    expect(receiptsApi.createReceiptUpload).toHaveBeenCalledTimes(2)
  })
})
