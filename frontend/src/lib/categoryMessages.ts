import type { ApiError } from '@/api/problem'
import { NAME_MAX } from '@/config'
import { GENERIC, NETWORK, waitPhrase } from '@/lib/authMessages'

export interface CategoryErrorView {
  /** The message that belongs under the name field. */
  fields: { name?: string }
  /** The message about the whole form (empty when there is none). */
  form: string
  /** True for a 409: the view offers "Reload" on a rename. */
  conflict: boolean
}

export const MAY_BE_SAVED = 'It may have been saved; check the list before trying again.'
const CONFLICT_FALLBACK = 'The category was changed or the name is taken. Reload and try again.'

/** The client-side message for a name that fails the check (plan phase-4 C10). */
export function nameProblem(reason: 'empty' | 'too-long'): string {
  return reason === 'empty' ? 'Enter a name.' : `Name can have at most ${NAME_MAX} characters.`
}

function plain(form: string, conflict = false): CategoryErrorView {
  return { fields: {}, form, conflict }
}

// Server text (409 `detail`, 422 `errors[].detail`) is shown as it is: the contract promises it is written for people
// and never repeats a value. Only `name` has a field here; any other name joins the form message.
function describeValidation(error: Extract<ApiError, { kind: 'validation' }>): CategoryErrorView {
  const rest = [...error.formErrors]
  for (const [field, message] of Object.entries(error.fieldErrors)) {
    if (field !== 'name') {
      rest.push(message)
    }
  }
  const name = error.fieldErrors.name
  return { fields: name === undefined ? {} : { name }, form: rest.join(' '), conflict: false }
}

/**
 * The text for a failed category call (plan phase-4 C4). Everything but a 409 detail and a 422 detail is fixed client
 * text. `write` is true for create, rename and delete: a write that timed out may still have been saved.
 */
export function describeCategoryError(error: ApiError, write: boolean): CategoryErrorView {
  switch (error.kind) {
    case 'network':
      return plain(NETWORK)
    case 'timeout':
      return plain(write ? `${NETWORK} ${MAY_BE_SAVED}` : NETWORK)
    case 'bad-request':
      return plain('The request was not accepted. Reload the page and try again.')
    case 'forbidden':
      return plain('The request was blocked. Reload the page and try again.')
    case 'unauthorized':
      return plain('Your session ended. Sign in again.')
    case 'not-found':
      return plain('This category no longer exists.')
    case 'conflict':
      return plain(error.detail ?? CONFLICT_FALLBACK, true)
    case 'validation':
      return describeValidation(error)
    case 'rate-limited':
      return plain(`Too many attempts. Try again ${waitPhrase(error.retryAfterSeconds)}.`)
    case 'unavailable':
      return plain(`The service is busy. Try again ${waitPhrase(error.retryAfterSeconds)}.`)
    default:
      return plain(GENERIC)
  }
}

/** The id of the page's warning about deleting, which the Delete buttons point at (`aria-describedby`). */
export const DELETE_HINT_ID = 'category-delete-hint'
export const DELETE_HINT = 'When you delete a category, its expenses become Uncategorized.'
