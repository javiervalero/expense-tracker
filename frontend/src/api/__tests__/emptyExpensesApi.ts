import { vi } from 'vitest'

/**
 * A stand-in for '@/api/expenses' for specs that mount a page at /expenses without being about the expense list: the
 * list is empty, so the page loads without touching the network. Use it as
 * `vi.mock('@/api/expenses', () => import('@/api/__tests__/emptyExpensesApi').then((m) => m.emptyExpensesApi()))`.
 */
export function emptyExpensesApi() {
  return {
    listExpenses: vi
      .fn()
      .mockResolvedValue({ ok: true, status: 200, data: { items: [], nextCursor: null } }),
  }
}
