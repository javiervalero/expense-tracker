import type { Expense, ExpensePatch } from '@/api/types'
import { trimServerWhitespace } from '@/lib/text'

/** The values of the expense form once parsed: cents as an integer, `categoryId` null for Uncategorized. */
export interface ExpenseValues {
  date: string
  amountCents: number
  description: string
  categoryId: string | null
}

/**
 * The body of a PATCH: the `version` read plus only the members that changed (plan phase-4 C12). Returns null when
 * nothing changed, so the caller sends no request. The description is compared after trimming; `categoryId: null`
 * means "make it Uncategorized".
 */
export function buildExpensePatch(original: Expense, form: ExpenseValues): ExpensePatch | null {
  const description = trimServerWhitespace(form.description)
  const changes: Omit<ExpensePatch, 'version'> = {
    ...(form.date === original.date ? {} : { date: form.date }),
    ...(form.amountCents === original.amountCents ? {} : { amountCents: form.amountCents }),
    ...(description === original.description ? {} : { description }),
    ...(form.categoryId === original.categoryId ? {} : { categoryId: form.categoryId }),
  }
  return Object.keys(changes).length === 0 ? null : { version: original.version, ...changes }
}
