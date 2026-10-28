import { request, resourcePath } from './http'
import type { ApiResult } from './http'
import type { Expense, ExpensePage, ExpensePatch, NewExpense } from './types'

// The five expense operations of contract/openapi.yaml. All need the session, so a 401 runs the expiry handler.

const INVALID_ID: ApiResult<never> = { ok: false, error: { kind: 'invalid-request' } }

/**
 * `GET /expenses`: one page, newest first. `query` carries the filters, `limit` and `cursor`; the caller leaves out
 * empty values (the API answers 400 to an empty or unknown parameter).
 */
export function listExpenses(
  query?: URLSearchParams,
  signal?: AbortSignal,
): Promise<ApiResult<ExpensePage>> {
  return request<ExpensePage>('GET', '/expenses', {
    auth: 'session',
    ...(query === undefined ? {} : { query }),
    ...(signal === undefined ? {} : { signal }),
  })
}

/** `GET /expenses/{id}`: 200 with the expense, 404 when it does not exist (or is not the user's). */
export function getExpense(id: string, signal?: AbortSignal): Promise<ApiResult<Expense>> {
  const path = resourcePath('/expenses', id)
  return path === null
    ? Promise.resolve(INVALID_ID)
    : request<Expense>('GET', path, {
        auth: 'session',
        ...(signal === undefined ? {} : { signal }),
      })
}

/** `POST /expenses`: 201 with the new expense. There is no idempotency key, so the caller must not send twice. */
export function createExpense(body: NewExpense): Promise<ApiResult<Expense>> {
  return request<Expense>('POST', '/expenses', { auth: 'session', body })
}

/** `PATCH /expenses/{id}`: 200 with the expense. The body carries the `version` last read (409 if stale). */
export function updateExpense(id: string, body: ExpensePatch): Promise<ApiResult<Expense>> {
  const path = resourcePath('/expenses', id)
  return path === null
    ? Promise.resolve(INVALID_ID)
    : request<Expense>('PATCH', path, { auth: 'session', body })
}

/** `DELETE /expenses/{id}`: 204. A second delete of the same id is a 404. */
export function deleteExpense(id: string): Promise<ApiResult<void>> {
  const path = resourcePath('/expenses', id)
  return path === null
    ? Promise.resolve(INVALID_ID)
    : request<void>('DELETE', path, { auth: 'session' })
}
