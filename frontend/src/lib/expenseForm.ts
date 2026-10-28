import { DATE_MAX, DATE_MIN, DESCRIPTION_MAX } from '@/config'
import { isIsoDate } from '@/lib/dates'
import type { ExpenseValues } from '@/lib/expensePatch'
import { parseAmount } from '@/lib/money'
import type { AmountReason } from '@/lib/money'
import { checkDescription } from '@/lib/text'

export type ExpenseField = 'date' | 'amount' | 'description' | 'categoryId'

/** What the form holds: text as typed. `categoryId` is '' for Uncategorized. */
export interface ExpenseFormInput {
  date: string
  amount: string
  description: string
  categoryId: string
}

export type ExpenseFormResult =
  { ok: true; value: ExpenseValues } | { ok: false; fields: Partial<Record<ExpenseField, string>> }

const AMOUNT_MESSAGES: Record<AmountReason, string> = {
  empty: 'Enter an amount.',
  negative: 'Enter the amount without a minus sign.',
  invalid: 'Enter an amount such as 12.50.',
  'too-many-decimals': 'Use at most 2 decimals.',
  zero: 'The amount must be at least 0.01.',
  'too-large': 'The amount can be at most 1,000,000.00.',
}

function dateProblem(date: string): string | undefined {
  if (date === '') {
    return 'Enter a date.'
  }
  return isIsoDate(date) ? undefined : `Enter a real date between ${DATE_MIN} and ${DATE_MAX}.`
}

/**
 * The client-side check of the expense form (plan phase-4 C8 to C10). Looser than the server, never stricter: the
 * server's 422 decides. Reports every invalid field at once, in form order.
 */
export function validateExpenseForm(input: ExpenseFormInput): ExpenseFormResult {
  const fields: Partial<Record<ExpenseField, string>> = {}
  const dateMessage = dateProblem(input.date)
  if (dateMessage !== undefined) {
    fields.date = dateMessage
  }
  const amount = parseAmount(input.amount)
  if (!amount.ok) {
    fields.amount = AMOUNT_MESSAGES[amount.reason]
  }
  const description = checkDescription(input.description)
  if (!description.ok) {
    fields.description =
      description.reason === 'empty'
        ? 'Enter a description.'
        : `Description can have at most ${DESCRIPTION_MAX} characters.`
  }
  if (dateMessage !== undefined || !amount.ok || !description.ok) {
    return { ok: false, fields }
  }
  return {
    ok: true,
    value: {
      date: input.date,
      amountCents: amount.value,
      description: description.value,
      categoryId: input.categoryId === '' ? null : input.categoryId,
    },
  }
}
