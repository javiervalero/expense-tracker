import type { ApiResult } from '@/api/http'
import type { ApiError } from '@/api/problem'
import type { Receipt, ReceiptUpload } from '@/api/types'

// Hand-written fixtures shared by the useReceipt specs. The vi.mock calls stay in each spec (they must be hoisted there).

export const EXPENSE_ID = '5a1c1f0e-5d3a-4c8e-9a41-2f6d8e7b1a91'

export function receipt(overrides: Partial<Receipt> = {}): Receipt {
  return {
    receiptId: '9b1c1f0e-5d3a-4c8e-9a41-2f6d8e7b1a11',
    status: 'pending',
    contentType: 'image/png',
    size: 4,
    createdAt: '2026-11-01T10:00:00Z',
    failureReason: null,
    viewUrl: null,
    thumbnailUrl: null,
    urlsExpireAt: null,
    ...overrides,
  }
}

export const pendingReceipt = (): Receipt => receipt()
export const readyReceipt = (overrides: Partial<Receipt> = {}): Receipt =>
  receipt({
    status: 'ready',
    viewUrl: 'https://bucket.example/view?sig=1',
    urlsExpireAt: '2026-11-01T12:05:00Z',
    ...overrides,
  })
export const failedReceipt = (): Receipt =>
  receipt({ status: 'failed', failureReason: 'unreadable' })

export function ok<T>(data: T, status = 200): ApiResult<T> {
  return { ok: true, status, data }
}
export function fail(error: ApiError): ApiResult<never> {
  return { ok: false, error }
}
export const NOT_FOUND: ApiError = { kind: 'not-found', status: 404 }
export const NETWORK: ApiError = { kind: 'network' }

export function policy(): ApiResult<ReceiptUpload> {
  return ok(
    {
      receiptId: '9b1c1f0e-5d3a-4c8e-9a41-2f6d8e7b1a11',
      url: 'https://bucket.example/upload',
      fields: { key: 'receipts/k', Policy: 'p' },
      expiresAt: '2026-11-01T12:10:00Z',
      maxBytes: 10_485_760,
    },
    201,
  )
}

/** A 4-byte PNG by the browser's own report. */
export function pngFile(): File {
  return new File(['abcd'], 'lunch.png', { type: 'image/png' })
}
export function pdfFile(): File {
  return new File(['abcdefgh'], 'bill.pdf', { type: 'application/pdf' })
}

/** Lets chained promise callbacks run under fake timers (flushPromises would wait on a faked timer). */
export async function settle(): Promise<void> {
  for (let turn = 0; turn < 20; turn += 1) {
    await Promise.resolve()
  }
}
