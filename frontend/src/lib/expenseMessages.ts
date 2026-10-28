import type { ApiError } from '@/api/problem'
import { GENERIC, NETWORK, waitPhrase } from '@/lib/authMessages'
import { MAY_BE_SAVED } from '@/lib/categoryMessages'
import type { ExpenseField } from '@/lib/expenseForm'

export interface ExpenseErrorView {
  /** Messages that belong under a field. */
  fields: Partial<Record<ExpenseField, string>>
  /** The message about the whole form (empty when there is none). */
  form: string
  /** True for a 409: the view offers "Reload". */
  conflict: boolean
  /** True for a 404: the expense no longer exists, and the view links back to the list. */
  gone: boolean
}

const CONFLICT_FALLBACK = 'The expense was changed elsewhere. Reload and try again.'

// The contract names the amount member `amountCents`; the form calls the field `amount`.
const FIELD_OF_MEMBER: Readonly<Record<string, ExpenseField>> = {
  date: 'date',
  amountCents: 'amount',
  description: 'description',
  categoryId: 'categoryId',
}

function plain(form: string, flags: { conflict?: boolean; gone?: boolean } = {}): ExpenseErrorView {
  return { fields: {}, form, conflict: flags.conflict ?? false, gone: flags.gone ?? false }
}

// Server text (409 `detail`, 422 `errors[].detail`) is shown as it is. A member the form has no field for (such as
// `version`) joins the form message.
function describeValidation(error: Extract<ApiError, { kind: 'validation' }>): ExpenseErrorView {
  const fields: Partial<Record<ExpenseField, string>> = {}
  const rest = [...error.formErrors]
  for (const [member, message] of Object.entries(error.fieldErrors)) {
    const field = Object.hasOwn(FIELD_OF_MEMBER, member) ? FIELD_OF_MEMBER[member] : undefined
    if (field === undefined) {
      rest.push(message)
    } else {
      fields[field] = message
    }
  }
  return { fields, form: rest.join(' '), conflict: false, gone: false }
}

/**
 * The text for a failed expense call (plan phase-4 C4). Everything but a 409 detail and a 422 detail is fixed client
 * text. `write` is true for create, update and delete: a write that timed out may still have been saved.
 */
export function describeExpenseError(error: ApiError, write: boolean): ExpenseErrorView {
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
      return plain('This expense no longer exists.', { gone: true })
    case 'conflict':
      return plain(error.detail ?? CONFLICT_FALLBACK, { conflict: true })
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
