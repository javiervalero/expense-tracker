import { DATE_MAX, DATE_MIN, PAGE_SIZE, Q_MAX } from '../config'
import { isIsoDate } from './dates'
import { codePointLength, trimServerWhitespace } from './text'

// The expense list filters (plan phase-4 C11) in their three forms: the page URL (`route.query`), the form, and the API
// query. Every value that comes from outside passes through `sanitizeFilters` before it can reach the API, so a bad
// value is dropped, never sent.

/** '' means "not set" for each member. `q` is always trimmed. */
export interface ExpenseFilters {
  readonly from: string
  readonly to: string
  readonly category: string
  readonly q: string
}
export type FilterField = keyof ExpenseFilters
export type FilterProblems = Partial<Record<FilterField, string>>

export const NO_FILTERS: ExpenseFilters = { from: '', to: '', category: '', q: '' }

/** The category filter for expenses without one. */
export const CATEGORY_NONE = 'none'

// Contract: the category id is a lower-case version 4 uuid. `$` without the m flag matches only the very end.
const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

export function isUuidV4(value: string): boolean {
  return UUID_V4.test(value)
}

const FIELDS: readonly FilterField[] = ['from', 'to', 'category', 'q']

function validDate(value: string): boolean {
  return value === '' || isIsoDate(value)
}
function validCategory(value: string): boolean {
  return value === '' || value === CATEGORY_NONE || isUuidV4(value)
}
function validQ(trimmed: string): boolean {
  return codePointLength(trimmed) <= Q_MAX
}

/** The same filters with every invalid member dropped, and `q` trimmed. */
export function sanitizeFilters(filters: ExpenseFilters): ExpenseFilters {
  const q = trimServerWhitespace(filters.q)
  return {
    from: validDate(filters.from) ? filters.from : '',
    to: validDate(filters.to) ? filters.to : '',
    category: validCategory(filters.category) ? filters.category : '',
    q: validQ(q) ? q : '',
  }
}

/**
 * Filters from the page URL. Only the four known names are read; each must be a single string (a repeated name is an
 * array in `route.query`, and `?name` with no value is null). Anything invalid is dropped.
 */
export function parseFilters(query: Readonly<Record<string, unknown>>): ExpenseFilters {
  const read = (name: FilterField): string => {
    const value = Object.hasOwn(query, name) ? query[name] : undefined
    return typeof value === 'string' ? value : ''
  }
  return sanitizeFilters({
    from: read('from'),
    to: read('to'),
    category: read('category'),
    q: read('q'),
  })
}

/** The members to put in the page URL: no empty value. */
export function filtersToQuery(filters: ExpenseFilters): Record<string, string> {
  const query: Record<string, string> = {}
  for (const field of FIELDS) {
    if (filters[field] !== '') {
      query[field] = filters[field]
    }
  }
  return query
}

/** A string that is equal for equal (sanitized) filters. */
export function filtersKey(filters: ExpenseFilters): string {
  return new URLSearchParams(filtersToQuery(sanitizeFilters(filters))).toString()
}

const DATE_PROBLEM = `Enter a date from ${DATE_MIN} to ${DATE_MAX}.`

/**
 * What is wrong with the filters, per field (an empty object when nothing is). The client may be looser than the
 * server, never stricter (plan C10): these are the server's own rules.
 */
export function checkFilters(filters: ExpenseFilters): FilterProblems {
  const problems: FilterProblems = {}
  if (!validDate(filters.from)) {
    problems.from = DATE_PROBLEM
  }
  if (!validDate(filters.to)) {
    problems.to = DATE_PROBLEM
  }
  if (problems.from === undefined && problems.to === undefined) {
    // YYYY-MM-DD strings compare in date order.
    if (filters.from !== '' && filters.to !== '' && filters.from > filters.to) {
      problems.to = 'The end date must not be before the start date.'
    }
  }
  if (!validCategory(filters.category)) {
    problems.category = 'Choose a category from the list.'
  }
  if (!validQ(trimServerWhitespace(filters.q))) {
    problems.q = `Search text can be at most ${Q_MAX} characters.`
  }
  return problems
}

/**
 * The API query for one page: the sanitized filters that are set, `limit` always, and the cursor when there is one. The
 * cursor is opaque (never decoded or changed here); URLSearchParams only encodes it as one value.
 */
export function buildApiParams(
  filters: ExpenseFilters,
  cursor: string | null = null,
): URLSearchParams {
  const clean = sanitizeFilters(filters)
  const params = new URLSearchParams()
  for (const field of FIELDS) {
    if (clean[field] !== '') {
      params.set(field, clean[field])
    }
  }
  params.set('limit', String(PAGE_SIZE))
  if (cursor !== null && cursor !== '') {
    params.set('cursor', cursor)
  }
  return params
}
