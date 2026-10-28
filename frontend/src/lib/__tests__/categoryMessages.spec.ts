import { describe, expect, it } from 'vitest'

import type { ApiError } from '@/api/problem'
import { describeCategoryError, nameProblem } from '../categoryMessages'

describe('nameProblem', () => {
  it('asks for a name when it is empty', () => {
    expect(nameProblem('empty')).toBe('Enter a name.')
  })

  it('states the limit in characters when it is too long', () => {
    expect(nameProblem('too-long')).toBe('Name can have at most 50 characters.')
  })
})

describe('describeCategoryError', () => {
  const validation: ApiError = {
    kind: 'validation',
    status: 422,
    fieldErrors: { name: 'Name is invalid.', version: 'Version is stale.' },
    formErrors: ['Something general.'],
  }

  it('puts a name error under the field and the rest at form level', () => {
    const view = describeCategoryError(validation, true)

    expect(view.fields).toEqual({ name: 'Name is invalid.' })
    expect(view.form).toBe('Something general. Version is stale.')
    expect(view.conflict).toBe(false)
  })

  it('shows the server detail of a 409 at form level and flags the conflict', () => {
    const view = describeCategoryError(
      { kind: 'conflict', status: 409, detail: 'Name in use.' },
      true,
    )

    expect(view).toEqual({ fields: {}, form: 'Name in use.', conflict: true })
  })

  it('has a fixed text for a 409 without a detail', () => {
    const view = describeCategoryError({ kind: 'conflict', status: 409, detail: null }, true)

    expect(view.form).toBe('The category was changed or the name is taken. Reload and try again.')
    expect(view.conflict).toBe(true)
  })

  it('says a 404 means the category no longer exists', () => {
    expect(describeCategoryError({ kind: 'not-found', status: 404 }, true).form).toBe(
      'This category no longer exists.',
    )
  })

  it('adds the "may have been saved" hint to a write that timed out, not to a read', () => {
    expect(describeCategoryError({ kind: 'timeout' }, true).form).toBe(
      "Can't reach the server. Check your connection and try again. It may have been saved; check the list before trying again.",
    )
    expect(describeCategoryError({ kind: 'timeout' }, false).form).toBe(
      "Can't reach the server. Check your connection and try again.",
    )
  })

  it.each<[ApiError, string]>([
    [{ kind: 'network' }, "Can't reach the server. Check your connection and try again."],
    [
      { kind: 'bad-request', status: 400 },
      'The request was not accepted. Reload the page and try again.',
    ],
    [{ kind: 'forbidden', status: 403 }, 'The request was blocked. Reload the page and try again.'],
    [{ kind: 'unauthorized', status: 401 }, 'Your session ended. Sign in again.'],
    [
      { kind: 'rate-limited', status: 429, retryAfterSeconds: 90 },
      'Too many attempts. Try again in about 2 minutes.',
    ],
    [
      { kind: 'unavailable', status: 503, retryAfterSeconds: 30 },
      'The service is busy. Try again in 30 seconds.',
    ],
    [{ kind: 'server', status: 500 }, 'Something went wrong on our side. Try again.'],
    [{ kind: 'client-bug', status: 415 }, 'Something went wrong on our side. Try again.'],
  ])('maps %j to fixed text', (error, text) => {
    const view = describeCategoryError(error, false)

    expect(view).toEqual({ fields: {}, form: text, conflict: false })
  })
})
