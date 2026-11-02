import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ref, watch } from 'vue'

import { deferred } from '@/api/__tests__/expenseFixtures'
import type { ApiResult } from '@/api/http'
import type { ApiError } from '@/api/problem'
import type { Receipt } from '@/api/types'
import type { UploadResult } from '@/api/upload'
import { describeExpenseError } from '@/lib/expenseMessages'
import { policyErrorMessage, preCheckMessage, uploadErrorMessage } from '@/lib/receiptMessages'
import { useReceipt } from '../useReceipt'
import {
  EXPENSE_ID,
  NETWORK,
  NOT_FOUND,
  fail,
  failedReceipt,
  ok,
  pdfFile,
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

const UPLOAD_OK: UploadResult = { ok: true }

async function begin(first: ApiResult<Receipt>) {
  receiptsApi.getReceipt.mockResolvedValueOnce(first)
  const state = useReceipt(EXPENSE_ID)
  await state.start()
  await settle()
  return state
}
const withoutReceipt = () => begin(fail(NOT_FOUND))

beforeEach(() => {
  vi.useFakeTimers()
  Object.values(receiptsApi).forEach((mock) => mock.mockReset())
  uploadApi.uploadReceipt.mockReset()
  receiptsApi.createReceiptUpload.mockResolvedValue(policy())
  uploadApi.uploadReceipt.mockResolvedValue(UPLOAD_OK)
})
afterEach(() => {
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

describe('useReceipt: start', () => {
  it('begins in the loading phase with nothing to show', () => {
    const state = useReceipt(EXPENSE_ID)

    expect(state.phase.value).toBe('loading')
    expect(state.receipt.value).toBeNull()
    expect(state.hasReceipt.value).toBe(false)
    expect(state.progress.value).toBe(0)
    expect([state.message.value, state.fileMessage.value, state.announcement.value]).toEqual([
      '',
      '',
      '',
    ])
  })

  it('reads the receipt of the expense with an abort signal', async () => {
    await withoutReceipt()

    expect(receiptsApi.getReceipt).toHaveBeenCalledOnce()
    expect(receiptsApi.getReceipt.mock.calls[0]?.[0]).toBe(EXPENSE_ID)
    expect(receiptsApi.getReceipt.mock.calls[0]?.[1]).toBeInstanceOf(AbortSignal)
  })

  it('reads the current value of a ref or getter id', async () => {
    receiptsApi.getReceipt.mockResolvedValue(fail(NOT_FOUND))
    const id = ref('first')

    await useReceipt(() => id.value).start()

    expect(receiptsApi.getReceipt.mock.calls[0]?.[0]).toBe('first')
  })

  it('goes to none, with no message, on a 404', async () => {
    const state = await withoutReceipt()

    expect(state.phase.value).toBe('none')
    expect(state.hasReceipt.value).toBe(false)
    expect(state.message.value).toBe('')
  })

  it.each([
    ['pending', pendingReceipt(), 'processing'],
    ['ready', readyReceipt(), 'ready'],
    ['failed', failedReceipt(), 'failed'],
  ] as const)('goes from a %s receipt to %s and keeps the receipt', async (_s, found, phase) => {
    const state = await begin(ok(found))

    expect(state.phase.value).toBe(phase)
    expect(state.receipt.value).toEqual(found)
    expect(state.hasReceipt.value).toBe(true)
  })

  it('shows no failure text for a failed receipt that names no reason', async () => {
    const state = await begin(ok({ ...failedReceipt(), failureReason: null }))

    expect(state.phase.value).toBe('failed')
    expect(state.message.value).toBe('')
  })

  it('shows nothing from a first read that is answered after stop', async () => {
    const late = deferred<ApiResult<Receipt>>()
    receiptsApi.getReceipt.mockReturnValueOnce(late.promise)
    const state = useReceipt(EXPENSE_ID)
    const starting = state.start()

    state.stop()
    late.resolve(ok(readyReceipt()))
    await starting

    expect(state.phase.value).toBe('loading')
    expect(state.receipt.value).toBeNull()
  })

  it('goes to none with the Phase 4 text on any other error, and refuses a file until start() works', async () => {
    const state = await begin(fail(NETWORK))

    expect(state.phase.value).toBe('none')
    expect(state.message.value).toBe(describeExpenseError(NETWORK, false).form)
    expect(await state.choose(pngFile())).toBe('rejected')
  })
})

describe('useReceipt: choose, pre-checks', () => {
  it.each([
    ['a type that is not accepted', new File(['abcd'], 'a.txt', { type: 'text/plain' }), 'type'],
    ['an empty file', new File([], 'a.png', { type: 'image/png' }), 'empty'],
  ] as const)('rejects %s without any request', async (_name, file, reason) => {
    const state = await withoutReceipt()

    const outcome = await state.choose(file)

    expect(outcome).toBe('rejected')
    expect(state.fileMessage.value).toBe(preCheckMessage(reason))
    expect(receiptsApi.createReceiptUpload).not.toHaveBeenCalled()
    expect(state.phase.value).toBe('none')
  })

  it('rejects a second file while the policy request is in flight', async () => {
    const held = deferred<ApiResult<unknown>>()
    receiptsApi.createReceiptUpload.mockReturnValue(held.promise)
    const state = await withoutReceipt()
    void state.choose(pngFile())
    await settle()

    const outcome = await state.choose(pdfFile())

    expect(state.phase.value).toBe('requesting')
    expect(outcome).toBe('rejected')
    expect(receiptsApi.createReceiptUpload).toHaveBeenCalledOnce()
  })

  it('rejects a second file while an upload is running', async () => {
    uploadApi.uploadReceipt.mockReturnValue(new Promise(() => {}))
    const state = await withoutReceipt()
    void state.choose(pngFile())
    await settle()

    const outcome = await state.choose(pdfFile())

    expect(state.phase.value).toBe('uploading')
    expect(outcome).toBe('rejected')
    expect(receiptsApi.createReceiptUpload).toHaveBeenCalledOnce()
  })
})

describe('useReceipt: choose, upload pipeline', () => {
  it('requests the policy and uploads using the type and size of the same file', async () => {
    const state = await withoutReceipt()
    const file = pdfFile()

    const outcome = await state.choose(file)
    await settle()

    expect(outcome).toBe('started')
    expect(receiptsApi.createReceiptUpload).toHaveBeenCalledWith(
      EXPENSE_ID,
      { contentType: 'application/pdf', size: file.size },
      expect.any(AbortSignal),
    )
    const [target, uploaded, options] = uploadApi.uploadReceipt.mock.calls[0] ?? []
    expect(target).toEqual({
      url: 'https://bucket.example/upload',
      fields: { key: 'receipts/k', Policy: 'p' },
    })
    expect(uploaded).toBe(file)
    expect(options.signal).toBeInstanceOf(AbortSignal)
  })

  it('moves requesting, uploading, processing, follows the progress and ends at 100', async () => {
    const policyAnswer = deferred<ApiResult<unknown>>()
    const uploadAnswer = deferred<UploadResult>()
    let report: (percent: number) => void = () => {}
    receiptsApi.createReceiptUpload.mockReturnValue(policyAnswer.promise)
    uploadApi.uploadReceipt.mockImplementation((_t, _f, options) => {
      report = options.onProgress
      return uploadAnswer.promise
    })
    const state = await withoutReceipt()

    const pending = state.choose(pngFile())
    await settle()
    expect(state.phase.value).toBe('requesting')
    policyAnswer.resolve(policy())
    await settle()
    expect(state.phase.value).toBe('uploading')
    report(45)
    expect(state.progress.value).toBe(45)
    uploadAnswer.resolve(UPLOAD_OK)
    await pending
    await settle()

    expect(state.phase.value).toBe('processing')
    expect(state.progress.value).toBe(100)
    expect(state.hasReceipt.value).toBe(true)
  })

  it('shows a validation refusal under the file input and not in the alert', async () => {
    const refusal: ApiError = {
      kind: 'validation',
      status: 422,
      fieldErrors: { size: 'Too big for this expense.' },
      formErrors: [],
    }
    receiptsApi.createReceiptUpload.mockResolvedValue(fail(refusal))
    const state = await withoutReceipt()

    await state.choose(pngFile())
    await settle()

    expect(state.fileMessage.value).toBe(policyErrorMessage(refusal))
    expect(state.message.value).toBe('')
    expect(state.phase.value).toBe('none')
    expect(uploadApi.uploadReceipt).not.toHaveBeenCalled()
  })

  it('shows every other policy failure in the alert and returns to none', async () => {
    receiptsApi.createReceiptUpload.mockResolvedValue(
      fail({ kind: 'conflict', status: 409, detail: null }),
    )
    const state = await withoutReceipt()

    await state.choose(pngFile())
    await settle()

    expect(state.message.value).toBe('The receipt changed in another tab. Reload.')
    expect(state.fileMessage.value).toBe('')
    expect(state.phase.value).toBe('none')
  })

  it.each([
    ['expired', 'The upload link expired. Try again.'],
    ['interrupted', 'Upload interrupted. Try again.'],
    ['size', uploadErrorMessage({ kind: 'size' })],
  ] as const)('shows a %s upload in the alert and returns to none', async (kind, text) => {
    uploadApi.uploadReceipt.mockResolvedValue({ ok: false, error: { kind } })
    const state = await withoutReceipt()

    await state.choose(pngFile())
    await settle()

    expect(state.message.value).toBe(text)
    expect(state.phase.value).toBe('none')
  })

  it('stays silent when the upload was aborted', async () => {
    uploadApi.uploadReceipt.mockResolvedValue({ ok: false, error: { kind: 'aborted' } })
    const state = await withoutReceipt()

    await state.choose(pngFile())
    await settle()

    expect([state.message.value, state.fileMessage.value]).toEqual(['', ''])
    expect(state.phase.value).toBe('none')
  })
})

describe('useReceipt: retry', () => {
  it('asks for a NEW policy for the same held file after an expired upload', async () => {
    uploadApi.uploadReceipt.mockResolvedValueOnce({ ok: false, error: { kind: 'expired' } })
    const state = await withoutReceipt()
    const file = pngFile()
    await state.choose(file)
    await settle()

    await state.retry()
    await settle()

    expect(receiptsApi.createReceiptUpload).toHaveBeenCalledTimes(2)
    expect(receiptsApi.createReceiptUpload.mock.calls[1]?.[1]).toEqual({
      contentType: 'image/png',
      size: file.size,
    })
    expect(uploadApi.uploadReceipt).toHaveBeenCalledTimes(2)
    expect(uploadApi.uploadReceipt.mock.calls[1]?.[1]).toBe(file)
    expect(state.phase.value).toBe('processing')
    expect(state.message.value).toBe('')
  })

  it('does nothing when no file is held', async () => {
    const state = await withoutReceipt()

    await state.retry()

    expect(receiptsApi.createReceiptUpload).not.toHaveBeenCalled()
    expect(state.phase.value).toBe('none')
  })
})

describe('useReceipt: replace', () => {
  it('asks for confirmation when a receipt exists and calls nothing yet', async () => {
    const state = await begin(ok(readyReceipt()))
    const file = pngFile()

    const outcome = await state.choose(file)

    expect(outcome).toBe('needs-confirmation')
    expect(state.pendingReplacement.value).toBe(file)
    expect(state.phase.value).toBe('ready')
    expect(receiptsApi.createReceiptUpload).not.toHaveBeenCalled()
  })

  it('runs the upload after confirmReplace and clears the pending file', async () => {
    const state = await begin(ok(readyReceipt()))
    const file = pdfFile()
    await state.choose(file)

    await state.confirmReplace()
    await settle()

    expect(receiptsApi.createReceiptUpload.mock.calls[0]?.[1]).toEqual({
      contentType: 'application/pdf',
      size: file.size,
    })
    expect(uploadApi.uploadReceipt.mock.calls[0]?.[1]).toBe(file)
    expect(state.pendingReplacement.value).toBeNull()
    expect(state.phase.value).toBe('processing')
  })

  it('drops the pending file on cancelReplace without any call', async () => {
    const state = await begin(ok(readyReceipt()))
    await state.choose(pngFile())

    state.cancelReplace()

    expect(state.pendingReplacement.value).toBeNull()
    expect(state.phase.value).toBe('ready')
    expect(receiptsApi.createReceiptUpload).not.toHaveBeenCalled()
  })

  it('does nothing on confirmReplace when there is no pending file', async () => {
    const state = await begin(ok(readyReceipt()))

    await state.confirmReplace()

    expect(receiptsApi.createReceiptUpload).not.toHaveBeenCalled()
    expect(state.phase.value).toBe('ready')
  })

  it('returns to the old receipt phase and keeps it when the new policy is refused', async () => {
    const old = readyReceipt()
    receiptsApi.createReceiptUpload.mockResolvedValue(fail(NETWORK))
    const state = await begin(ok(old))
    await state.choose(pngFile())

    await state.confirmReplace()
    await settle()

    expect(state.phase.value).toBe('ready')
    expect(state.receipt.value).toEqual(old)
    expect(state.message.value).toBe(policyErrorMessage(NETWORK))
  })
})

describe('useReceipt: announcements', () => {
  function recordAnnouncements(state: ReturnType<typeof useReceipt>): string[] {
    const seen: string[] = []
    watch(state.announcement, (text) => seen.push(text), { flush: 'sync' })
    return seen
  }

  it('announces only the milestones, never the progress, up to ready', async () => {
    receiptsApi.getReceipt
      .mockResolvedValueOnce(fail(NOT_FOUND))
      .mockResolvedValue(ok(readyReceipt()))
    uploadApi.uploadReceipt.mockImplementation(async (_t, _f, options) => {
      ;[10, 50, 90].forEach((percent) => options.onProgress(percent))
      return UPLOAD_OK
    })
    const state = useReceipt(EXPENSE_ID)
    const seen = recordAnnouncements(state)
    await state.start()

    await state.choose(pngFile())
    await settle()
    await vi.advanceTimersByTimeAsync(1_000)
    await settle()

    expect(seen).toEqual(['Uploading receipt', 'Receipt uploaded, processing', 'Receipt ready'])
  })

  it('announces that the receipt could not be processed when the check fails', async () => {
    receiptsApi.getReceipt
      .mockResolvedValueOnce(fail(NOT_FOUND))
      .mockResolvedValue(ok(failedReceipt()))
    const state = useReceipt(EXPENSE_ID)
    const seen = recordAnnouncements(state)
    await state.start()
    await state.choose(pngFile())
    await settle()

    await vi.advanceTimersByTimeAsync(1_000)
    await settle()

    expect(seen).toEqual([
      'Uploading receipt',
      'Receipt uploaded, processing',
      'Receipt could not be processed',
    ])
  })
})
