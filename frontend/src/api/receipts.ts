import { request, resourcePath } from './http'
import type { ApiResult } from './http'
import type { Receipt, ReceiptUpload, ReceiptUploadRequest } from './types'

// The three receipt operations of contract/openapi.yaml. All need the session, so a 401 runs the expiry handler.

const INVALID_ID: ApiResult<never> = { ok: false, error: { kind: 'invalid-request' } }

const RECEIPT_SUFFIX = '/receipt'
const UPLOAD_SUFFIX = '/receipt/upload'

// Built from `resourcePath`, so the id check and the safe-path check still apply; the suffix is fixed text.
function receiptPath(expenseId: string, suffix: string): string | null {
  const base = resourcePath('/expenses', expenseId)
  return base === null ? null : `${base}${suffix}`
}

/**
 * `POST /expenses/{id}/receipt/upload`: 201 with a signed browser upload (it holds a signature: do not log it). The
 * body carries the declared type and exact size.
 */
export function createReceiptUpload(
  expenseId: string,
  body: ReceiptUploadRequest,
  signal?: AbortSignal,
): Promise<ApiResult<ReceiptUpload>> {
  const path = receiptPath(expenseId, UPLOAD_SUFFIX)
  return path === null
    ? Promise.resolve(INVALID_ID)
    : request<ReceiptUpload>('POST', path, {
        auth: 'session',
        body,
        ...(signal === undefined ? {} : { signal }),
      })
}

/** `GET /expenses/{id}/receipt`: 200 with the receipt, 404 when there is none. */
export function getReceipt(expenseId: string, signal?: AbortSignal): Promise<ApiResult<Receipt>> {
  const path = receiptPath(expenseId, RECEIPT_SUFFIX)
  return path === null
    ? Promise.resolve(INVALID_ID)
    : request<Receipt>('GET', path, {
        auth: 'session',
        ...(signal === undefined ? {} : { signal }),
      })
}

/** `DELETE /expenses/{id}/receipt`: 204. A second delete is a 404. */
export function deleteReceipt(expenseId: string): Promise<ApiResult<void>> {
  const path = receiptPath(expenseId, RECEIPT_SUFFIX)
  return path === null
    ? Promise.resolve(INVALID_ID)
    : request<void>('DELETE', path, { auth: 'session' })
}
