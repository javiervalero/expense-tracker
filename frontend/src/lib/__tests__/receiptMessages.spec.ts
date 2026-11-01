import { describe, expect, it } from 'vitest'

import type { ApiError } from '@/api/problem'
import type { UploadError } from '@/api/upload'
import { describeExpenseError } from '../expenseMessages'
import {
  STILL_PROCESSING_MESSAGE,
  deleteErrorMessage,
  failureMessage,
  policyErrorMessage,
  preCheckMessage,
  uploadErrorMessage,
} from '../receiptMessages'

const SIZE_TEXT = 'Choose a JPEG, PNG, WebP or PDF file up to 10 MB.'
const INTERRUPTED = 'Upload interrupted. Try again.'

function validation(fieldErrors: Record<string, string>, formErrors: string[] = []): ApiError {
  return { kind: 'validation', status: 422, fieldErrors, formErrors }
}

// Every kind that has no receipt-specific text reuses the Phase 4 C4 text of expenseMessages.
const GENERIC_KINDS: readonly ApiError[] = [
  { kind: 'network' },
  { kind: 'timeout' },
  { kind: 'bad-request', status: 400 },
  { kind: 'forbidden', status: 403 },
  { kind: 'unauthorized', status: 401 },
  { kind: 'rate-limited', status: 429, retryAfterSeconds: 30 },
  { kind: 'unavailable', status: 503, retryAfterSeconds: null },
  { kind: 'server', status: 500 },
]

describe('preCheckMessage', () => {
  it.each(['type', 'empty', 'too-large'] as const)('gives the size text for %s', (reason) => {
    expect(preCheckMessage(reason)).toBe(SIZE_TEXT)
  })
})

describe('uploadErrorMessage', () => {
  it('is null (silent) for aborted', () => {
    expect(uploadErrorMessage({ kind: 'aborted' })).toBeNull()
  })

  it.each([
    ['size', SIZE_TEXT],
    ['expired', 'The upload link expired. Try again.'],
    ['interrupted', INTERRUPTED],
    ['invalid-request', INTERRUPTED],
  ] as const)('maps %s to its fixed text', (kind, text) => {
    const error: UploadError = { kind }
    expect(uploadErrorMessage(error)).toBe(text)
  })
})

describe('failureMessage', () => {
  it.each([
    ['unsupported-type', "This file isn't a valid JPEG, PNG, WebP or PDF."],
    ['too-large', 'The image is too large (max 40 megapixels).'],
    ['unreadable', 'The file could not be read.'],
  ] as const)('maps %s', (reason, text) => {
    expect(failureMessage(reason)).toBe(text)
  })

  it('gives a safe generic text for a reason a newer server sends', () => {
    expect(failureMessage('quarantined' as never)).toBe(
      'The receipt was not accepted. Choose another file.',
    )
  })
})

describe('policyErrorMessage', () => {
  it('says the expense is gone on not-found', () => {
    expect(policyErrorMessage({ kind: 'not-found', status: 404 })).toBe(
      'This expense no longer exists.',
    )
  })

  it('says the receipt changed in another tab on conflict, whatever the server detail', () => {
    expect(policyErrorMessage({ kind: 'conflict', status: 409, detail: 'Server words.' })).toBe(
      'The receipt changed in another tab. Reload.',
    )
  })

  it('shows the contentType field message of a 422', () => {
    expect(policyErrorMessage(validation({ contentType: 'Type not allowed.' }))).toBe(
      'Type not allowed.',
    )
  })

  it('shows the size field message of a 422', () => {
    expect(policyErrorMessage(validation({ size: 'Too big.' }))).toBe('Too big.')
  })

  it('prefers a known field message over a form message', () => {
    expect(policyErrorMessage(validation({ size: 'Too big.' }, ['Form words.']))).toBe('Too big.')
  })

  it('falls back to the first form error when no known field has a message', () => {
    expect(policyErrorMessage(validation({ other: 'Ignored.' }, ['First.', 'Second.']))).toBe(
      'First.',
    )
  })

  it('gives a non-empty fallback when a 422 has no usable message', () => {
    expect(policyErrorMessage(validation({}, [])).length).toBeGreaterThan(0)
  })

  it.each(GENERIC_KINDS)('reuses the Phase 4 text for $kind', (error) => {
    expect(policyErrorMessage(error)).toBe(describeExpenseError(error, false).form)
  })

  it('uses the exact Phase 4 wording for an expired session', () => {
    expect(policyErrorMessage({ kind: 'unauthorized', status: 401 })).toBe(
      'Your session ended. Sign in again.',
    )
  })
})

describe('deleteErrorMessage', () => {
  it('says "Already removed." on not-found', () => {
    expect(deleteErrorMessage({ kind: 'not-found', status: 404 })).toBe('Already removed.')
  })

  it('says the receipt changed in another tab on conflict, never the server text', () => {
    const error: ApiError = { kind: 'conflict', status: 409, detail: 'Server words.' }

    expect(deleteErrorMessage(error)).toBe('The receipt changed in another tab. Reload.')
  })

  it('shows fixed client text on a validation error, never the server text', () => {
    const error = validation({ size: 'Server words.' }, ['More server words.'])

    expect(deleteErrorMessage(error)).toBe(
      'The request was not accepted. Reload the page and try again.',
    )
  })

  it.each(GENERIC_KINDS)('reuses the Phase 4 write text for $kind', (error) => {
    expect(deleteErrorMessage(error)).toBe(describeExpenseError(error, true).form)
  })
})

describe('STILL_PROCESSING_MESSAGE', () => {
  it('is the polling-cap text', () => {
    expect(STILL_PROCESSING_MESSAGE).toBe('Still processing. Check again in a moment.')
  })
})
