import { describe, expect, it } from 'vitest'

import type { ApiError } from '@/api/problem'
import { GENERIC, NETWORK } from '@/lib/authMessages'
import { describeSummaryError, INCOMPLETE_NOTICE } from '../summaryMessages'

describe('describeSummaryError', () => {
  it.each<[ApiError, string]>([
    [{ kind: 'network' }, NETWORK],
    [{ kind: 'timeout' }, NETWORK],
    [
      { kind: 'bad-request', status: 400 },
      'The request was not accepted. Reload the page and try again.',
    ],
    [{ kind: 'invalid-request' }, GENERIC],
    [{ kind: 'forbidden', status: 403 }, 'The request was blocked. Reload the page and try again.'],
    [{ kind: 'unauthorized', status: 401 }, 'Your session ended. Sign in again.'],
    [
      { kind: 'rate-limited', status: 429, retryAfterSeconds: null },
      'Too many attempts. Try again later.',
    ],
    [
      { kind: 'rate-limited', status: 429, retryAfterSeconds: 30 },
      'Too many attempts. Try again in 30 seconds.',
    ],
    [
      { kind: 'unavailable', status: 503, retryAfterSeconds: null },
      'The service is busy. Try again later.',
    ],
    [{ kind: 'server', status: 500 }, GENERIC],
    [{ kind: 'aborted' }, GENERIC],
    [{ kind: 'not-found', status: 404 }, GENERIC],
    [{ kind: 'client-bug', status: 418 }, GENERIC],
    [{ kind: 'conflict', status: 409, detail: 'Text.' }, GENERIC],
    [{ kind: 'validation', status: 422, fieldErrors: {}, formErrors: [] }, GENERIC],
    [{ kind: 'invalid-credentials', status: 401 }, GENERIC],
  ])('maps %j to its fixed text', (error, text) => {
    expect(describeSummaryError(error)).toBe(text)
  })

  it('has the incomplete-month notice of the plan', () => {
    expect(INCOMPLETE_NOTICE).toBe(
      'This month has more than 10,000 expenses. The totals cover the latest 10,000.',
    )
  })
})
