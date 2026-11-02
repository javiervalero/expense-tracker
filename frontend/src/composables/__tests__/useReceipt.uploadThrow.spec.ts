import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { uploadErrorMessage } from '@/lib/receiptMessages'
import { useReceipt } from '../useReceipt'
import { EXPENSE_ID, NOT_FOUND, fail, pngFile, settle } from './receiptHarness'

// receiptUpload promises never to throw. If it does anyway, the page ends in a defined state instead of an unhandled
// rejection.

const receiptsApi = vi.hoisted(() => ({ getReceipt: vi.fn() }))
const uploadRun = vi.hoisted(() => ({ runReceiptUpload: vi.fn() }))
vi.mock('@/api/receipts', () => receiptsApi)
vi.mock('@/composables/receiptUpload', () => uploadRun)

beforeEach(() => {
  vi.useFakeTimers()
  receiptsApi.getReceipt.mockReset().mockResolvedValue(fail(NOT_FOUND))
  uploadRun.runReceiptUpload.mockReset()
})
afterEach(() => {
  vi.useRealTimers()
})

describe('useReceipt: an upload run that throws', () => {
  it('ends in none with the interrupted text, and keeps the file for retry', async () => {
    uploadRun.runReceiptUpload.mockRejectedValueOnce(new Error('boom'))
    const state = useReceipt(EXPENSE_ID)
    await state.start()

    await state.choose(pngFile())
    await settle()

    expect(state.phase.value).toBe('none')
    expect(state.message.value).toBe(uploadErrorMessage({ kind: 'interrupted' }))
    uploadRun.runReceiptUpload.mockResolvedValueOnce({ kind: 'stale' })
    await state.retry()
    expect(uploadRun.runReceiptUpload).toHaveBeenCalledTimes(2)
    state.stop()
  })

  it('stays silent when the run throws after the page stopped', async () => {
    let rejectRun: (error: Error) => void = () => {}
    uploadRun.runReceiptUpload.mockReturnValueOnce(
      new Promise((_resolve, reject) => (rejectRun = reject)),
    )
    const state = useReceipt(EXPENSE_ID)
    await state.start()
    await state.choose(pngFile())
    await settle()

    state.stop()
    rejectRun(new Error('late'))
    await settle()

    expect([state.message.value, state.fileMessage.value]).toEqual(['', ''])
  })
})
