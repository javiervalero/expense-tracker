import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

import type { ApiResult } from '@/api/http'
import type { Expense } from '@/api/types'
import { describeExpenseError } from '@/lib/expenseMessages'
import { clearFlash, flashMessage } from '@/lib/flash'
import ExpenseFormView from '../ExpenseFormView.vue'

// Phase 5 WP11 (L6): only the two reads of the expense get an AbortSignal. Saves and deletes are not aborted.

const expensesApi = vi.hoisted(() => ({
  createExpense: vi.fn(),
  getExpense: vi.fn(),
  updateExpense: vi.fn(),
  deleteExpense: vi.fn(),
}))
const categoriesApi = vi.hoisted(() => ({ listCategories: vi.fn() }))
vi.mock('@/api/expenses', () => expensesApi)
vi.mock('@/api/categories', () => categoriesApi)

const ID = '5a1c1f0e-5d3a-4c8e-9a41-2f6d8e7b1a91'
const OTHER_ID = '7c2d1f0e-5d3a-4c8e-9a41-2f6d8e7b1a92'
const ABORTED: ApiResult<never> = { ok: false, error: { kind: 'aborted' } }

function expense(overrides: Partial<Expense> = {}): Expense {
  return {
    id: ID,
    date: '2026-10-01',
    amountCents: 1250,
    description: 'Lunch',
    categoryId: null,
    version: 3,
    createdAt: '2026-10-01T10:00:00Z',
    updatedAt: '2026-10-01T10:00:00Z',
    ...overrides,
  }
}
const ok = <T>(data: T): ApiResult<T> => ({ ok: true, status: 200, data })
function deferred<T>() {
  let resolve: (value: T) => void = () => {}
  const promise = new Promise<T>((r) => (resolve = r))
  return { promise, resolve }
}
function signalOfCall(index: number): AbortSignal {
  return expensesApi.getExpense.mock.calls[index]?.[1] as AbortSignal
}

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
async function conflictThenReload(wrapper: Awaited<ReturnType<typeof render>>['wrapper']) {
  expensesApi.updateExpense.mockResolvedValue({
    ok: false,
    error: { kind: 'conflict', status: 409, detail: 'Changed.' },
  })
  await wrapper.get('#expense-description').setValue('Edited')
  await wrapper.get('form').trigger('submit')
  await flushPromises()
}
const reloadButton = (wrapper: Awaited<ReturnType<typeof render>>['wrapper']) =>
  wrapper.get('button.reload')

const path = `/expenses/${ID}`

beforeEach(() => {
  Object.values(expensesApi).forEach((mock) => mock.mockReset())
  categoriesApi.listCategories.mockReset()
  clearFlash()
})
afterEach(() => {
  document.body.replaceChildren()
})

describe('ExpenseFormView: abort signals on the reads', () => {
  it('gives the initial load an AbortSignal that is not aborted while the load is current', async () => {
    expensesApi.getExpense.mockResolvedValue(ok(expense()))

    await render(path)

    expect(expensesApi.getExpense.mock.calls[0]?.[0]).toBe(ID)
    expect(signalOfCall(0)).toBeInstanceOf(AbortSignal)
    expect(signalOfCall(0).aborted).toBe(false)
  })

  it('gives Reload its own AbortSignal that is not aborted while it is current', async () => {
    expensesApi.getExpense.mockResolvedValueOnce(ok(expense()))
    const { wrapper } = await render(path)
    await conflictThenReload(wrapper)
    expensesApi.getExpense.mockReturnValueOnce(new Promise(() => {}))

    await reloadButton(wrapper).trigger('click')

    expect(signalOfCall(1)).toBeInstanceOf(AbortSignal)
    expect(signalOfCall(1)).not.toBe(signalOfCall(0))
    expect(signalOfCall(1).aborted).toBe(false)
  })

  it('aborts the load of A, and only that one, when the route changes to expense B', async () => {
    expensesApi.getExpense.mockReturnValueOnce(new Promise(() => {}))
    expensesApi.getExpense.mockReturnValueOnce(new Promise(() => {}))
    const { router } = await render(path)

    await router.push(`/expenses/${OTHER_ID}`)
    await flushPromises()

    expect(signalOfCall(0).aborted).toBe(true)
    expect(signalOfCall(1).aborted).toBe(false)
  })

  it('aborts the load when the route changes to the new form', async () => {
    expensesApi.getExpense.mockReturnValueOnce(new Promise(() => {}))
    const { router } = await render(path)

    await router.push('/expenses/new')
    await flushPromises()

    expect(signalOfCall(0).aborted).toBe(true)
  })

  it('aborts the load when the router moves away from the form', async () => {
    expensesApi.getExpense.mockReturnValueOnce(new Promise(() => {}))
    const { router } = await render(path)

    await router.push('/categories')
    await flushPromises()

    expect(signalOfCall(0).aborted).toBe(true)
  })

  it('aborts the load on unmount', async () => {
    expensesApi.getExpense.mockReturnValueOnce(new Promise(() => {}))
    const { wrapper } = await render(path)

    wrapper.unmount()

    expect(signalOfCall(0).aborted).toBe(true)
  })

  it('aborts a Reload on unmount', async () => {
    expensesApi.getExpense.mockResolvedValueOnce(ok(expense()))
    const { wrapper } = await render(path)
    await conflictThenReload(wrapper)
    expensesApi.getExpense.mockReturnValueOnce(new Promise(() => {}))
    await reloadButton(wrapper).trigger('click')

    wrapper.unmount()

    expect(signalOfCall(1).aborted).toBe(true)
  })

  it('aborts a Reload when the route changes and does not apply its late answer', async () => {
    expensesApi.getExpense.mockResolvedValueOnce(ok(expense()))
    const { wrapper, router } = await render(path)
    await conflictThenReload(wrapper)
    const late = deferred<ApiResult<Expense>>()
    expensesApi.getExpense.mockReturnValueOnce(late.promise)
    await reloadButton(wrapper).trigger('click')
    expensesApi.getExpense.mockResolvedValueOnce(ok(expense({ id: OTHER_ID, description: 'Taxi' })))

    await router.push(`/expenses/${OTHER_ID}`)
    await flushPromises()
    late.resolve(ok(expense({ description: 'Late A' })))
    await flushPromises()

    expect(signalOfCall(1).aborted).toBe(true)
    expect(signalOfCall(2).aborted).toBe(false)
    expect((wrapper.get('#expense-description').element as HTMLInputElement).value).toBe('Taxi')
  })
})

describe('ExpenseFormView: an aborted answer changes nothing', () => {
  it('shows the other expense, and no error, when the aborted answer of the first one arrives after the route changed', async () => {
    const lateA = deferred<ApiResult<Expense>>()
    expensesApi.getExpense.mockReturnValueOnce(lateA.promise)
    expensesApi.getExpense.mockResolvedValueOnce(ok(expense({ id: OTHER_ID, description: 'Taxi' })))
    const { wrapper, router } = await render(path)

    await router.push(`/expenses/${OTHER_ID}`)
    await flushPromises()
    lateA.resolve(ABORTED)
    await flushPromises()

    expect(signalOfCall(0).aborted).toBe(true)
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Retry')
    expect(wrapper.text()).not.toContain('Loading expense')
    expect((wrapper.get('#expense-description').element as HTMLInputElement).value).toBe('Taxi')
    expect(flashMessage.value).toBe('')
  })

  it('shows nothing from the aborted load of an unmounted form', async () => {
    const late = deferred<ApiResult<Expense>>()
    expensesApi.getExpense.mockReturnValueOnce(late.promise)
    const { wrapper } = await render(path)

    wrapper.unmount()
    late.resolve(ABORTED)
    await flushPromises()

    expect(signalOfCall(0).aborted).toBe(true)
    expect(flashMessage.value).toBe('')
  })

  it('ends in the failed state with Retry when an aborted answer arrives although the form never moved on', async () => {
    expensesApi.getExpense.mockResolvedValueOnce(ABORTED)

    const { wrapper } = await render(path)

    expect(wrapper.text()).not.toContain('Loading expense')
    expect(wrapper.get('[role="alert"]').text()).toBe(
      describeExpenseError({ kind: 'aborted' }, false).form,
    )
    expect(wrapper.findAll('button').map((b) => b.text())).toContain('Retry')
    expect(wrapper.find('form').exists()).toBe(false)
  })

  it('gives the Retry after a failed load a new signal, and loads the form', async () => {
    expensesApi.getExpense.mockResolvedValueOnce({ ok: false, error: { kind: 'network' } })
    const { wrapper } = await render(path)
    expensesApi.getExpense.mockResolvedValueOnce(ok(expense({ description: 'Second try' })))

    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'Retry')
      ?.trigger('click')
    await flushPromises()

    expect(signalOfCall(1)).toBeInstanceOf(AbortSignal)
    expect(signalOfCall(1)).not.toBe(signalOfCall(0))
    expect(signalOfCall(1).aborted).toBe(false)
    expect((wrapper.get('#expense-description').element as HTMLInputElement).value).toBe(
      'Second try',
    )
  })

  it('loads normally, with a fresh signal, when the route comes back after a page without the form', async () => {
    expensesApi.getExpense.mockResolvedValue(ok(expense()))
    const { wrapper, router } = await render(path)
    await router.push('/categories')
    await flushPromises()
    expect(signalOfCall(0).aborted).toBe(true)

    await router.push(path)
    await flushPromises()

    expect(expensesApi.getExpense).toHaveBeenCalledTimes(2)
    expect(signalOfCall(1).aborted).toBe(false)
    expect(signalOfCall(1)).not.toBe(signalOfCall(0))
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect((wrapper.get('#expense-description').element as HTMLInputElement).value).toBe('Lunch')
  })

  it('keeps the conflict, and lets Reload be used again, when a Reload fails for another reason', async () => {
    expensesApi.getExpense.mockResolvedValueOnce(ok(expense()))
    const { wrapper } = await render(path)
    await conflictThenReload(wrapper)
    expensesApi.getExpense.mockResolvedValueOnce({ ok: false, error: { kind: 'network' } })

    await reloadButton(wrapper).trigger('click')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toBe(
      describeExpenseError({ kind: 'network' }, false).form,
    )
    expect(wrapper.find('.conflict').exists()).toBe(true)
    expect(reloadButton(wrapper).attributes('aria-disabled')).toBeUndefined()
    expect((wrapper.get('#expense-description').element as HTMLInputElement).value).toBe('Edited')
  })

  it('keeps the form and announces nothing for an aborted Reload', async () => {
    expensesApi.getExpense.mockResolvedValueOnce(ok(expense()))
    const { wrapper } = await render(path)
    await conflictThenReload(wrapper)
    expensesApi.getExpense.mockResolvedValueOnce(ABORTED)

    await reloadButton(wrapper).trigger('click')
    await flushPromises()

    expect((wrapper.get('#expense-description').element as HTMLInputElement).value).toBe('Edited')
    expect(wrapper.text()).not.toContain('Reloaded the latest version.')
    expect(wrapper.get('[role="alert"]').text()).toBe('Changed.')
    expect(wrapper.find('.conflict').exists()).toBe(true)
    expect(reloadButton(wrapper).attributes('aria-disabled')).toBeUndefined()
    expect(flashMessage.value).toBe('')
  })

  it('shows nothing from an aborted answer that arrives after leaving the page', async () => {
    const late = deferred<ApiResult<Expense>>()
    expensesApi.getExpense.mockReturnValueOnce(late.promise)
    const { wrapper, router } = await render(path)
    await router.push('/categories')

    late.resolve(ABORTED)
    await flushPromises()

    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('could not be')
    expect(flashMessage.value).toBe('')
  })
})

describe('ExpenseFormView: saves and deletes are not aborted', () => {
  it('still announces "Expense saved" for a save that finishes after unmount, sent without a signal', async () => {
    expensesApi.getExpense.mockResolvedValue(ok(expense()))
    const late = deferred<ApiResult<Expense>>()
    expensesApi.updateExpense.mockReturnValue(late.promise)
    const { wrapper } = await render(path)
    await wrapper.get('#expense-amount').setValue('20')
    await wrapper.get('form').trigger('submit')

    wrapper.unmount()
    late.resolve(ok(expense({ version: 4 })))
    await flushPromises()

    expect(expensesApi.updateExpense.mock.calls[0]).toHaveLength(2)
    expect(flashMessage.value).toBe('Expense saved')
  })
})

describe('ExpenseFormView: a delete that finishes after the form is gone', () => {
  it('still announces "Expense deleted", sent without a signal', async () => {
    expensesApi.getExpense.mockResolvedValue(ok(expense()))
    const late = deferred<ApiResult<void>>()
    expensesApi.deleteExpense.mockReturnValue(late.promise)
    const { wrapper } = await render(path)
    const press = (label: string) => wrapper.findAll('button').find((b) => b.text() === label)
    await press('Delete')?.trigger('click')
    await press('Confirm delete')?.trigger('click')

    wrapper.unmount()
    late.resolve({ ok: true, status: 204, data: undefined })
    await flushPromises()

    expect(expensesApi.deleteExpense.mock.calls[0]).toEqual([ID])
    expect(flashMessage.value).toBe('Expense deleted')
  })
})
