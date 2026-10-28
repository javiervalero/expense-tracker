import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, getActivePinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

import type { ApiResult } from '@/api/http'
import type { ApiError } from '@/api/problem'
import type { Category, Expense } from '@/api/types'
import { clearFlash, flashMessage } from '@/lib/flash'
import ConfirmDeleteButton from '@/components/ConfirmDeleteButton.vue'
import ExpenseFormView from '../ExpenseFormView.vue'

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
const FOOD = '0b7c1f0e-5d3a-4c8e-9a41-2f6d8e7b1a90'
const TRAVEL = '1b7c1f0e-5d3a-4c8e-9a41-2f6d8e7b1a99'

function cat(id: string, name: string): Category {
  return {
    id,
    name,
    version: 1,
    createdAt: '2026-10-01T10:00:00Z',
    updatedAt: '2026-10-01T10:00:00Z',
  }
}
function expense(overrides: Partial<Expense> = {}): Expense {
  return {
    id: ID,
    date: '2026-10-01',
    amountCents: 1250,
    description: 'Lunch',
    categoryId: FOOD,
    version: 3,
    createdAt: '2026-10-01T10:00:00Z',
    updatedAt: '2026-10-01T10:00:00Z',
    ...overrides,
  }
}
function ok<T>(data: T, status = 200): ApiResult<T> {
  return { ok: true, status, data }
}
function failure(error: ApiError): ApiResult<never> {
  return { ok: false, error }
}
const listOf = (...items: Category[]) => ok({ items })
const stub = { template: '<p>stub</p>' }

async function render(
  path: string,
  categories: ApiResult<unknown> = listOf(cat(FOOD, 'Food'), cat(TRAVEL, 'Travel')),
) {
  categoriesApi.listCategories.mockResolvedValue(categories)
  const pinia = createPinia()
  setActivePinia(pinia)
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
type Page = Awaited<ReturnType<typeof render>>['wrapper']

async function fill(
  page: Page,
  values: { date?: string; amount?: string; description?: string; category?: string },
) {
  if (values.date !== undefined) await page.get('#expense-date').setValue(values.date)
  if (values.amount !== undefined) await page.get('#expense-amount').setValue(values.amount)
  if (values.description !== undefined)
    await page.get('#expense-description').setValue(values.description)
  if (values.category !== undefined) await page.get('#expense-categoryId').setValue(values.category)
}
async function submit(page: Page) {
  await page.get('form').trigger('submit')
  await flushPromises()
}
function button(page: Page, text: string) {
  const found = page.findAll('button').find((b) => b.text() === text)
  if (!found) throw new Error(`no button "${text}"`)
  return found
}

beforeEach(() => {
  Object.values(expensesApi).forEach((mock) => mock.mockReset())
  categoriesApi.listCategories.mockReset()
  clearFlash()
})
afterEach(() => {
  vi.useRealTimers()
  document.body.replaceChildren()
})

describe('ExpenseFormView: new', () => {
  it('says the categories could not be loaded, and offers none, when the refresh fails', async () => {
    const { wrapper } = await render('/expenses/new', failure({ kind: 'network' }))

    expect(wrapper.findAll('#expense-categoryId option').map((o) => o.text())).toEqual([
      'Uncategorized',
    ])
    expect(wrapper.text()).toContain('Categories could not be loaded')
  })

  it('ignores a categories answer that arrives after the form is gone', async () => {
    const late = deferred<unknown>()
    categoriesApi.listCategories.mockReturnValue(late.promise)
    const pinia = createPinia()
    setActivePinia(pinia)
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/expenses/new', name: 'expense-new', component: ExpenseFormView }],
    })
    await router.push('/expenses/new')
    const gone = mount(ExpenseFormView, { global: { plugins: [pinia, router] } })
    gone.unmount()
    expect(categoriesApi.listCategories).toHaveBeenCalledOnce()

    late.resolve(listOf(cat(FOOD, 'Food')))
    await flushPromises()

    expect(gone.text()).not.toContain('Food')
  })

  it("shows no cached category name while this mount's own request is pending", async () => {
    const { wrapper, router } = await render('/expenses/new')
    expect(wrapper.get('#expense-categoryId').text()).toContain('Food')
    wrapper.unmount()
    categoriesApi.listCategories.mockReset()
    categoriesApi.listCategories.mockReturnValue(new Promise(() => {}))

    const again = mount(ExpenseFormView, { global: { plugins: [getActivePinia()!, router] } })
    await flushPromises()

    expect(again.findAll('#expense-categoryId option').map((o) => o.text())).toEqual([
      'Uncategorized',
    ])
    expect(again.text()).toContain('Loading categories…')
    expect(again.text()).not.toContain('Food')
  })

  it('refreshes the categories on every mount of the new form, not only the first', async () => {
    const { wrapper, router } = await render('/expenses/new')
    wrapper.unmount()

    mount(ExpenseFormView, { global: { plugins: [getActivePinia()!, router] } })
    await flushPromises()

    expect(categoriesApi.listCategories).toHaveBeenCalledTimes(2)
  })

  it('has one h1, and every field has a label', async () => {
    const { wrapper } = await render('/expenses/new')

    expect(wrapper.findAll('h1')).toHaveLength(1)
    expect(wrapper.get('h1').text()).toBe('New expense')
    for (const id of ['date', 'amount', 'description', 'categoryId']) {
      expect(wrapper.find(`label[for="expense-${id}"]`).exists()).toBe(true)
    }
    expect(wrapper.get('form').attributes('novalidate')).toBeDefined()
    expect(wrapper.get('label[for="expense-amount"]').text()).toBe('Amount (EUR)')
  })

  it('defaults the date to today in the local calendar', async () => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date(2026, 9, 28, 23, 30))

    const { wrapper } = await render('/expenses/new')

    expect((wrapper.get('#expense-date').element as HTMLInputElement).value).toBe('2026-10-28')
    expect(wrapper.get('#expense-date').attributes('min')).toBe('2000-01-01')
    expect(wrapper.get('#expense-date').attributes('max')).toBe('2099-12-31')
  })

  it('lists Uncategorized plus the categories in the select', async () => {
    const { wrapper } = await render('/expenses/new')

    const options = wrapper.findAll('#expense-categoryId option').map((o) => o.text())
    expect(options).toEqual(['Uncategorized', 'Food', 'Travel'])
    expect(wrapper.find('a[href="/categories"]').exists()).toBe(false)
  })

  it('shows the Add a category link when there are no categories', async () => {
    const { wrapper } = await render('/expenses/new', listOf())

    expect(wrapper.findAll('#expense-categoryId option').map((o) => o.text())).toEqual([
      'Uncategorized',
    ])
    const link = wrapper.get('a[href="/categories"]')
    expect(link.text()).toBe('Add a category')
  })

  it('sends 12,50 as the integer 1250 and leaves categoryId out for Uncategorized', async () => {
    expensesApi.createExpense.mockResolvedValue(ok(expense(), 201))
    const { wrapper } = await render('/expenses/new')

    await fill(wrapper, { date: '2026-10-01', amount: '12,50', description: '  Lunch  ' })
    await submit(wrapper)

    expect(expensesApi.createExpense).toHaveBeenCalledTimes(1)
    const body = expensesApi.createExpense.mock.calls[0]?.[0] as Record<string, unknown>
    expect(body).toEqual({ date: '2026-10-01', amountCents: 1250, description: 'Lunch' })
    expect(Number.isInteger(body.amountCents)).toBe(true)
    expect('categoryId' in body).toBe(false)
  })

  it('sends the chosen category id', async () => {
    expensesApi.createExpense.mockResolvedValue(ok(expense(), 201))
    const { wrapper } = await render('/expenses/new')

    await fill(wrapper, { date: '2026-10-01', amount: '5', description: 'Bus', category: TRAVEL })
    await submit(wrapper)

    expect(expensesApi.createExpense).toHaveBeenCalledWith({
      date: '2026-10-01',
      amountCents: 500,
      description: 'Bus',
      categoryId: TRAVEL,
    })
  })

  it('goes to /expenses and announces "Expense saved" after a 201', async () => {
    expensesApi.createExpense.mockResolvedValue(ok(expense(), 201))
    const { wrapper, router } = await render('/expenses/new')

    await fill(wrapper, { amount: '12.50', description: 'Lunch' })
    await submit(wrapper)

    expect(router.currentRoute.value.path).toBe('/expenses')
    expect(flashMessage.value).toBe('Expense saved')
  })

  it('shows the field messages and focuses the first invalid field without sending', async () => {
    const { wrapper } = await render('/expenses/new')

    await fill(wrapper, { amount: 'abc', description: '' })
    await submit(wrapper)

    expect(expensesApi.createExpense).not.toHaveBeenCalled()
    const amount = wrapper.get('#expense-amount')
    expect(amount.attributes('aria-invalid')).toBe('true')
    expect(amount.attributes('aria-describedby')).toContain('expense-amount-error')
    expect(wrapper.get('#expense-amount-error').text()).toBe('Enter an amount such as 12.50.')
    expect(wrapper.get('#expense-description-error').text()).toBe('Enter a description.')
    expect(document.activeElement).toBe(amount.element)
  })

  it('on a 422 at /categoryId refreshes the categories and shows the field message', async () => {
    expensesApi.createExpense.mockResolvedValue(
      failure({
        kind: 'validation',
        status: 422,
        fieldErrors: { categoryId: 'This category does not exist.' },
        formErrors: [],
      }),
    )
    const { wrapper } = await render('/expenses/new')
    categoriesApi.listCategories.mockClear()
    categoriesApi.listCategories.mockResolvedValue(listOf(cat(TRAVEL, 'Travel')))

    await fill(wrapper, { amount: '5', description: 'Bus', category: FOOD })
    await submit(wrapper)

    expect(categoriesApi.listCategories).toHaveBeenCalledTimes(1)
    expect(wrapper.get('#expense-categoryId-error').text()).toBe('This category does not exist.')
    expect(wrapper.findAll('#expense-categoryId option').map((o) => o.text())).toEqual([
      'Uncategorized',
      'Travel',
    ])
    expect(document.activeElement).toBe(wrapper.get('#expense-categoryId').element)
  })

  it('sends one request on a double click', async () => {
    let finish: (value: ApiResult<Expense>) => void = () => {}
    expensesApi.createExpense.mockReturnValue(new Promise((resolve) => (finish = resolve)))
    const { wrapper } = await render('/expenses/new')
    await fill(wrapper, { amount: '5', description: 'Bus' })

    await wrapper.get('form').trigger('submit')
    await wrapper.get('form').trigger('submit')

    expect(expensesApi.createExpense).toHaveBeenCalledTimes(1)
    expect(button(wrapper, 'Saving…').attributes('aria-disabled')).toBe('true')
    finish(ok(expense(), 201))
    await flushPromises()
  })

  it('shows the "may have been saved" hint after a timeout and keeps the data', async () => {
    expensesApi.createExpense.mockResolvedValue(failure({ kind: 'timeout' }))
    const { wrapper } = await render('/expenses/new')

    await fill(wrapper, { amount: '5', description: 'Bus' })
    await submit(wrapper)

    expect(wrapper.get('[role="alert"]').text()).toContain('It may have been saved')
    expect((wrapper.get('#expense-description').element as HTMLInputElement).value).toBe('Bus')
    expect(document.activeElement).toBe(wrapper.get('[role="alert"]').element)
    expect(button(wrapper, 'Save').attributes('aria-disabled')).toBeUndefined()
  })

  it('has no delete button', async () => {
    const { wrapper } = await render('/expenses/new')

    expect(wrapper.findAll('button').map((b) => b.text())).toEqual(['Save'])
  })

  it('renders text as text, never as markup', async () => {
    expensesApi.createExpense.mockResolvedValue(
      failure({ kind: 'conflict', status: 409, detail: '<img src=x onerror=alert(1)>' }),
    )
    const { wrapper } = await render('/expenses/new')
    await fill(wrapper, { amount: '5', description: '<img src=x onerror=alert(1)>' })
    await submit(wrapper)

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.get('[role="alert"]').text()).toBe('<img src=x onerror=alert(1)>')
  })
})

describe('ExpenseFormView: edit', () => {
  const path = `/expenses/${ID}`

  it('loads the expense by id and pre-fills the form', async () => {
    expensesApi.getExpense.mockResolvedValue(ok(expense()))
    const { wrapper } = await render(path)

    expect(expensesApi.getExpense).toHaveBeenCalledWith(ID)
    expect(wrapper.get('h1').text()).toBe('Edit expense')
    expect((wrapper.get('#expense-date').element as HTMLInputElement).value).toBe('2026-10-01')
    expect((wrapper.get('#expense-amount').element as HTMLInputElement).value).toBe('12.50')
    expect((wrapper.get('#expense-description').element as HTMLInputElement).value).toBe('Lunch')
    expect((wrapper.get('#expense-categoryId').element as HTMLSelectElement).value).toBe(FOOD)
  })

  it('says it is loading until the expense arrives', async () => {
    expensesApi.getExpense.mockReturnValue(new Promise(() => {}))
    const { wrapper } = await render(path)

    expect(wrapper.text()).toContain('Loading expense…')
    expect(wrapper.find('form').exists()).toBe(false)
  })

  it('shows "no longer exists" with a link to the list on a 404', async () => {
    expensesApi.getExpense.mockResolvedValue(failure({ kind: 'not-found', status: 404 }))
    const { wrapper } = await render(path)

    expect(wrapper.get('[role="alert"]').text()).toBe('This expense no longer exists.')
    expect(wrapper.get('a[href="/expenses"]').text()).toBe('Back to expenses')
    expect(wrapper.find('form').exists()).toBe(false)
  })

  it('offers Retry when the load fails for another reason', async () => {
    expensesApi.getExpense.mockResolvedValueOnce(failure({ kind: 'network' }))
    const { wrapper } = await render(path)
    expect(wrapper.get('[role="alert"]').text()).toContain("Can't reach the server")
    expect(wrapper.find('a[href="/expenses"]').exists()).toBe(false)

    expensesApi.getExpense.mockResolvedValueOnce(ok(expense()))
    await button(wrapper, 'Retry').trigger('click')
    await flushPromises()

    expect(wrapper.find('form').exists()).toBe(true)
  })

  it('treats a category that is not in the list as Uncategorized after one refresh', async () => {
    expensesApi.getExpense.mockResolvedValue(ok(expense({ categoryId: TRAVEL })))
    const { wrapper } = await render(path, listOf(cat(FOOD, 'Food')))

    expect(categoriesApi.listCategories).toHaveBeenCalledTimes(2)
    expect((wrapper.get('#expense-categoryId').element as HTMLSelectElement).value).toBe('')
    await submit(wrapper)
    expect(expensesApi.updateExpense).not.toHaveBeenCalled()
    expect(wrapper.get('[role="status"]').text()).toBe('Nothing to save')
  })

  it('picks the category up when the one refresh finds it', async () => {
    expensesApi.getExpense.mockResolvedValue(ok(expense({ categoryId: TRAVEL })))
    categoriesApi.listCategories.mockResolvedValueOnce(listOf(cat(FOOD, 'Food')))
    const { wrapper } = await render(path)

    expect(categoriesApi.listCategories).toHaveBeenCalledTimes(2)
    expect((wrapper.get('#expense-categoryId').element as HTMLSelectElement).value).toBe(TRAVEL)
  })

  it('sends no request and says "Nothing to save" when nothing changed', async () => {
    expensesApi.getExpense.mockResolvedValue(ok(expense()))
    const { wrapper } = await render(path)

    await fill(wrapper, { description: '  Lunch ' })
    await submit(wrapper)

    expect(expensesApi.updateExpense).not.toHaveBeenCalled()
    expect(wrapper.get('[role="status"]').text()).toBe('Nothing to save')
  })

  it('sends the version and only the changed members, then announces and leaves', async () => {
    expensesApi.getExpense.mockResolvedValue(ok(expense()))
    expensesApi.updateExpense.mockResolvedValue(ok(expense({ amountCents: 1300, version: 4 })))
    const { wrapper, router } = await render(path)

    await fill(wrapper, { amount: '13' })
    await submit(wrapper)

    expect(expensesApi.updateExpense).toHaveBeenCalledWith(ID, { version: 3, amountCents: 1300 })
    expect(router.currentRoute.value.path).toBe('/expenses')
    expect(flashMessage.value).toBe('Expense saved')
  })

  it('sends categoryId null when the category is changed to Uncategorized', async () => {
    expensesApi.getExpense.mockResolvedValue(ok(expense()))
    expensesApi.updateExpense.mockResolvedValue(ok(expense({ categoryId: null })))
    const { wrapper } = await render(path)

    await fill(wrapper, { category: '' })
    await submit(wrapper)

    expect(expensesApi.updateExpense).toHaveBeenCalledWith(ID, { version: 3, categoryId: null })
  })

  it('on a 409 shows the server detail and Reload, which re-fetches and resets the form', async () => {
    expensesApi.getExpense.mockResolvedValueOnce(ok(expense()))
    expensesApi.updateExpense.mockResolvedValue(
      failure({ kind: 'conflict', status: 409, detail: 'The expense was changed elsewhere.' }),
    )
    const { wrapper } = await render(path)
    await fill(wrapper, { description: 'Dinner' })
    await submit(wrapper)
    expect(wrapper.get('[role="alert"]').text()).toBe('The expense was changed elsewhere.')
    expect(wrapper.text()).toContain('unsaved changes')

    expensesApi.getExpense.mockResolvedValueOnce(ok(expense({ description: 'Brunch', version: 4 })))
    await button(wrapper, 'Reload').trigger('click')
    await flushPromises()

    expect(expensesApi.getExpense).toHaveBeenCalledTimes(2)
    expect((wrapper.get('#expense-description').element as HTMLInputElement).value).toBe('Brunch')
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(wrapper.find('button.reload').exists()).toBe(false)
    expect(document.activeElement).toBe(wrapper.get('h1').element)

    expensesApi.updateExpense.mockResolvedValue(ok(expense()))
    await fill(wrapper, { description: 'Supper' })
    await submit(wrapper)
    expect(expensesApi.updateExpense).toHaveBeenLastCalledWith(ID, {
      version: 4,
      description: 'Supper',
    })
  })

  it('shows why a Reload failed and keeps the form', async () => {
    expensesApi.getExpense.mockResolvedValueOnce(ok(expense()))
    expensesApi.updateExpense.mockResolvedValue(
      failure({ kind: 'conflict', status: 409, detail: null }),
    )
    const { wrapper } = await render(path)
    await fill(wrapper, { description: 'Dinner' })
    await submit(wrapper)

    expensesApi.getExpense.mockResolvedValueOnce(failure({ kind: 'not-found', status: 404 }))
    await button(wrapper, 'Reload').trigger('click')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toBe('This expense no longer exists.')
    expect(wrapper.find('a[href="/expenses"]').exists()).toBe(true)
    expect(wrapper.find('button.reload').exists()).toBe(true)
  })

  it('shows "no longer exists" with a link when the save gets a 404', async () => {
    expensesApi.getExpense.mockResolvedValue(ok(expense()))
    expensesApi.updateExpense.mockResolvedValue(failure({ kind: 'not-found', status: 404 }))
    const { wrapper } = await render(path)

    await fill(wrapper, { amount: '20' })
    await submit(wrapper)

    expect(wrapper.get('[role="alert"]').text()).toBe('This expense no longer exists.')
    expect(wrapper.get('a[href="/expenses"]').text()).toBe('Back to expenses')
  })

  it('shows a 422 with an empty pointer at form level', async () => {
    expensesApi.getExpense.mockResolvedValue(ok(expense()))
    expensesApi.updateExpense.mockResolvedValue(
      failure({
        kind: 'validation',
        status: 422,
        fieldErrors: {},
        formErrors: ['Change at least one member.'],
      }),
    )
    const { wrapper } = await render(path)

    await fill(wrapper, { amount: '20' })
    await submit(wrapper)

    expect(wrapper.get('[role="alert"]').text()).toBe('Change at least one member.')
    expect(wrapper.find('.form-field-error').exists()).toBe(false)
  })

  it('deletes after a confirmation, announces it and goes back to the list', async () => {
    expensesApi.getExpense.mockResolvedValue(ok(expense()))
    expensesApi.deleteExpense.mockResolvedValue(ok(undefined, 204))
    const { wrapper, router } = await render(path)

    await button(wrapper, 'Delete').trigger('click')
    await button(wrapper, 'Confirm delete').trigger('click')
    await flushPromises()

    expect(expensesApi.deleteExpense).toHaveBeenCalledWith(ID)
    expect(router.currentRoute.value.path).toBe('/expenses')
    expect(flashMessage.value).toBe('Expense deleted')
  })

  it('treats a 404 on delete as "Already deleted" and goes back to the list', async () => {
    expensesApi.getExpense.mockResolvedValue(ok(expense()))
    expensesApi.deleteExpense.mockResolvedValue(failure({ kind: 'not-found', status: 404 }))
    const { wrapper, router } = await render(path)

    await button(wrapper, 'Delete').trigger('click')
    await button(wrapper, 'Confirm delete').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/expenses')
    expect(flashMessage.value).toBe('Already deleted')
  })

  it('stays on the page with a message when the delete fails', async () => {
    expensesApi.getExpense.mockResolvedValue(ok(expense()))
    expensesApi.deleteExpense.mockResolvedValue(failure({ kind: 'timeout' }))
    const { wrapper, router } = await render(path)

    await button(wrapper, 'Delete').trigger('click')
    await button(wrapper, 'Confirm delete').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.path).toBe(path)
    expect(wrapper.get('[role="alert"]').text()).toContain('It may have been saved')
  })

  it('sends one load request on a double click of Reload', async () => {
    expensesApi.getExpense.mockResolvedValueOnce(ok(expense()))
    expensesApi.updateExpense.mockResolvedValue(
      failure({ kind: 'conflict', status: 409, detail: null }),
    )
    const { wrapper } = await render(path)
    await fill(wrapper, { description: 'Dinner' })
    await submit(wrapper)
    expensesApi.getExpense.mockReturnValue(new Promise(() => {}))

    await button(wrapper, 'Reload').trigger('click')
    await button(wrapper, 'Reload').trigger('click')

    expect(expensesApi.getExpense).toHaveBeenCalledTimes(2)
  })

  it('ignores a delete confirmed while a save is pending', async () => {
    expensesApi.getExpense.mockResolvedValue(ok(expense()))
    expensesApi.updateExpense.mockReturnValue(new Promise(() => {}))
    const { wrapper } = await render(path)
    await fill(wrapper, { amount: '20' })
    await wrapper.get('form').trigger('submit')

    wrapper.findComponent(ConfirmDeleteButton).vm.$emit('confirm')
    await flushPromises()

    expect(expensesApi.deleteExpense).not.toHaveBeenCalled()
  })
})

function deferred<T>() {
  let resolve: (value: T) => void = () => {}
  const promise = new Promise<T>((r) => (resolve = r))
  return { promise, resolve }
}
const OTHER_ID = '7c2d1f0e-5d3a-4c8e-9a41-2f6d8e7b1a92'

describe('ExpenseFormView: late answers and a changed route', () => {
  const path = `/expenses/${ID}`

  async function startSave(wrapper: Page) {
    await fill(wrapper, { amount: '20' })
    await wrapper.get('form').trigger('submit')
  }

  it('does not navigate to the list when a save succeeds after the user went elsewhere, but announces it', async () => {
    expensesApi.getExpense.mockResolvedValue(ok(expense()))
    const late = deferred<ApiResult<Expense>>()
    expensesApi.updateExpense.mockReturnValue(late.promise)
    const { wrapper, router } = await render(path)
    await startSave(wrapper)

    await router.push('/categories')
    late.resolve(ok(expense({ version: 4 })))
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/categories')
    expect(flashMessage.value).toBe('Expense saved')
  })

  it('ignores a save that fails after the user went elsewhere', async () => {
    expensesApi.getExpense.mockResolvedValue(ok(expense()))
    const late = deferred<ApiResult<Expense>>()
    expensesApi.updateExpense.mockReturnValue(late.promise)
    const { wrapper, router } = await render(path)
    await startSave(wrapper)

    await router.push('/categories')
    late.resolve(failure({ kind: 'network' }))
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/categories')
    expect(flashMessage.value).toBe('')
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
  })

  it('does not navigate when a delete finishes after the user went elsewhere, but announces it', async () => {
    expensesApi.getExpense.mockResolvedValue(ok(expense()))
    const late = deferred<ApiResult<void>>()
    expensesApi.deleteExpense.mockReturnValue(late.promise)
    const { wrapper, router } = await render(path)
    await button(wrapper, 'Delete').trigger('click')
    await button(wrapper, 'Confirm delete').trigger('click')

    await router.push('/categories')
    late.resolve(failure({ kind: 'not-found', status: 404 }))
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/categories')
    expect(flashMessage.value).toBe('Already deleted')
  })

  it('ignores a delete that fails after the user went elsewhere', async () => {
    expensesApi.getExpense.mockResolvedValue(ok(expense()))
    const late = deferred<ApiResult<void>>()
    expensesApi.deleteExpense.mockReturnValue(late.promise)
    const { wrapper, router } = await render(path)
    await button(wrapper, 'Delete').trigger('click')
    await button(wrapper, 'Confirm delete').trigger('click')

    await router.push('/categories')
    late.resolve(failure({ kind: 'network' }))
    await flushPromises()

    expect(flashMessage.value).toBe('')
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
  })

  it('shows the new expense after /expenses/A becomes /expenses/B, and Save patches B', async () => {
    const late = deferred<ApiResult<Expense>>()
    expensesApi.getExpense.mockReturnValueOnce(late.promise)
    const { wrapper, router } = await render(path)
    expensesApi.getExpense.mockResolvedValueOnce(
      ok(expense({ id: OTHER_ID, description: 'Taxi', version: 7 })),
    )

    await router.push(`/expenses/${OTHER_ID}`)
    await flushPromises()
    late.resolve(ok(expense({ description: 'Lunch of A' })))
    await flushPromises()

    expect((wrapper.get('#expense-description').element as HTMLInputElement).value).toBe('Taxi')
    expensesApi.updateExpense.mockResolvedValue(ok(expense()))
    await fill(wrapper, { amount: '20' })
    await submit(wrapper)
    expect(expensesApi.updateExpense).toHaveBeenCalledWith(OTHER_ID, {
      version: 7,
      amountCents: 2000,
    })
  })

  it('drops the form state of A when the route changes to B', async () => {
    expensesApi.getExpense.mockResolvedValueOnce(ok(expense()))
    expensesApi.updateExpense.mockResolvedValue(
      failure({ kind: 'conflict', status: 409, detail: 'Changed.' }),
    )
    const { wrapper, router } = await render(path)
    await fill(wrapper, { description: 'Edited' })
    await submit(wrapper)
    expect(wrapper.find('button.reload').exists()).toBe(true)
    const hold = deferred<ApiResult<Expense>>()
    expensesApi.getExpense.mockReturnValueOnce(hold.promise)

    await router.push(`/expenses/${OTHER_ID}`)
    await flushPromises()

    expect(wrapper.text()).toContain('Loading expense…')
    expect(wrapper.find('button.reload').exists()).toBe(false)
    hold.resolve(ok(expense({ id: OTHER_ID, description: 'Taxi' })))
    await flushPromises()
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect((wrapper.get('#expense-description').element as HTMLInputElement).value).toBe('Taxi')
  })

  it('becomes an empty new-expense form when the route changes from edit to new', async () => {
    expensesApi.getExpense.mockResolvedValue(ok(expense()))
    const { wrapper, router } = await render(path)

    await router.push('/expenses/new')
    await flushPromises()

    expect(wrapper.get('h1').text()).toBe('New expense')
    expect((wrapper.get('#expense-description').element as HTMLInputElement).value).toBe('')
    expect(wrapper.find('button.danger, [role="group"]').exists()).toBe(false)
    expect(wrapper.findAll('button').map((b) => b.text())).toEqual(['Save'])
  })

  it('drops a Reload answer that arrives after the route changed', async () => {
    expensesApi.getExpense.mockResolvedValueOnce(ok(expense()))
    expensesApi.updateExpense.mockResolvedValue(
      failure({ kind: 'conflict', status: 409, detail: 'Changed.' }),
    )
    const { wrapper, router } = await render(path)
    await fill(wrapper, { description: 'Edited' })
    await submit(wrapper)
    const late = deferred<ApiResult<Expense>>()
    expensesApi.getExpense.mockReturnValueOnce(late.promise)
    await button(wrapper, 'Reload').trigger('click')
    expensesApi.getExpense.mockResolvedValueOnce(ok(expense({ id: OTHER_ID, description: 'Taxi' })))

    await router.push(`/expenses/${OTHER_ID}`)
    await flushPromises()
    late.resolve(ok(expense({ description: 'Late A' })))
    await flushPromises()

    expect((wrapper.get('#expense-description').element as HTMLInputElement).value).toBe('Taxi')
    expect(button(wrapper, 'Save').attributes('aria-disabled')).toBeUndefined()
  })

  it('drops a category lookup that finishes after the route changed', async () => {
    const lookup = deferred<ApiResult<{ items: Category[] }>>()
    categoriesApi.listCategories.mockResolvedValueOnce(listOf(cat(FOOD, 'Food')))
    categoriesApi.listCategories.mockReturnValueOnce(lookup.promise)
    expensesApi.getExpense.mockResolvedValueOnce(ok(expense({ categoryId: TRAVEL })))
    const { wrapper, router } = await render(path)
    expensesApi.getExpense.mockResolvedValueOnce(
      ok(expense({ id: OTHER_ID, description: 'Taxi', categoryId: FOOD })),
    )

    await router.push(`/expenses/${OTHER_ID}`)
    await flushPromises()
    lookup.resolve(listOf(cat(FOOD, 'Food'), cat(TRAVEL, 'Travel')))
    await flushPromises()

    expect((wrapper.get('#expense-description').element as HTMLInputElement).value).toBe('Taxi')
    expect((wrapper.get('#expense-categoryId').element as HTMLSelectElement).value).toBe(FOOD)
  })

  it('drops a Reload whose category lookup finishes after the route changed', async () => {
    const lookup = deferred<ApiResult<{ items: Category[] }>>()
    categoriesApi.listCategories.mockResolvedValueOnce(listOf(cat(FOOD, 'Food')))
    expensesApi.getExpense.mockResolvedValueOnce(ok(expense()))
    expensesApi.updateExpense.mockResolvedValue(
      failure({ kind: 'conflict', status: 409, detail: 'Changed.' }),
    )
    const { wrapper, router } = await render(path)
    await fill(wrapper, { description: 'Edited' })
    await submit(wrapper)
    categoriesApi.listCategories.mockReturnValueOnce(lookup.promise)
    expensesApi.getExpense.mockResolvedValueOnce(ok(expense({ categoryId: TRAVEL })))
    await button(wrapper, 'Reload').trigger('click')
    expensesApi.getExpense.mockResolvedValueOnce(ok(expense({ id: OTHER_ID, description: 'Taxi' })))

    await router.push(`/expenses/${OTHER_ID}`)
    await flushPromises()
    lookup.resolve(listOf(cat(FOOD, 'Food'), cat(TRAVEL, 'Travel')))
    await flushPromises()

    expect((wrapper.get('#expense-description').element as HTMLInputElement).value).toBe('Taxi')
    expect(wrapper.find('[role="status"]').text()).toBe('')
  })
})
