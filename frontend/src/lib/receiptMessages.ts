import type { ApiError } from '@/api/problem'
import type { Receipt } from '@/api/types'
import type { UploadError } from '@/api/upload'
import { describeExpenseError } from '@/lib/expenseMessages'
import type { ReceiptFileProblem } from '@/lib/receiptFile'

// Fixed client text for the receipt flow (plan phase-5 C20). Server text is shown only for a 422 field or form message
// on the "start an upload" call; every other receipt message here is fixed client text.

// "10 MB" is RECEIPT_MAX_BYTES (10 MiB) in the owner-approved wording (decision 1); it must change with that constant.
const ACCEPTED_FILES_TEXT = 'Choose a JPEG, PNG, WebP or PDF file up to 10 MB.'
const INTERRUPTED = 'Upload interrupted. Try again.'
const POLICY_FALLBACK = 'The receipt was not accepted. Choose another file.'
const CONFLICT_TEXT = 'The receipt changed in another tab. Reload.'

export const STILL_PROCESSING_MESSAGE = 'Still processing. Check again in a moment.'

/** The text for a file the browser pre-check refused: every reason (type, empty, too large) gets the same advice. */
export function preCheckMessage(reason: ReceiptFileProblem): string {
  switch (reason) {
    case 'type':
    case 'empty':
    case 'too-large':
      return ACCEPTED_FILES_TEXT
  }
}

/** The text for a failed browser upload, or null when the user cancelled (nothing to show). */
export function uploadErrorMessage(error: UploadError): string | null {
  switch (error.kind) {
    case 'aborted':
      return null
    case 'size':
      return ACCEPTED_FILES_TEXT
    case 'expired':
      return 'The upload link expired. Try again.'
    default:
      return INTERRUPTED
  }
}

/** The text for a receipt the server checked and refused. An unknown reason (a newer server) gets a generic text. */
export function failureMessage(reason: NonNullable<Receipt['failureReason']>): string {
  switch (reason) {
    case 'unsupported-type':
      return "This file isn't a valid JPEG, PNG, WebP or PDF."
    // "40 megapixels" is the server's pixel limit for an image (contract, Receipt.failureReason).
    case 'too-large':
      return 'The image is too large (max 40 megapixels).'
    case 'unreadable':
      return 'The file could not be read.'
    default:
      reason satisfies never // the compiler flags a new known reason that is not handled above
      return POLICY_FALLBACK
  }
}

function validationMessage(error: Extract<ApiError, { kind: 'validation' }>): string {
  for (const member of ['contentType', 'size']) {
    const message = Object.hasOwn(error.fieldErrors, member) ? error.fieldErrors[member] : undefined
    if (message !== undefined) {
      return message
    }
  }
  return error.formErrors[0] ?? POLICY_FALLBACK
}

/** The text for a failed "start an upload" call. */
export function policyErrorMessage(error: ApiError): string {
  switch (error.kind) {
    case 'conflict':
      return CONFLICT_TEXT
    case 'validation':
      return validationMessage(error)
    default:
      return describeExpenseError(error, false).form
  }
}

/**
 * The text for a failed receipt delete: fixed client text only. A delete sends no body, so a 409 or 422 `detail` is
 * not shown. Not-found means it is already gone; the rest reuses the Phase 4 write text.
 */
export function deleteErrorMessage(error: ApiError): string {
  switch (error.kind) {
    case 'not-found':
      return 'Already removed.'
    case 'conflict':
      return CONFLICT_TEXT
    case 'validation':
      return describeExpenseError({ kind: 'bad-request', status: 400 }, true).form
    default:
      return describeExpenseError(error, true).form
  }
}
