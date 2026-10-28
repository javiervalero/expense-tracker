import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory } from 'vue-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import App from '@/App.vue'
import { clearFlash } from '@/lib/flash'
import { createAppRouter } from '@/router'

const auth = vi.hoisted(() => ({ me: vi.fn() }))
const expenses = vi.hoisted(() => ({ createExpense: vi.fn(), listExpenses: vi.fn() }))
vi.mock('@/api/auth', () => auth)
vi.mock('@/api/expenses', () => expenses)
vi.mock('@/api/categories', () =>
  import('@/api/__tests__/emptyCategoriesApi').then((m) => m.emptyCategoriesApi()),
)
vi.mock('@/lib/navigation', () => ({ navigateTo: vi.fn() }))
vi.mock('@/api/health', () => ({ fetchHealth: () => new Promise(() => {}) }))

beforeEach(() => {
  auth.me.mockReset()
  expenses.createExpense.mockReset()
  expenses.listExpenses.mockReset()
  expenses.listExpenses.mockResolvedValue({
    ok: true,
    status: 200,
    data: { items: [], nextCursor: null },
  })
  clearFlash()
})

describe('saving a new expense in the whole app', () => {
  it('lands on /expenses and shows "Expense saved" in the flash region', async () => {
    auth.me.mockResolvedValue({ ok: true, status: 200, data: { id: 'u', email: 'a@example.com' } })
    expenses.createExpense.mockResolvedValue({ ok: true, status: 201, data: {} })
    const pinia = createPinia()
    setActivePinia(pinia)
    const router = createAppRouter(createMemoryHistory())
    await router.push('/expenses/new')
    const wrapper = mount(App, { global: { plugins: [pinia, router] } })
    await flushPromises()

    await wrapper.get('#expense-amount').setValue('12,50')
    await wrapper.get('#expense-description').setValue('Lunch')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/expenses')
    expect(wrapper.get('.flash').text()).toBe('Expense saved')
  })
})
