import { describe, expect, it } from 'vitest'

import type { ApiError } from '@/api/problem'
import { checkCredentials, describeAuthError, waitPhrase } from '../authMessages'

describe('waitPhrase', () => {
  it.each([
    [null, 'later'],
    [1, 'in 1 second'],
    [30, 'in 30 seconds'],
    [59, 'in 59 seconds'],
    [60, 'in about 1 minute'],
    [61, 'in about 2 minutes'],
    [89, 'in about 2 minutes'],
    [897, 'in about 15 minutes'],
    [3539, 'in about 59 minutes'],
    [3540, 'in about 1 hour'],
    [3600, 'in about 1 hour'],
    [5399, 'in about 2 hours'],
    [86_400, 'in about 24 hours'],
  ])('%s seconds gives %j', (seconds, expected) => {
    expect(waitPhrase(seconds)).toBe(expected)
  })
})

function validation(fieldErrors: Record<string, string>, formErrors: string[] = []): ApiError {
  return { kind: 'validation', status: 422, fieldErrors, formErrors }
}

describe('describeAuthError', () => {
  it('sign-in 401 is one fixed message', () => {
    const shown = describeAuthError({ kind: 'invalid-credentials', status: 401 }, 'login')

    expect(shown).toEqual({
      fields: {},
      form: 'Email or password is incorrect.',
      accountExists: false,
    })
  })

  it('unauthorized is the fixed session message, whatever extra members the error has', () => {
    const hostile = { kind: 'unauthorized', status: 401, detail: 'Leaked server text' } as ApiError

    const shown = describeAuthError(hostile, 'login')

    expect(shown.form).toBe('Your session ended. Sign in again.')
    expect(JSON.stringify(shown)).not.toContain('Leaked')
  })

  it('names the network problem, and a register timeout adds that the account may exist', () => {
    expect(describeAuthError({ kind: 'network' }, 'login').form).toBe(
      "Can't reach the server. Check your connection and try again.",
    )
    expect(describeAuthError({ kind: 'timeout' }, 'login').form).toBe(
      "Can't reach the server. Check your connection and try again.",
    )
    expect(describeAuthError({ kind: 'timeout' }, 'register').form).toBe(
      "Can't reach the server. Check your connection and try again. Your account may have been created: try signing in.",
    )
  })

  it('409 on register says the account exists and asks for the link; on sign-in it is generic', () => {
    const conflict: ApiError = { kind: 'conflict', status: 409, detail: 'server text' }

    expect(describeAuthError(conflict, 'register')).toEqual({
      fields: {},
      form: 'An account with this email already exists.',
      accountExists: true,
    })
    expect(describeAuthError(conflict, 'login').accountExists).toBe(false)
    expect(describeAuthError(conflict, 'login').form).toBe(
      'Something went wrong on our side. Try again.',
    )
  })

  it('429 gives the wait, or "later" without Retry-After', () => {
    expect(
      describeAuthError({ kind: 'rate-limited', status: 429, retryAfterSeconds: 897 }, 'login')
        .form,
    ).toBe('Too many attempts. Try again in about 15 minutes.')
    expect(
      describeAuthError({ kind: 'rate-limited', status: 429, retryAfterSeconds: 30 }, 'login').form,
    ).toBe('Too many attempts. Try again in 30 seconds.')
    expect(
      describeAuthError({ kind: 'rate-limited', status: 429, retryAfterSeconds: null }, 'login')
        .form,
    ).toBe('Too many attempts. Try again later.')
  })

  it('503 is busy; on register it adds the "may exist" hint', () => {
    const busy: ApiError = { kind: 'unavailable', status: 503, retryAfterSeconds: 5 }

    expect(describeAuthError(busy, 'login').form).toBe(
      'The service is busy. Try again in 5 seconds.',
    )
    expect(describeAuthError(busy, 'register').form).toBe(
      'The service is busy. Try again in 5 seconds. If you tried before, your account may exist: try signing in.',
    )
  })

  it.each([
    [
      { kind: 'bad-request', status: 400 },
      'The request was not accepted. Reload the page and try again.',
    ],
    [{ kind: 'forbidden', status: 403 }, 'The request was blocked. Reload the page and try again.'],
    [{ kind: 'not-found', status: 404 }, 'Something went wrong on our side. Try again.'],
    [{ kind: 'client-bug', status: 413 }, 'Something went wrong on our side. Try again.'],
    [{ kind: 'server', status: 500 }, 'Something went wrong on our side. Try again.'],
    [{ kind: 'aborted' }, 'Something went wrong on our side. Try again.'],
    [{ kind: 'invalid-request' }, 'Something went wrong on our side. Try again.'],
  ] as [ApiError, string][])('%j gives a fixed message', (error, message) => {
    expect(describeAuthError(error, 'login').form).toBe(message)
  })

  it('422 puts email and password messages on their fields', () => {
    const shown = describeAuthError(
      validation({ email: 'Bad email.', password: 'Too short.' }),
      'register',
    )

    expect(shown.fields).toEqual({ email: 'Bad email.', password: 'Too short.' })
    expect(shown.form).toBe('')
  })

  it('422 form-level and unknown-field messages become the form message', () => {
    const shown = describeAuthError(
      validation({ nickname: 'Not allowed.' }, ['Something is wrong.']),
      'login',
    )

    expect(shown.fields).toEqual({})
    expect(shown.form).toBe('Something is wrong. Not allowed.')
  })

  it('422 with a field named like an object member does not shadow anything', () => {
    const errors = Object.create(null) as Record<string, string>
    errors['__proto__'] = 'Odd.'
    errors.password = 'Short.'

    const shown = describeAuthError(validation(errors), 'register')

    expect(shown.fields).toEqual({ password: 'Short.' })
    expect(shown.form).toBe('Odd.')
  })
})

describe('checkCredentials', () => {
  it('trims the email like the server and keeps the password as typed', () => {
    expect(checkCredentials('login', '  a@example.com\t', ' pw ')).toEqual({
      ok: true,
      credentials: { email: 'a@example.com', password: ' pw ' },
    })
  })

  it('reports an empty email, and an empty sign-in password', () => {
    expect(checkCredentials('login', ' ', '')).toEqual({
      ok: false,
      fields: { email: 'Enter your email address.', password: 'Enter your password.' },
    })
  })

  it('sign-in has no minimum and a 128 code point maximum', () => {
    expect(checkCredentials('login', 'a@b.c', 'x').ok).toBe(true)
    expect(checkCredentials('login', 'a@b.c', '😀'.repeat(128)).ok).toBe(true)
    expect(checkCredentials('login', 'a@b.c', '😀'.repeat(129))).toEqual({
      ok: false,
      fields: { password: 'Password can have at most 128 characters.' },
    })
  })

  it('register wants 12 to 128 code points', () => {
    expect(checkCredentials('register', 'a@b.c', '😀'.repeat(11))).toEqual({
      ok: false,
      fields: { password: 'Password must have at least 12 characters.' },
    })
    expect(checkCredentials('register', 'a@b.c', '😀'.repeat(12)).ok).toBe(true)
    expect(checkCredentials('register', 'a@b.c', 'x'.repeat(129)).ok).toBe(false)
  })
})
