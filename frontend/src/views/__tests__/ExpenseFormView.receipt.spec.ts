import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

import type { ApiResult } from '@/api/http'
import type { Expense } from '@/api/types'
import { clearFlash } from '@/lib/flash'
import ExpenseFormView from '../ExpenseFormView.vue'

// Phase 5 WP12 (C16, C22): the receipt panel lives on the edit page only; the new form says why there is none.

const expensesApi = vi.hoisted(() => ({
  createExpense: vi.fn(),
  getExpense: vi.fn(),
  updateExpense: vi.fn(),
  deleteExpense: vi.fn(),
}))
const categoriesApi = vi.hoisted(() => ({ listCategories: vi.fn() }))
const panelLog = vi.hoisted(() => ({ events: [] as string[] }))
vi.mock('@/api/expenses', () => expensesApi)
vi.mock('@/api/categories', () => categoriesApi)
// A stand-in that records its lifecycle: the real panel has its own specs.
vi.mock('@/components/ReceiptPanel.vue', async () => {
  const { defineComponent, h, onBeforeUnmount, onMounted } = await import('vue')
  return {
    default: defineComponent({
      name: 'ReceiptPanelStub',
      props: { expenseId: { type: String, required: true } },
      setup(props) {
        onMounted(() => panelLog.events.push(`mount:${props.expenseId}`))
        onBeforeUnmount(() => panelLog.events.push(`unmount:${props.expenseId}`))
        return () => h('div', { 'data-testid': 'receipt-panel' }, props.expenseId)
      },
    }),
  }
})

const ID = '5a1c1f0e-5d3a-4c8e-9a41-2f6d8e7b1a91'
const OTHER_ID = '7c2d1f0e-5d3a-4c8e-9a41-2f6d8e7b1a92'
const HINT = 'You can attach a receipt after saving.'

function expense(id = ID): Expense {
  return {
    id,
    date: '2026-10-01',
    amountCents: 1250,
    description: 'Lunch',
    categoryId: null,
    version: 3,
    createdAt: '2026-10-01T10:00:00Z',
    updatedAt: '2026-10-01T10:00:00Z',
  }
}
const ok = <T>(data: T): ApiResult<T> => ({ ok: true, status: 200, data })

async function render(path: string) {
  categoriesApi.listCategories.mockResolvedValue(ok({ items: [] }))
  const pinia = createPinia()
  setActivePinia(pinia)
  const stub = { template: '<p>stub</p>' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/expenses', name: 'expenses', component: stub },
      { path: '/expenses/new', name: 'expense-new', component: ExpenseFormView },
      { path: '/expenses/:id', name: 'expense-edit', component: ExpenseFormView },
      { path: '/categories', name: 'categories', component: stub },
    ],
  })
  await router.push(path)
  const host = document.createElement('div')
  document.body.appendChild(host)
  const wrapper = mount(ExpenseFormView, { attachTo: host, global: { plugins: [pinia, router] } })
  await flushPromises()
  return { wrapper, router }
}
const panels = (wrapper: Awaited<ReturnType<typeof render>>['wrapper']) =>
  wrapper.findAllComponents({ name: 'ReceiptPanelStub' })

beforeEach(() => {
  Object.values(expensesApi).forEach((mock) => mock.mockReset())
  categoriesApi.listCategories.mockReset()
  panelLog.events.length = 0
  clearFlash()
})
afterEach(() => {
  document.body.replaceChildren()
})

describe('ExpenseFormView: the receipt on the new form', () => {
  it('says a receipt can be attached after saving, and has no file input or panel', async () => {
    const { wrapper } = await render('/expenses/new')

    expect(wrapper.text()).toContain(HINT)
    expect(wrapper.find('input[type="file"]').exists()).toBe(false)
    expect(panels(wrapper)).toHaveLength(0)
  })
})

describe('ExpenseFormView: the receipt on the edit page', () => {
  it('shows the panel for the route id once the expense is loaded, and not the hint', async () => {
    expensesApi.getExpense.mockResolvedValue(ok(expense()))

    const { wrapper } = await render(`/expenses/${ID}`)

    expect(panels(wrapper)).toHaveLength(1)
    expect(panels(wrapper)[0]?.props('expenseId')).toBe(ID)
    expect(wrapper.text()).not.toContain(HINT)
  })

  it('shows no panel while the expense is loading', async () => {
    expensesApi.getExpense.mockReturnValue(new Promise(() => {}))

    const { wrapper } = await render(`/expenses/${ID}`)

    expect(wrapper.text()).toContain('Loading expense…')
    expect(panels(wrapper)).toHaveLength(0)
    expect(panelLog.events).toEqual([])
  })

  it('shows no panel when the load failed', async () => {
    expensesApi.getExpense.mockResolvedValue({ ok: false, error: { kind: 'server', status: 500 } })

    const { wrapper } = await render(`/expenses/${ID}`)

    expect(panels(wrapper)).toHaveLength(0)
    expect(panelLog.events).toEqual([])
  })

  it('shows no panel when the expense no longer exists (404 on load)', async () => {
    expensesApi.getExpense.mockResolvedValue({
      ok: false,
      error: { kind: 'not-found', status: 404 },
    })

    const { wrapper } = await render(`/expenses/${ID}`)

    expect(wrapper.text()).toContain('Back to expenses')
    expect(panels(wrapper)).toHaveLength(0)
  })

  it('takes the panel away when a save finds the expense gone (404)', async () => {
    expensesApi.getExpense.mockResolvedValue(ok(expense()))
    expensesApi.updateExpense.mockResolvedValue({
      ok: false,
      error: { kind: 'not-found', status: 404 },
    })
    const { wrapper } = await render(`/expenses/${ID}`)
    await wrapper.get('#expense-description').setValue('Edited')

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('Back to expenses')
    expect(panels(wrapper)).toHaveLength(0)
    expect(panelLog.events).toEqual([`mount:${ID}`, `unmount:${ID}`])
  })

  it('keeps the same panel (no remount) across a failed save of a live expense', async () => {
    expensesApi.getExpense.mockResolvedValue(ok(expense()))
    expensesApi.updateExpense.mockResolvedValue({
      ok: false,
      error: { kind: 'server', status: 500 },
    })
    const { wrapper } = await render(`/expenses/${ID}`)
    await wrapper.get('#expense-description').setValue('Edited')

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(panels(wrapper)).toHaveLength(1)
    expect(panelLog.events).toEqual([`mount:${ID}`])
  })
})

describe('ExpenseFormView: a changed route', () => {
  it('unmounts the panel of A and mounts a new one for B, never showing A under B', async () => {
    expensesApi.getExpense.mockImplementation((id: string) => Promise.resolve(ok(expense(id))))
    const { wrapper, router } = await render(`/expenses/${ID}`)
    expect(panelLog.events).toEqual([`mount:${ID}`])

    await router.push(`/expenses/${OTHER_ID}`)
    await flushPromises()

    expect(panelLog.events).toEqual([`mount:${ID}`, `unmount:${ID}`, `mount:${OTHER_ID}`])
    expect(panels(wrapper)).toHaveLength(1)
    expect(panels(wrapper)[0]?.props('expenseId')).toBe(OTHER_ID)
  })

  it('shows no panel for B while B is still loading', async () => {
    expensesApi.getExpense.mockResolvedValueOnce(ok(expense()))
    expensesApi.getExpense.mockReturnValueOnce(new Promise(() => {}))
    const { wrapper, router } = await render(`/expenses/${ID}`)

    await router.push(`/expenses/${OTHER_ID}`)
    await flushPromises()

    expect(panels(wrapper)).toHaveLength(0)
    expect(panelLog.events).toEqual([`mount:${ID}`, `unmount:${ID}`])
  })

  it('takes the panel away and shows the hint when the route becomes the new form', async () => {
    expensesApi.getExpense.mockResolvedValue(ok(expense()))
    const { wrapper, router } = await render(`/expenses/${ID}`)

    await router.push('/expenses/new')
    await flushPromises()

    expect(panels(wrapper)).toHaveLength(0)
    expect(wrapper.text()).toContain(HINT)
    expect(panelLog.events).toEqual([`mount:${ID}`, `unmount:${ID}`])
  })
})
