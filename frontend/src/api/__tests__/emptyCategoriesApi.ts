import { vi } from 'vitest'

/**
 * A stand-in for '@/api/categories' for specs that mount a page at /categories without being about categories: the
 * list is empty, so the page loads without touching the network. Use it as
 * `vi.mock('@/api/categories', () => import('@/api/__tests__/emptyCategoriesApi').then((m) => m.emptyCategoriesApi()))`.
 */
export function emptyCategoriesApi() {
  return {
    listCategories: vi.fn().mockResolvedValue({ ok: true, status: 200, data: { items: [] } }),
  }
}
