import { UPLOAD_TIMEOUT_MS } from '../config'
import { isSafeUploadUrl } from '../lib/receiptUrl'
import type { ReceiptUpload } from './types'

// The browser upload of a receipt to the signed address (plan phase-5 C17). This is the only file allowed to use
// XMLHttpRequest, because fetch cannot report upload progress. Nothing here logs: the url and fields hold a signature.

export type UploadError =
  | { kind: 'invalid-request' }
  | { kind: 'aborted' }
  | { kind: 'size' }
  | { kind: 'expired' }
  | { kind: 'interrupted' }

export type UploadResult = { ok: true } | { ok: false; error: UploadError }

export type UploadTarget = Pick<ReceiptUpload, 'url' | 'fields'>

export interface UploadOptions {
  /** Called with whole percents from 0 to 100, never after the upload has finished. It must not throw. */
  onProgress?: (percent: number) => void
  signal?: AbortSignal
}

// The only form fields a signed upload may carry. Anything else (`acl`, `success_action_redirect`...) is refused.
const ALLOWED_FIELD = /^(?:key|Content-Type|Policy|X-Amz-.+)$/

const STATUS_NO_CONTENT = 204
const STATUS_BAD_REQUEST = 400
const STATUS_FORBIDDEN = 403

function fail(kind: UploadError['kind']): UploadResult {
  return { ok: false, error: { kind } }
}

const MAX_FIELDS = 16

function isRecord(value: unknown): value is Record<string, unknown> {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) {
    return false
  }
  const prototype: unknown = Object.getPrototypeOf(value)
  return prototype === Object.prototype || prototype === null
}

// The target comes from a server answer, so its shape is checked and not trusted: a plain object of at most
// MAX_FIELDS string values, every name on the allow-list (which also rules out `__proto__`, `constructor` and
// `prototype`), and a safe address.
function isValidTarget(target: unknown): target is UploadTarget {
  if (!isRecord(target) || !isRecord(target.fields)) {
    return false
  }
  const { url, fields } = target
  const names = Object.keys(fields)
  return (
    isSafeUploadUrl(typeof url === 'string' ? url : null) &&
    names.length <= MAX_FIELDS &&
    names.every((name) => ALLOWED_FIELD.test(name) && typeof fields[name] === 'string')
  )
}

function buildForm(fields: Readonly<Record<string, string>>, file: File): FormData {
  const form = new FormData()
  for (const [name, value] of Object.entries(fields)) {
    form.append(name, value)
  }
  form.append('file', file)
  return form
}

function percentOf(event: ProgressEvent): number {
  return Math.min(100, Math.max(0, Math.round((event.loaded / event.total) * 100)))
}

// The bucket answers 204 on success, 400 when the size is outside the policy and 403 when the policy expired.
function resultOfStatus(status: number): UploadResult {
  if (status === STATUS_NO_CONTENT) {
    return { ok: true }
  }
  if (status === STATUS_BAD_REQUEST) {
    return fail('size')
  }
  return fail(status === STATUS_FORBIDDEN ? 'expired' : 'interrupted')
}

/**
 * POSTs `fields` then `file` (last) to `target.url`. Never throws or rejects: every outcome is a value. The request is
 * created and sent before this function returns. An address or field name outside the allow-list fails closed as
 * `invalid-request` before anything is opened.
 */
export function uploadReceipt(
  target: UploadTarget,
  file: File,
  options: UploadOptions = {},
): Promise<UploadResult> {
  const { onProgress, signal } = options
  if (!isValidTarget(target)) {
    return Promise.resolve(fail('invalid-request'))
  }
  if (signal?.aborted === true) {
    return Promise.resolve(fail('aborted'))
  }
  return new Promise<UploadResult>((resolve) => {
    const xhr = new XMLHttpRequest()
    const onAbort = (): void => xhr.abort()
    let settled = false
    const settle = (result: UploadResult): void => {
      if (settled) {
        return
      }
      settled = true
      signal?.removeEventListener('abort', onAbort)
      resolve(result)
    }
    xhr.upload.onprogress = (event): void => {
      if (!settled && event.lengthComputable && event.total > 0) {
        onProgress?.(percentOf(event))
      }
    }
    xhr.onload = (): void => settle(resultOfStatus(xhr.status))
    xhr.onerror = (): void => settle(fail('interrupted'))
    xhr.ontimeout = (): void => settle(fail('interrupted'))
    xhr.onabort = (): void => settle(fail('aborted'))
    try {
      xhr.open('POST', target.url)
      xhr.withCredentials = false
      xhr.timeout = UPLOAD_TIMEOUT_MS
      signal?.addEventListener('abort', onAbort, { once: true })
      xhr.send(buildForm(target.fields, file))
    } catch {
      settle(fail('interrupted'))
    }
  })
}
