import { describe, expect, it } from 'vitest'

import type { ApiError } from '@/api/problem'
import { describeExpenseError, describeListError } from '../expenseMessages'

describe('describeExpenseError', () => {
  it('maps server field names to form fields: amountCents is the amount field', () => {
    const error: ApiError = {
      kind: 'validation',
      status: 422,
      fieldErrors: {
        amountCents: 'Too big.',
        date: 'Bad date.',
        description: 'Bad text.',
        categoryId: 'Gone.',
        version: 'Stale.',
      },
      formErrors: ['Nothing to change.'],
    }

    const view = describeExpenseError(error, true)

    expect(view.fields).toEqual({
      amount: 'Too big.',
      date: 'Bad date.',
      description: 'Bad text.',
      categoryId: 'Gone.',
    })
    expect(view.form).toBe('Nothing to change. Stale.')
  })

  it('shows a 422 with an empty pointer at form level', () => {
    const view = describeExpenseError(
      { kind: 'validation', status: 422, fieldErrors: {}, formErrors: ['Change something.'] },
      true,
    )

    expect(view).toMatchObject({ fields: {}, form: 'Change something.' })
  })

  it('flags a 409 with the server detail, or a fallback without one', () => {
    expect(
      describeExpenseError({ kind: 'conflict', status: 409, detail: 'Changed.' }, true),
    ).toEqual({
      fields: {},
      form: 'Changed.',
      conflict: true,
      gone: false,
    })
    expect(describeExpenseError({ kind: 'conflict', status: 409, detail: null }, true).form).toBe(
      'The expense was changed elsewhere. Reload and try again.',
    )
  })

  it('flags a 404 as gone', () => {
    expect(describeExpenseError({ kind: 'not-found', status: 404 }, true)).toEqual({
      fields: {},
      form: 'This expense no longer exists.',
      conflict: false,
      gone: true,
    })
  })

  it('adds the may-have-been-saved hint to a write that timed out, not to a read', () => {
    expect(describeExpenseError({ kind: 'timeout' }, true).form).toContain('It may have been saved')
    expect(describeExpenseError({ kind: 'timeout' }, false).form).not.toContain(
      'may have been saved',
    )
    expect(describeExpenseError({ kind: 'network' }, true).form).toBe(
      "Can't reach the server. Check your connection and try again.",
    )
  })

  it.each<[ApiError, string]>([
    [
      { kind: 'bad-request', status: 400 },
      'The request was not accepted. Reload the page and try again.',
    ],
    [{ kind: 'forbidden', status: 403 }, 'The request was blocked. Reload the page and try again.'],
    [{ kind: 'unauthorized', status: 401 }, 'Your session ended. Sign in again.'],
    [
      { kind: 'rate-limited', status: 429, retryAfterSeconds: 30 },
      'Too many attempts. Try again in 30 seconds.',
    ],
    [
      { kind: 'unavailable', status: 503, retryAfterSeconds: null },
      'The service is busy. Try again later.',
    ],
    [{ kind: 'server', status: 500 }, 'Something went wrong on our side. Try again.'],
  ])('maps %j to fixed text', (error, text) => {
    expect(describeExpenseError(error, true).form).toBe(text)
  })
})

describe('describeListError', () => {
  const SECRET = 'Hand-written server detail'

  it('turns a 409 or 422 into the generic text, never the server detail', () => {
    const validation: ApiError = {
      kind: 'validation',
      status: 422,
      fieldErrors: { q: SECRET },
      formErrors: [SECRET],
    }
    const conflict: ApiError = { kind: 'conflict', status: 409, detail: SECRET }

    expect(describeListError(validation, false)).toBe(
      'Something went wrong on our side. Try again.',
    )
    expect(describeListError(conflict, true)).toBe('Something went wrong on our side. Try again.')
  })

  it('keeps the fixed texts of the other kinds', () => {
    expect(describeListError({ kind: 'network' }, false)).toContain("Can't reach the server")
    expect(describeListError({ kind: 'timeout' }, true)).toContain('It may have been saved')
  })
})
