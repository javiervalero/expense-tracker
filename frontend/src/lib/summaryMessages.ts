import type { ApiError } from '@/api/problem'
import { GENERIC } from '@/lib/authMessages'
import { describeListError } from '@/lib/expenseMessages'

/** The notice above the table when the month has more expenses than the report counts (plan phase-6 D16). */
export const INCOMPLETE_NOTICE =
  'This month has more than 10,000 expenses. The totals cover the latest 10,000.'

/**
 * The text for a failed report call (plan phase-6 D16): the fixed Phase 4 C4 texts of the list page. The report is only
 * read, so a timeout is the plain network text. A 401 also reaches the global expiry handler.
 */
export function describeSummaryError(error: ApiError): string {
  // The report has no 404 (an empty month is a 200); the expense text of the list must not appear here.
  return error.kind === 'not-found' ? GENERIC : describeListError(error, false)
}
