import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'

import { FOOD, category, deferred, expense, failed, uid } from '@/api/__tests__/expenseFixtures'
import type { Expense } from '@/api/types'
import { useCategoriesStore } from '@/stores/categories'
import { useCategoryLabels } from '../useCategoryLabels'

const api = vi.hoisted(() => ({ listCategories: vi.fn() }))
vi.mock('@/api/categories', () => api)

const UNKNOWN = '2b7c1f0e-5d3a-4c8e-9a41-2f6d8e7b1a91'
const OTHER = '3b7c1f0e-5d3a-4c8e-9a41-2f6d8e7b1a92'
const listOf = (...items: ReturnType<typeof category>[]) => ({
  ok: true as const,
  status: 200,
  data: { items },
})
const FOOD_ONLY = listOf(category(FOOD, 'Food'))

function withExpenses(...ids: (string | null)[]) {
  return ref<readonly Expense[]>(ids.map((id, i) => expense(uid(i + 1), { categoryId: id })))
}

beforeEach(() => {
  api.listCategories.mockReset()
  setActivePinia(createPinia())
})

describe('useCategoryLabels', () => {
  it('labels a null category, a known one, and one that is still being looked up', async () => {
    api.listCategories.mockResolvedValue(FOOD_ONLY)
    const items = withExpenses(null, FOOD, UNKNOWN)
    const { categoryLabel } = useCategoryLabels(items)

    expect(categoryLabel(UNKNOWN)).toBe('…')
    await flushPromises()

    expect(categoryLabel(null)).toBe('Uncategorized')
    expect(categoryLabel(FOOD)).toBe('Food')
    expect(categoryLabel(UNKNOWN)).toBe('Uncategorized')
  })

  it('loads the categories once for the list and refreshes once for an unknown id', async () => {
    api.listCategories.mockResolvedValue(FOOD_ONLY)

    useCategoryLabels(withExpenses(UNKNOWN, UNKNOWN))
    await flushPromises()

    expect(api.listCategories).toHaveBeenCalledTimes(2)
  })

  it('shows "Category unavailable" when the lookup fails, and retries with a later batch', async () => {
    api.listCategories
      .mockResolvedValueOnce(FOOD_ONLY)
      .mockResolvedValueOnce(failed({ kind: 'network' }))
    const items = withExpenses(UNKNOWN)
    const { categoryLabel } = useCategoryLabels(items)
    await flushPromises()
    expect(categoryLabel(UNKNOWN)).toBe('Category unavailable')

    api.listCategories.mockResolvedValue(listOf(category(UNKNOWN, 'Gifts')))
    items.value = [...items.value, expense(uid(2), { categoryId: UNKNOWN })]
    await flushPromises()

    expect(categoryLabel(UNKNOWN)).toBe('Gifts')
    expect(api.listCategories).toHaveBeenCalledTimes(3)
  })

  it('does not retry a failed lookup when the list only shrinks', async () => {
    api.listCategories
      .mockResolvedValueOnce(FOOD_ONLY)
      .mockResolvedValueOnce(failed({ kind: 'network' }))
    const items = withExpenses(UNKNOWN, null)
    useCategoryLabels(items)
    await flushPromises()

    items.value = items.value.slice(0, 1)
    await flushPromises()

    expect(api.listCategories).toHaveBeenCalledTimes(2)
  })

  it('does not start a second lookup while one is pending (a bigger list)', async () => {
    const slow = deferred<unknown>()
    api.listCategories.mockResolvedValueOnce(FOOD_ONLY).mockReturnValueOnce(slow.promise)
    const items = withExpenses(UNKNOWN)
    useCategoryLabels(items)
    await flushPromises()

    items.value = [...items.value, expense(uid(2), { categoryId: UNKNOWN })]
    await flushPromises()
    slow.resolve(FOOD_ONLY)
    await flushPromises()

    expect(api.listCategories).toHaveBeenCalledTimes(2)
  })

  it('does not start a second lookup while one is pending (a delete)', async () => {
    const slow = deferred<unknown>()
    api.listCategories.mockResolvedValueOnce(FOOD_ONLY).mockReturnValueOnce(slow.promise)
    const items = withExpenses(UNKNOWN, null)
    useCategoryLabels(items)
    await flushPromises()

    items.value = items.value.slice(0, 1)
    await flushPromises()
    slow.resolve(FOOD_ONLY)
    await flushPromises()

    expect(api.listCategories).toHaveBeenCalledTimes(2)
  })

  it('looks an id up again after a failed lookup finished (the pending mark is released)', async () => {
    api.listCategories
      .mockResolvedValueOnce(FOOD_ONLY)
      .mockResolvedValueOnce(failed({ kind: 'network' }))
      .mockResolvedValueOnce(FOOD_ONLY)
    const items = withExpenses(UNKNOWN)
    useCategoryLabels(items)
    await flushPromises()

    items.value = [...items.value, expense(uid(2), { categoryId: OTHER })]
    await flushPromises()

    expect(api.listCategories).toHaveBeenCalledTimes(3)
  })

  it('retries once when the store loads successfully elsewhere after a failed first load', async () => {
    api.listCategories
      .mockResolvedValueOnce(failed({ kind: 'network' }))
      .mockResolvedValueOnce(FOOD_ONLY)
      .mockResolvedValueOnce(FOOD_ONLY)
    const { categoryLabel } = useCategoryLabels(withExpenses(UNKNOWN))
    await flushPromises()
    expect(categoryLabel(UNKNOWN)).toBe('Category unavailable')

    await useCategoriesStore().refresh()
    await flushPromises()

    expect(categoryLabel(UNKNOWN)).toBe('Uncategorized')
    expect(api.listCategories).toHaveBeenCalledTimes(3)
  })
})

describe('useCategoryLabels: the store is emptied', () => {
  it('asks for nothing when the store resets (loaded goes back to false)', async () => {
    api.listCategories.mockResolvedValue(FOOD_ONLY)
    useCategoryLabels(withExpenses(FOOD))
    await flushPromises()

    useCategoriesStore().reset()
    await flushPromises()

    expect(api.listCategories).toHaveBeenCalledTimes(1)
  })
})
