import { RECEIPT_MAX_BYTES, RECEIPT_TYPES } from '../config'

export type ReceiptType = (typeof RECEIPT_TYPES)[number]

export type ReceiptFileProblem = 'type' | 'empty' | 'too-large'

export type ReceiptFileCheck =
  { ok: true; contentType: ReceiptType } | { ok: false; reason: ReceiptFileProblem }

/** True when `value` is exactly one of the accepted receipt types (lower case, no parameters). */
export function isReceiptType(value: string): value is ReceiptType {
  return (RECEIPT_TYPES as readonly string[]).includes(value)
}

/**
 * The browser-side pre-check of a chosen file (plan C17): type first, then size. The server and the bucket decide
 * again; this only saves a round trip. The type is the browser's own report, compared exactly (lower case). On success
 * the type is narrowed, so the upload request can be built from it without a cast.
 */
export function checkReceiptFile(file: { type: string; size: number }): ReceiptFileCheck {
  if (!isReceiptType(file.type)) {
    return { ok: false, reason: 'type' }
  }
  if (file.size < 1) {
    return { ok: false, reason: 'empty' }
  }
  if (file.size > RECEIPT_MAX_BYTES) {
    return { ok: false, reason: 'too-large' }
  }
  return { ok: true, contentType: file.type }
}
