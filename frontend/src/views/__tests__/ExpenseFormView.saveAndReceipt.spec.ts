import { flushPromises, mount } from '@vue/test-utils'
import type { VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent } from 'vue'
import { createMemoryHistory, createRouter, RouterView } from 'vue-router'

import type { ApiResult } from '@/api/http'
import type { Expense } from '@/api/types'
import { clearFlash, flashMessage } from '@/lib/flash'
import ExpenseFormView from '../ExpenseFormView.vue'

// "Save and add receipt": creates like Save, then opens the edit page of the new expense so the receipt panel is there.

const expensesApi = vi.hoisted(() => ({
  createExpense: vi.fn(),
  getExpense: vi.fn(),
  updateExpense: vi.fn(),
  deleteExpense: vi.fn(),
}))
const categoriesApi = vi.hoisted(() => ({ listCategories: vi.fn() }))
vi.mock('@/api/expenses', () => expensesApi)
vi.mock('@/api/categories', () => categoriesApi)
vi.mock('@/components/ReceiptPanel.vue', async () => {
  const { defineComponent: define, h } = await import('vue')
  return {
    default: define({
      name: 'ReceiptPanelStub',
      props: { expenseId: { type: String, required: true } },
      setup: (props) => () => h('div', { 'data-testid': 'receipt-panel' }, props.expenseId),
    }),
  }
})

const NEW_ID = '9d1c1f0e-5d3a-4c8e-9a41-2f6d8e7b1a93'
const LABEL = 'Save and add receipt'

function expense(): Expense {
  return {
    id: NEW_ID,
    date: '2026-10-01',
    amountCents: 1250,
    description: 'Lunch',
    categoryId: null,
    version: 1,
    createdAt: '2026-10-01T10:00:00Z',
    updatedAt: '2026-10-01T10:00:00Z',
  }
}
const ok = <T>(data: T, status = 200): ApiResult<T> => ({ ok: true, status, data })

// A real router and a RouterView, so that /expenses/new -> /expenses/:id reuses the component instance like the app does.
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
  const Root = defineComponent({ components: { RouterView }, template: '<RouterView />' })
  const wrapper = mount(Root, { attachTo: host, global: { plugins: [pinia, router] } })
  await flushPromises()
  return { wrapper, router }
}

type Wrapper = VueWrapper
const buttons = (wrapper: Wrapper) => wrapper.findAll('button')
function receiptButton(wrapper: Wrapper) {
  return buttons(wrapper).find((button) => button.text() === LABEL)
}
async function fillValid(wrapper: Wrapper) {
  await wrapper.get('#expense-amount').setValue('12.50')
  await wrapper.get('#expense-description').setValue('Lunch')
}
async function pressReceipt(wrapper: Wrapper) {
  await receiptButton(wrapper)?.trigger('click')
  await flushPromises()
}

beforeEach(() => {
  Object.values(expensesApi).forEach((mock) => mock.mockReset())
  categoriesApi.listCategories.mockReset()
  clearFlash()
})
afterEach(() => {
  document.body.replaceChildren()
})

describe('ExpenseFormView: Save and add receipt', () => {
  it('is a plain button on the new form and absent on the edit form', async () => {
    const created = await render('/expenses/new')
    const button = receiptButton(created.wrapper)
    expect(button?.attributes('type')).toBe('button')
    expect(created.wrapper.text()).toContain('To attach a receipt, use Save and add receipt.')
    expect(created.wrapper.text()).not.toContain('You can attach a receipt after saving.')

    document.body.replaceChildren()
    expensesApi.getExpense.mockResolvedValue(ok(expense()))
    const edited = await render(`/expenses/${NEW_ID}`)
    expect(receiptButton(edited.wrapper)).toBeUndefined()
  })

  it('creates the expense, opens its edit page, shows the receipt panel and announces once', async () => {
    expensesApi.createExpense.mockResolvedValue(ok(expense(), 201))
    expensesApi.getExpense.mockResolvedValue(ok(expense()))
    const { wrapper, router } = await render('/expenses/new')
    await fillValid(wrapper)

    await pressReceipt(wrapper)

    expect(expensesApi.createExpense).toHaveBeenCalledTimes(1)
    expect(router.currentRoute.value.name).toBe('expense-edit')
    expect(router.currentRoute.value.params.id).toBe(NEW_ID)
    expect(expensesApi.getExpense).toHaveBeenCalledTimes(1)
    expect(wrapper.get('h1').text()).toBe('Edit expense')
    expect(wrapper.get('[data-testid="receipt-panel"]').text()).toBe(NEW_ID)
    expect((wrapper.get('#expense-description').element as HTMLInputElement).value).toBe('Lunch')
    expect(receiptButton(wrapper)).toBeUndefined()
    expect(wrapper.find('[id$="-error"]').exists()).toBe(false)
    expect(wrapper.find('[role="alert"]:not(:empty)').exists()).toBe(false)
    expect(flashMessage.value).toBe('Expense saved')
    const save = buttons(wrapper).find((button) => button.attributes('type') === 'submit')
    expect(save?.attributes('aria-disabled')).toBeUndefined()
    expect(save?.text()).toBe('Save')
  })

  it('shows the field errors and does not navigate or send when the form is invalid', async () => {
    const { wrapper, router } = await render('/expenses/new')
    await wrapper.get('#expense-amount').setValue('abc')

    await pressReceipt(wrapper)

    expect(expensesApi.createExpense).not.toHaveBeenCalled()
    expect(router.currentRoute.value.path).toBe('/expenses/new')
    expect(wrapper.get('#expense-amount-error').text()).toBe('Enter an amount such as 12.50.')
    expect(document.activeElement).toBe(wrapper.get('#expense-amount').element)
    expect(flashMessage.value).toBe('')
  })

  it.each([
    [{ kind: 'server', status: 503 }],
    [
      {
        kind: 'validation',
        status: 422,
        fieldErrors: { description: 'Too long.' },
        formErrors: [],
      },
    ],
  ])('stays on the form and shows the error like Save (%j)', async (error) => {
    expensesApi.createExpense.mockResolvedValue({ ok: false, error })
    const { wrapper, router } = await render('/expenses/new')
    await fillValid(wrapper)

    await pressReceipt(wrapper)

    expect(expensesApi.createExpense).toHaveBeenCalledTimes(1)
    expect(router.currentRoute.value.path).toBe('/expenses/new')
    expect(flashMessage.value).toBe('')
    expect(wrapper.find('[role="alert"], .error, [id$="-error"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="receipt-panel"]').exists()).toBe(false)
    expect(
      buttons(wrapper).every((button) => button.attributes('aria-disabled') === undefined),
    ).toBe(true)
  })

  it('ignores a second click while the save is pending and aria-disables both buttons', async () => {
    let finish: (value: ApiResult<Expense>) => void = () => {}
    expensesApi.createExpense.mockReturnValue(
      new Promise((resolve) => {
        finish = resolve
      }),
    )
    expensesApi.getExpense.mockResolvedValue(ok(expense()))
    const { wrapper } = await render('/expenses/new')
    await fillValid(wrapper)

    await receiptButton(wrapper)?.trigger('click')
    await receiptButton(wrapper)?.trigger('click')

    expect(expensesApi.createExpense).toHaveBeenCalledTimes(1)
    expect(receiptButton(wrapper)?.attributes('aria-disabled')).toBe('true')
    expect(wrapper.get('button[type="submit"]').attributes('aria-disabled')).toBe('true')
    finish(ok(expense(), 201))
    await flushPromises()
    expect(expensesApi.createExpense).toHaveBeenCalledTimes(1)
  })

  it('does not navigate when the user went elsewhere first, but still announces', async () => {
    let finish: (value: ApiResult<Expense>) => void = () => {}
    expensesApi.createExpense.mockReturnValue(
      new Promise((resolve) => {
        finish = resolve
      }),
    )
    const { wrapper, router } = await render('/expenses/new')
    await fillValid(wrapper)
    await receiptButton(wrapper)?.trigger('click')

    await router.push('/categories')
    await flushPromises()
    finish(ok(expense(), 201))
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/categories')
    expect(expensesApi.getExpense).not.toHaveBeenCalled()
    expect(flashMessage.value).toBe('Expense saved')
  })

  it('leaves Save as it was: creates and goes to /expenses', async () => {
    expensesApi.createExpense.mockResolvedValue(ok(expense(), 201))
    const { wrapper, router } = await render('/expenses/new')
    await fillValid(wrapper)

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/expenses')
    expect(flashMessage.value).toBe('Expense saved')
  })

  it('replaces the blank form in history after Save and add receipt, so Back skips it', async () => {
    expensesApi.createExpense.mockResolvedValue(ok(expense(), 201))
    expensesApi.getExpense.mockResolvedValue(ok(expense()))
    const { wrapper, router } = await render('/expenses/new')
    await fillValid(wrapper)

    await pressReceipt(wrapper)
    expect(router.currentRoute.value.name).toBe('expense-edit')
    router.back()
    await flushPromises()

    expect(router.currentRoute.value.path).not.toBe('/expenses/new')
  })

  it('still pushes on plain Save, so Back returns to the form', async () => {
    expensesApi.createExpense.mockResolvedValue(ok(expense(), 201))
    const { wrapper, router } = await render('/expenses/new')
    await fillValid(wrapper)

    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(router.currentRoute.value.path).toBe('/expenses')
    router.back()
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/expenses/new')
  })

  it('after landing on the edit page, Save updates and never creates again', async () => {
    expensesApi.createExpense.mockResolvedValue(ok(expense(), 201))
    expensesApi.getExpense.mockResolvedValue(ok(expense()))
    expensesApi.updateExpense.mockResolvedValue(
      ok({ ...expense(), description: 'Dinner', version: 2 }),
    )
    const { wrapper, router } = await render('/expenses/new')
    await fillValid(wrapper)
    await pressReceipt(wrapper)
    expect(router.currentRoute.value.name).toBe('expense-edit')

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('Nothing to save')
    expect(expensesApi.updateExpense).not.toHaveBeenCalled()
    expect(expensesApi.createExpense).toHaveBeenCalledTimes(1)

    await wrapper.get('#expense-description').setValue('Dinner')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(expensesApi.updateExpense).toHaveBeenCalledTimes(1)
    expect(expensesApi.updateExpense.mock.calls[0]?.[0]).toBe(NEW_ID)
    expect(expensesApi.createExpense).toHaveBeenCalledTimes(1)
  })
})
