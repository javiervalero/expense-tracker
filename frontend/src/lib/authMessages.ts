import type { ApiError } from '@/api/problem'
import type { Credentials } from '@/api/types'
import { PASSWORD_MAX, PASSWORD_MIN } from '@/config'
import { checkRegisterPassword, checkSignInPassword, trimServerWhitespace } from '@/lib/text'

export type AuthForm = 'login' | 'register'
export type AuthField = 'email' | 'password'

export interface AuthErrorView {
  /** Messages that belong under a field. */
  fields: Partial<Record<AuthField, string>>
  /** The message about the whole form (empty when there is none). */
  form: string
  /** True when the view should offer a link to sign-in (register, 409). */
  accountExists: boolean
}

export const GENERIC = 'Something went wrong on our side. Try again.'
export const NETWORK = "Can't reach the server. Check your connection and try again."
const MAY_EXIST = 'If you tried before, your account may exist: try signing in.'

/**
 * How long to wait, as words: "in 30 seconds", "in about 15 minutes", "in about 2 hours", or "later" when the server did
 * not say (plan phase-4 C4). Under a minute it is exact; beyond that it is rounded UP, never down, so nobody is told to
 * retry before the server will let them. From 3540 s (59 minutes) it counts hours.
 */
export function waitPhrase(seconds: number | null): string {
  if (seconds === null) {
    return 'later'
  }
  if (seconds < 60) {
    return `in ${seconds} ${seconds === 1 ? 'second' : 'seconds'}`
  }
  if (seconds < 3540) {
    const minutes = Math.ceil(seconds / 60)
    return `in about ${minutes} ${minutes === 1 ? 'minute' : 'minutes'}`
  }
  const hours = Math.ceil(seconds / 3600)
  return `in about ${hours} ${hours === 1 ? 'hour' : 'hours'}`
}

function formOnly(form: string): AuthErrorView {
  return { fields: {}, form, accountExists: false }
}

// Server text (422 `errors[].detail`) is shown as it is: the contract promises it is written for people and never
// repeats a value. Only `email` and `password` have a field here; any other name joins the form message.
function describeValidation(error: Extract<ApiError, { kind: 'validation' }>): AuthErrorView {
  const fields: Partial<Record<AuthField, string>> = {}
  const rest = [...error.formErrors]
  for (const [name, message] of Object.entries(error.fieldErrors)) {
    if (name === 'email' || name === 'password') {
      fields[name] = message
    } else {
      rest.push(message)
    }
  }
  return { fields, form: rest.join(' '), accountExists: false }
}

/**
 * The text for a failed sign-in or registration. Everything but a 422 is fixed client text: the server's `detail` is
 * never used for 401, so nothing it says (or could be made to say) reaches the page. `unauthorized` is the generic
 * "session ended" line; sign-in's own 401 is `invalid-credentials`, the one message ADR 0004 allows.
 */
export function describeAuthError(error: ApiError, form: AuthForm): AuthErrorView {
  switch (error.kind) {
    case 'invalid-credentials':
      return formOnly('Email or password is incorrect.')
    case 'unauthorized':
      return formOnly('Your session ended. Sign in again.')
    case 'network':
      return formOnly(NETWORK)
    case 'timeout':
      // A register that timed out may still have created the account.
      return formOnly(
        form === 'register'
          ? `${NETWORK} Your account may have been created: try signing in.`
          : NETWORK,
      )
    case 'bad-request':
      return formOnly('The request was not accepted. Reload the page and try again.')
    case 'forbidden':
      return formOnly('The request was blocked. Reload the page and try again.')
    case 'conflict':
      return form === 'register'
        ? { fields: {}, form: 'An account with this email already exists.', accountExists: true }
        : formOnly(GENERIC)
    case 'validation':
      return describeValidation(error)
    case 'rate-limited':
      return formOnly(`Too many attempts. Try again ${waitPhrase(error.retryAfterSeconds)}.`)
    case 'unavailable': {
      const busy = `The service is busy. Try again ${waitPhrase(error.retryAfterSeconds)}.`
      return formOnly(form === 'register' ? `${busy} ${MAY_EXIST}` : busy)
    }
    default:
      return formOnly(GENERIC)
  }
}

export type CredentialsCheck =
  { ok: true; credentials: Credentials } | { ok: false; fields: Partial<Record<AuthField, string>> }

/**
 * The client-side checks before sending (plan phase-4 C10): a convenience, looser than the server and never stricter.
 * The email is trimmed as the server trims it and only has to be non-empty (the server decides what an address is); the
 * password is never trimmed. Register wants 12 to 128 code points, sign-in only at most 128 and not empty.
 */
export function checkCredentials(
  form: AuthForm,
  email: string,
  password: string,
): CredentialsCheck {
  const fields: Partial<Record<AuthField, string>> = {}
  const address = trimServerWhitespace(email)
  if (address === '') {
    fields.email = 'Enter your email address.'
  }
  const checked =
    form === 'register' ? checkRegisterPassword(password) : checkSignInPassword(password)
  if (!checked.ok) {
    fields.password = {
      empty: 'Enter your password.',
      'too-short': `Password must have at least ${PASSWORD_MIN} characters.`,
      'too-long': `Password can have at most ${PASSWORD_MAX} characters.`,
    }[checked.reason]
  }
  return Object.keys(fields).length > 0
    ? { ok: false, fields }
    : { ok: true, credentials: { email: address, password } }
}
