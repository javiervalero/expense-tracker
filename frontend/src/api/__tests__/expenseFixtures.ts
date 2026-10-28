import type { ApiResult } from '@/api/http'
import type { ApiError } from '@/api/problem'
import type { Category, Expense, ExpensePage } from '@/api/types'

// Shared by the specs of the expense list. Ids are lower-case version 4 uuids, as the API issues them.
export const FOOD = '0b7c1f0e-5d3a-4c8e-9a41-2f6d8e7b1a90'
export const TRAVEL = '1b7c1f0e-5d3a-4c8e-9a41-2f6d8e7b1a99'

export function expense(id: string, overrides: Partial<Expense> = {}): Expense {
  return {
    id,
    date: '2026-10-01',
    amountCents: 1250,
    description: `Expense ${id.slice(0, 4)}`,
    categoryId: null,
    version: 1,
    createdAt: '2026-10-01T10:00:00Z',
    updatedAt: '2026-10-01T10:00:00Z',
    ...overrides,
  }
}

/** A well-formed v4 uuid made from a small number, for lists of expenses. */
export function uid(n: number): string {
  return `${String(n).padStart(8, '0')}-5d3a-4c8e-9a41-2f6d8e7b1a90`
}

export function category(id: string, name: string): Category {
  return {
    id,
    name,
    version: 1,
    createdAt: '2026-10-01T10:00:00Z',
    updatedAt: '2026-10-01T10:00:00Z',
  }
}

export function pageOf(items: Expense[], nextCursor: string | null = null): ApiResult<ExpensePage> {
  return { ok: true, status: 200, data: { items, nextCursor } }
}

export function failed(error: ApiError): ApiResult<never> {
  return { ok: false, error }
}

/** A promise settled by hand, to hold a request in flight. */
export function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((r) => {
    resolve = r
  })
  return { promise, resolve }
}
