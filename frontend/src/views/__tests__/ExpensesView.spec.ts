import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, getActivePinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

import {
  FOOD,
  TRAVEL,
  category,
  deferred,
  expense,
  failed,
  pageOf,
  uid,
} from '@/api/__tests__/expenseFixtures'
import { clearFlash, flashMessage } from '@/lib/flash'
import ExpensesView from '../ExpensesView.vue'
import source from '../ExpensesView.vue?raw'

const expensesApi = vi.hoisted(() => ({ listExpenses: vi.fn(), deleteExpense: vi.fn() }))
const categoriesApi = vi.hoisted(() => ({ listCategories: vi.fn() }))
vi.mock('@/api/expenses', () => expensesApi)
vi.mock('@/api/categories', () => categoriesApi)

const stub = { template: '<p>stub</p>' }
const NO_CONTENT = { ok: true, status: 204, data: undefined }
const CATEGORIES = {
  ok: true,
  status: 200,
  data: { items: [category(FOOD, 'Food'), category(TRAVEL, 'Travel')] },
}

async function render(
  path = '/expenses',
  categories: unknown = CATEGORIES,
  refreshed: unknown = categories,
) {
  categoriesApi.listCategories.mockResolvedValue(refreshed)
  categoriesApi.listCategories.mockResolvedValueOnce(categories)
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/expenses', name: 'expenses', component: ExpensesView },
      { path: '/expenses/new', name: 'expense-new', component: stub },
      { path: '/expenses/:id', name: 'expense-edit', component: stub },
      { path: '/categories', name: 'categories', component: stub },
    ],
  })
  await router.push(path)
  const host = document.createElement('div')
  document.body.appendChild(host)
  const wrapper = mount(ExpensesView, { attachTo: host, global: { plugins: [pinia, router] } })
  await flushPromises()
  return { wrapper, router }
}
type Page = Awaited<ReturnType<typeof render>>['wrapper']

function button(page: Page, text: string) {
  const found = page.findAll('button').find((b) => b.text() === text)
  if (!found) throw new Error(`no button "${text}"`)
  return found
}
function rows(page: Page) {
  return page.findAll('tbody tr')
}
function rowAt(page: Page, index: number) {
  const found = rows(page).at(index)
  if (!found) throw new Error(`no row ${index}`)
  return found
}
function signalOfCall(index: number): AbortSignal {
  return expensesApi.listExpenses.mock.calls.at(index)?.[1] as AbortSignal
}
function paramsOfCall(index: number): string {
  return (expensesApi.listExpenses.mock.calls.at(index)?.[0] as URLSearchParams).toString()
}
function status(page: Page): string {
  return page.get('.status').text()
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

describe('ExpensesView: structure', () => {
  it("shows no cached category option until this mount's first list response is back", async () => {
    expensesApi.listExpenses.mockResolvedValue(pageOf([expense(uid(1))]))
    const { wrapper, router } = await render()
    expect(wrapper.get('select#filter-category').text()).toContain('Food')
    wrapper.unmount()
    const pending = deferred<unknown>()
    expensesApi.listExpenses.mockReset()
    expensesApi.listExpenses.mockReturnValue(pending.promise)

    const again = mount(ExpensesView, { global: { plugins: [getActivePinia()!, router] } })
    await flushPromises()

    expect(again.get('select#filter-category').text()).not.toContain('Food')
    pending.resolve(pageOf([expense(uid(1))]))
    await flushPromises()
    expect(again.get('select#filter-category').text()).toContain('Food')
  })

  it('has one h1, a results heading, and a table with a caption and column headers', async () => {
    expensesApi.listExpenses.mockResolvedValue(pageOf([expense(uid(1))]))

    const { wrapper } = await render()

    expect(wrapper.findAll('h1')).toHaveLength(1)
    expect(wrapper.get('h1').text()).toBe('Expenses')
    expect(wrapper.get('h2#results-heading').attributes('tabindex')).toBe('-1')
    expect(wrapper.get('table caption').text()).not.toBe('')
    const headers = wrapper.findAll('thead th')
    expect(headers.map((h) => h.attributes('scope'))).toEqual(['col', 'col', 'col', 'col', 'col'])
    expect(wrapper.get('tbody th').attributes('scope')).toBe('row')
  })

  it('has a labelled control for each filter and an Apply submit button', async () => {
    expensesApi.listExpenses.mockResolvedValue(pageOf([]))

    const { wrapper } = await render()

    for (const id of ['from', 'to', 'category', 'q']) {
      expect(wrapper.find(`label[for="filter-${id}"]`).exists()).toBe(true)
      expect(wrapper.find(`#filter-${id}`).exists()).toBe(true)
    }
    expect(wrapper.get('form').attributes('novalidate')).toBeDefined()
    expect(button(wrapper, 'Apply').attributes('type')).toBe('submit')
  })

  it('asks for the first page with limit=50 only', async () => {
    expensesApi.listExpenses.mockResolvedValue(pageOf([]))

    await render()

    expect(expensesApi.listExpenses).toHaveBeenCalledTimes(1)
    expect(paramsOfCall(0)).toBe('limit=50')
  })
})

describe('ExpensesView: rows', () => {
  it('shows the amount formatted and the date in a <time> element', async () => {
    expensesApi.listExpenses.mockResolvedValue(
      pageOf([expense(uid(1), { date: '2026-10-26', amountCents: 123456 })]),
    )

    const { wrapper } = await render()

    expect(rowAt(wrapper, 0).text()).toContain('€1,234.56')
    const time = wrapper.get('tbody time')
    expect(time.attributes('datetime')).toBe('2026-10-26')
    expect(time.text()).toBe('26 Oct 2026')
  })

  it('shows Uncategorized for a null category and the name for a known one', async () => {
    expensesApi.listExpenses.mockResolvedValue(
      pageOf([expense(uid(1), { categoryId: null }), expense(uid(2), { categoryId: FOOD })]),
    )

    const { wrapper } = await render()

    const first = rowAt(wrapper, 0)
    const second = rowAt(wrapper, 1)
    expect(first.text()).toContain('Uncategorized')
    expect(second.text()).toContain('Food')
    expect(categoriesApi.listCategories).toHaveBeenCalledTimes(1)
  })

  it('refreshes the categories once for an unknown id, then shows the new name', async () => {
    const unknown = '2b7c1f0e-5d3a-4c8e-9a41-2f6d8e7b1a91'
    expensesApi.listExpenses.mockResolvedValue(pageOf([expense(uid(1), { categoryId: unknown })]))
    const later = { ok: true, status: 200, data: { items: [category(unknown, 'Gifts')] } }
    const { wrapper } = await render('/expenses', CATEGORIES, later)

    expect(categoriesApi.listCategories).toHaveBeenCalledTimes(2)
    expect(rowAt(wrapper, 0).text()).toContain('Gifts')
  })

  it('shows Uncategorized when the id is still unknown after the refresh, and does not refresh again', async () => {
    const unknown = '2b7c1f0e-5d3a-4c8e-9a41-2f6d8e7b1a91'
    expensesApi.listExpenses
      .mockResolvedValueOnce(pageOf([expense(uid(1), { categoryId: unknown })], 'c1'))
      .mockResolvedValueOnce(pageOf([expense(uid(2), { categoryId: unknown })]))

    const { wrapper } = await render()
    expect(rowAt(wrapper, 0).text()).toContain('Uncategorized')
    expect(categoriesApi.listCategories).toHaveBeenCalledTimes(2)
    await button(wrapper, 'Load more').trigger('click')
    await flushPromises()

    expect(rows(wrapper)).toHaveLength(2)
    expect(categoriesApi.listCategories).toHaveBeenCalledTimes(2)
  })

  it('renders a description with markup and a bidi override as text inside <bdi>', async () => {
    const hostile = '<img src=x onerror=alert(1)>‮fdp.exe'
    expensesApi.listExpenses.mockResolvedValue(pageOf([expense(uid(1), { description: hostile })]))

    const { wrapper } = await render()

    expect(wrapper.find('img').exists()).toBe(false)
    const bdi = wrapper.findAll('tbody bdi').find((b) => b.text().includes('onerror'))
    expect(bdi).toBeDefined()
    expect(bdi!.text()).toBe(hostile)
    expect(bdi!.element.innerHTML).toContain('&lt;img')
  })

  it('renders a hostile category name as text too', async () => {
    const name = '<b onmouseover=alert(1)>x</b>'
    expensesApi.listExpenses.mockResolvedValue(pageOf([expense(uid(1), { categoryId: FOOD })]))
    const hostile = { ok: true, status: 200, data: { items: [category(FOOD, name)] } }

    const { wrapper } = await render('/expenses', hostile)

    expect(wrapper.find('tbody b').exists()).toBe(false)
    expect(wrapper.findAll('tbody bdi').some((b) => b.text() === name)).toBe(true)
  })

  it('shows a dash and no datetime attribute for a date that is not a date', async () => {
    expensesApi.listExpenses.mockResolvedValue(pageOf([expense(uid(1), { date: 'not-a-date' })]))

    const { wrapper } = await render()

    expect(wrapper.get('tbody time').text()).toBe('—')
    expect(wrapper.get('tbody time').attributes('datetime')).toBeUndefined()
  })

  it('links Edit to the route built from the id', async () => {
    expensesApi.listExpenses.mockResolvedValue(pageOf([expense(uid(7))]))

    const { wrapper } = await render()

    const link = wrapper.findAll('tbody a').find((a) => a.text() === 'Edit')
    expect(link?.attributes('href')).toBe(`/expenses/${uid(7)}`)
  })

  it('draws no link and no delete for an id that is not a uuid', async () => {
    expensesApi.listExpenses.mockResolvedValue(
      pageOf([expense('../../auth/logout', { description: 'odd' })]),
    )

    const { wrapper } = await render()

    expect(rows(wrapper)).toHaveLength(1)
    expect(wrapper.findAll('tbody a')).toHaveLength(0)
    expect(wrapper.findAll('tbody button')).toHaveLength(0)
  })
})

describe('ExpensesView: empty and failed states', () => {
  it('says there are no expenses yet when there are no filters, with a link to add one', async () => {
    expensesApi.listExpenses.mockResolvedValue(pageOf([]))

    const { wrapper } = await render()

    expect(wrapper.text()).toContain('No expenses yet')
    expect(wrapper.find('table').exists()).toBe(false)
    const link = wrapper.findAll('a').find((a) => a.attributes('href') === '/expenses/new')
    expect(link).toBeDefined()
    expect(wrapper.get('.empty').text()).not.toContain('Clear filters')
  })

  it('says nothing matches when filters are set, and Clear filters empties the query', async () => {
    expensesApi.listExpenses.mockResolvedValue(pageOf([]))
    const { wrapper, router } = await render('/expenses?q=tea')

    expect(wrapper.text()).toContain('No expenses match these filters')
    await wrapper.get('.empty button').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.query).toEqual({})
    expect(paramsOfCall(1)).toBe('limit=50')
  })

  it('clears from the filter form too: empties the fields and the query, and announces', async () => {
    expensesApi.listExpenses.mockResolvedValueOnce(pageOf([expense(uid(1))]))
    const { wrapper, router } = await render(`/expenses?from=2026-10-01&category=${FOOD}&q=tea`)
    expensesApi.listExpenses.mockResolvedValueOnce(pageOf([expense(uid(1)), expense(uid(2))]))

    await wrapper.get('form button[type="button"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('form button[type="button"]').text()).toBe('Clear filters')
    expect(router.currentRoute.value.query).toEqual({})
    expect(paramsOfCall(1)).toBe('limit=50')
    expect((wrapper.get('#filter-from').element as HTMLInputElement).value).toBe('')
    expect((wrapper.get('#filter-q').element as HTMLInputElement).value).toBe('')
    expect((wrapper.get('#filter-category').element as HTMLSelectElement).value).toBe('')
    expect(status(wrapper)).toBe('2 expenses shown')
    expect(document.activeElement).toBe(wrapper.get('#results-heading').element)
  })

  it('only empties typed, unapplied fields, without a search, when no filter is applied', async () => {
    expensesApi.listExpenses.mockResolvedValue(pageOf([expense(uid(1))]))
    const { wrapper, router } = await render()
    await wrapper.get('#filter-q').setValue('tea')

    await wrapper.get('form button[type="button"]').trigger('click')
    await flushPromises()

    expect((wrapper.get('#filter-q').element as HTMLInputElement).value).toBe('')
    expect(router.currentRoute.value.query).toEqual({})
    expect(expensesApi.listExpenses).toHaveBeenCalledTimes(1)
  })

  it('removes the field errors of a refused Apply when it empties the unapplied fields', async () => {
    expensesApi.listExpenses.mockResolvedValue(pageOf([expense(uid(1))]))
    const { wrapper } = await render()
    await wrapper.get('#filter-from').setValue('2026-10-02')
    await wrapper.get('#filter-to').setValue('2026-10-01')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(wrapper.find('[aria-invalid="true"]').exists()).toBe(true)

    await wrapper.get('form button[type="button"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[aria-invalid]').exists()).toBe(false)
    expect(wrapper.find('#filter-from-error').exists()).toBe(false)
    expect(wrapper.find('#filter-to-error').exists()).toBe(false)
    expect((wrapper.get('#filter-from').element as HTMLInputElement).value).toBe('')
  })

  it('does nothing when nothing is set: no search, no message, focus stays', async () => {
    expensesApi.listExpenses.mockResolvedValue(pageOf([expense(uid(1))]))
    const { wrapper } = await render()
    const clear = wrapper.get('form button[type="button"]')
    ;(clear.element as HTMLElement).focus()

    await clear.trigger('click')
    await flushPromises()

    expect(expensesApi.listExpenses).toHaveBeenCalledTimes(1)
    expect(status(wrapper)).toBe('')
    expect(document.activeElement).toBe(clear.element)
  })

  it('shows a loading text while the first page is on its way', async () => {
    const slow = deferred<ReturnType<typeof pageOf>>()
    expensesApi.listExpenses.mockReturnValue(slow.promise)

    const { wrapper } = await render()

    expect(wrapper.text()).toContain('Loading expenses…')
    slow.resolve(pageOf([]))
    await flushPromises()
    expect(wrapper.text()).not.toContain('Loading expenses…')
  })

  it('shows an alert with Retry when the list cannot be loaded', async () => {
    expensesApi.listExpenses.mockResolvedValueOnce(failed({ kind: 'server', status: 500 }))
    const { wrapper } = await render()

    expect(wrapper.get('[role="alert"]').text()).toBe(
      'Something went wrong on our side. Try again.',
    )
    expensesApi.listExpenses.mockResolvedValueOnce(pageOf([expense(uid(1))]))
    await button(wrapper, 'Retry').trigger('click')
    await flushPromises()

    expect(rows(wrapper)).toHaveLength(1)
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
  })

  it('shows the failure of a Load more without losing the rows', async () => {
    expensesApi.listExpenses
      .mockResolvedValueOnce(pageOf([expense(uid(1))], 'c1'))
      .mockResolvedValueOnce(failed({ kind: 'network' }))
    const { wrapper } = await render()

    await button(wrapper, 'Load more').trigger('click')
    await flushPromises()

    expect(rows(wrapper)).toHaveLength(1)
    expect(wrapper.get('[role="alert"]').text()).toContain("Can't reach the server")
    expect(wrapper.text()).toContain('Load more')
  })
})

describe('ExpensesView: Load more', () => {
  it('appends the next page with the same filters and announces how many were added', async () => {
    expensesApi.listExpenses
      .mockResolvedValueOnce(pageOf([expense(uid(1))], 'c1'))
      .mockResolvedValueOnce(pageOf([expense(uid(2)), expense(uid(3))]))
    const { wrapper } = await render('/expenses?category=none&q=tea')

    await button(wrapper, 'Load more').trigger('click')
    await flushPromises()

    expect(paramsOfCall(1)).toBe('category=none&q=tea&limit=50&cursor=c1')
    expect(rows(wrapper)).toHaveLength(3)
    expect(status(wrapper)).toBe('2 more loaded')
    expect(wrapper.text()).not.toContain('Load more')
  })

  it('never puts the cursor in the page URL or in the DOM', async () => {
    const cursor = 'opaque-cursor-value'
    expensesApi.listExpenses
      .mockResolvedValueOnce(pageOf([expense(uid(1))], cursor))
      .mockResolvedValueOnce(pageOf([expense(uid(2))], 'second-opaque'))
    const { wrapper, router } = await render('/expenses?q=tea')

    await button(wrapper, 'Load more').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.fullPath).toBe('/expenses?q=tea')
    expect(wrapper.html()).not.toContain(cursor)
    expect(wrapper.html()).not.toContain('second-opaque')
  })

  it('keeps the button for a short page that has a cursor', async () => {
    expensesApi.listExpenses.mockResolvedValue(pageOf([expense(uid(1))], 'c1'))

    const { wrapper } = await render()

    expect(button(wrapper, 'Load more')).toBeDefined()
  })

  it('is inert while a request is in flight (aria-disabled, one request)', async () => {
    const slow = deferred<ReturnType<typeof pageOf>>()
    expensesApi.listExpenses
      .mockResolvedValueOnce(pageOf([expense(uid(1))], 'c1'))
      .mockReturnValueOnce(slow.promise)
    const { wrapper } = await render()

    await button(wrapper, 'Load more').trigger('click')
    const busy = button(wrapper, 'Loading…')
    await busy.trigger('click')

    expect(busy.attributes('aria-disabled')).toBe('true')
    expect(expensesApi.listExpenses).toHaveBeenCalledTimes(2)
    slow.resolve(pageOf([]))
    await flushPromises()
  })

  it('offers "Keep searching" after three empty pages in a row', async () => {
    expensesApi.listExpenses
      .mockResolvedValueOnce(pageOf([expense(uid(1))], 'c1'))
      .mockResolvedValue(pageOf([], 'cn'))
    const { wrapper } = await render()

    await button(wrapper, 'Load more').trigger('click')
    await flushPromises()

    expect(expensesApi.listExpenses).toHaveBeenCalledTimes(5)
    expect(wrapper.text()).not.toContain('Load more')
    expect(button(wrapper, 'Keep searching')).toBeDefined()
    expect(rows(wrapper)).toHaveLength(1)
    expect(status(wrapper)).toMatch(/keep searching/i)
  })

  it('says so when the list was restarted after a 400', async () => {
    expensesApi.listExpenses
      .mockResolvedValueOnce(pageOf([expense(uid(1))], 'c1'))
      .mockResolvedValueOnce(failed({ kind: 'bad-request', status: 400 }))
      .mockResolvedValueOnce(pageOf([expense(uid(2))]))
    const { wrapper } = await render()

    await button(wrapper, 'Load more').trigger('click')
    await flushPromises()

    expect(rows(wrapper)).toHaveLength(1)
    expect(status(wrapper)).toBe('The list was reloaded from the start.')
  })

  it('moves focus to the first new row when the last page took the button away', async () => {
    expensesApi.listExpenses
      .mockResolvedValueOnce(pageOf([expense(uid(1))], 'c1'))
      .mockResolvedValueOnce(pageOf([expense(uid(2)), expense(uid(3))]))
    const { wrapper } = await render()
    const trigger = button(wrapper, 'Load more')
    trigger.element.focus()

    await trigger.trigger('click')
    await flushPromises()

    expect(document.activeElement).toBe(wrapper.findAll('tbody th').at(1)?.element)
  })

  it('leaves focus on the button while there is more to load', async () => {
    expensesApi.listExpenses
      .mockResolvedValueOnce(pageOf([expense(uid(1))], 'c1'))
      .mockResolvedValueOnce(pageOf([expense(uid(2))], 'c2'))
    const { wrapper } = await render()
    const trigger = button(wrapper, 'Load more')
    trigger.element.focus()

    await trigger.trigger('click')
    await flushPromises()

    expect(document.activeElement).toBe(trigger.element)
  })

  it('says there are no more expenses when the last page is empty', async () => {
    expensesApi.listExpenses
      .mockResolvedValueOnce(pageOf([expense(uid(1))], 'c1'))
      .mockResolvedValueOnce(pageOf([]))
    const { wrapper } = await render()

    await button(wrapper, 'Load more').trigger('click')
    await flushPromises()

    expect(status(wrapper)).toBe('No more expenses.')
  })

  it('does not announce anything before the first Load more', async () => {
    expensesApi.listExpenses.mockResolvedValue(pageOf([expense(uid(1))], 'c1'))

    const { wrapper } = await render()

    expect(status(wrapper)).toBe('')
  })
})

describe('ExpensesView: filters in the page URL', () => {
  it('fills the form from the URL and sends the same filters', async () => {
    expensesApi.listExpenses.mockResolvedValue(pageOf([]))

    const { wrapper } = await render(
      `/expenses?from=2026-10-01&to=2026-10-31&category=${FOOD}&q=tea`,
    )

    expect(paramsOfCall(0)).toBe(`from=2026-10-01&to=2026-10-31&category=${FOOD}&q=tea&limit=50`)
    expect((wrapper.get('#filter-from').element as HTMLInputElement).value).toBe('2026-10-01')
    expect((wrapper.get('#filter-to').element as HTMLInputElement).value).toBe('2026-10-31')
    expect((wrapper.get('#filter-category').element as HTMLSelectElement).value).toBe(FOOD)
    expect((wrapper.get('#filter-q').element as HTMLInputElement).value).toBe('tea')
  })

  it('drops invalid, repeated and unknown values and never sends them', async () => {
    expensesApi.listExpenses.mockResolvedValue(pageOf([]))

    const { wrapper } = await render(
      `/expenses?from=nope&to=2026-02-30&category=${FOOD.toUpperCase()}&category=none&foo=1&q=&cursor=abc&limit=5`,
    )

    expect(paramsOfCall(0)).toBe('limit=50')
    expect((wrapper.get('#filter-from').element as HTMLInputElement).value).toBe('')
    expect((wrapper.get('#filter-category').element as HTMLSelectElement).value).toBe('')
  })

  it('offers Any, Uncategorized and the categories, and keeps an unknown valid id selectable', async () => {
    expensesApi.listExpenses.mockResolvedValue(pageOf([]))
    const unknown = '2b7c1f0e-5d3a-4c8e-9a41-2f6d8e7b1a91'

    const { wrapper } = await render(`/expenses?category=${unknown}`)

    const options = wrapper.findAll('#filter-category option')
    expect(options.map((o) => o.attributes('value'))).toEqual(['', 'none', FOOD, TRAVEL, unknown])
    expect(options.map((o) => o.text()).slice(0, 4)).toEqual([
      'Any category',
      'Uncategorized',
      'Food',
      'Travel',
    ])
    expect((wrapper.get('#filter-category').element as HTMLSelectElement).value).toBe(unknown)
  })

  it('refuses from after to in the URL: no request, a message under To', async () => {
    const { wrapper } = await render('/expenses?from=2026-10-02&to=2026-10-01')

    expect(expensesApi.listExpenses).not.toHaveBeenCalled()
    expect(wrapper.get('#filter-to-error').text()).toMatch(/before/)
    expect(wrapper.find('table').exists()).toBe(false)
  })

  it('Apply pushes the filters to the URL, fetches with them, and puts focus on the results heading', async () => {
    expensesApi.listExpenses.mockResolvedValue(pageOf([expense(uid(1))]))
    const { wrapper, router } = await render()

    await wrapper.get('#filter-from').setValue('2026-10-01')
    await wrapper.get('#filter-category').setValue('none')
    await wrapper.get('#filter-q').setValue('  lunch ')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(router.currentRoute.value.query).toEqual({
      from: '2026-10-01',
      category: 'none',
      q: 'lunch',
    })
    expect(paramsOfCall(1)).toBe('from=2026-10-01&category=none&q=lunch&limit=50')
    expect(document.activeElement).toBe(wrapper.get('#results-heading').element)
  })

  it('does not search as you type', async () => {
    expensesApi.listExpenses.mockResolvedValue(pageOf([]))
    const { wrapper } = await render()

    await wrapper.get('#filter-q').setValue('lun')

    expect(expensesApi.listExpenses).toHaveBeenCalledTimes(1)
  })

  it('Apply with unchanged filters fetches again', async () => {
    expensesApi.listExpenses.mockResolvedValue(pageOf([expense(uid(1))]))
    const { wrapper } = await render('/expenses?q=tea')

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(expensesApi.listExpenses).toHaveBeenCalledTimes(2)
    expect(document.activeElement).toBe(wrapper.get('#results-heading').element)
  })

  it('Apply refuses from after to: nothing is pushed or sent, and focus goes to the field', async () => {
    expensesApi.listExpenses.mockResolvedValue(pageOf([]))
    const { wrapper, router } = await render()

    await wrapper.get('#filter-from').setValue('2026-10-02')
    await wrapper.get('#filter-to').setValue('2026-10-01')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(router.currentRoute.value.query).toEqual({})
    expect(expensesApi.listExpenses).toHaveBeenCalledTimes(1)
    expect(wrapper.get('#filter-to-error').text()).toMatch(/before/)
    expect(wrapper.get('#filter-to').attributes('aria-invalid')).toBe('true')
    expect(document.activeElement).toBe(wrapper.get('#filter-to').element)
  })

  it('Apply refuses a q over 100 code points', async () => {
    expensesApi.listExpenses.mockResolvedValue(pageOf([]))
    const { wrapper, router } = await render()

    await wrapper.get('#filter-q').setValue('😀'.repeat(101))
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(router.currentRoute.value.query).toEqual({})
    expect(expensesApi.listExpenses).toHaveBeenCalledTimes(1)
    expect(wrapper.get('#filter-q-error').text()).toMatch(/100/)
  })

  it('does not put a maxlength on the search field', async () => {
    expensesApi.listExpenses.mockResolvedValue(pageOf([]))

    const { wrapper } = await render()

    expect(wrapper.get('#filter-q').attributes('maxlength')).toBeUndefined()
  })

  it('a changed query aborts the request in flight and shows only the newer answer, without remounting', async () => {
    const old = deferred<ReturnType<typeof pageOf>>()
    expensesApi.listExpenses
      .mockReturnValueOnce(old.promise)
      .mockResolvedValueOnce(pageOf([expense(uid(2), { description: 'Newer' })]))
    const { wrapper, router } = await render()
    const heading = wrapper.get('h1').element

    await router.push('/expenses?q=newer')
    await flushPromises()
    old.resolve(pageOf([expense(uid(1), { description: 'Older' })]))
    await flushPromises()

    expect(signalOfCall(0).aborted).toBe(true)
    expect(wrapper.text()).toContain('Newer')
    expect(wrapper.text()).not.toContain('Older')
    expect(wrapper.get('h1').element).toBe(heading)
  })

  it('does not search again for an unrelated query parameter', async () => {
    expensesApi.listExpenses.mockResolvedValue(pageOf([]))
    const { router } = await render('/expenses?q=tea')

    await router.push('/expenses?q=tea&foo=1')
    await flushPromises()

    expect(expensesApi.listExpenses).toHaveBeenCalledTimes(1)
  })

  it('does not search when the route leaves /expenses, and aborts on unmount', async () => {
    const slow = deferred<ReturnType<typeof pageOf>>()
    expensesApi.listExpenses.mockReturnValue(slow.promise)
    const { wrapper, router } = await render('/expenses?q=tea')

    await router.push('/expenses/new?q=other')
    await flushPromises()
    wrapper.unmount()

    expect(expensesApi.listExpenses).toHaveBeenCalledTimes(1)
    expect(signalOfCall(0).aborted).toBe(true)
  })
})

describe('ExpensesView: inline delete', () => {
  async function renderTwo() {
    expensesApi.listExpenses.mockResolvedValue(
      pageOf([
        expense(uid(1), { description: 'First' }),
        expense(uid(2), { description: 'Second' }),
      ]),
    )
    return render()
  }
  async function confirmDelete(page: Page, name: string) {
    await page.get(`button[aria-label="Delete ${name}"]`).trigger('click')
    await flushPromises()
    await page.get(`button[aria-label="Confirm delete ${name}"]`).trigger('click')
    await flushPromises()
  }

  it('moves focus to Cancel when Delete is pressed', async () => {
    const { wrapper } = await renderTwo()

    await wrapper.get('button[aria-label="Delete First"]').trigger('click')
    await flushPromises()

    expect(document.activeElement?.textContent?.trim()).toBe('Cancel')
    expect(expensesApi.deleteExpense).not.toHaveBeenCalled()
  })

  it('removes the row on 204, announces it, and moves focus to the results heading', async () => {
    expensesApi.deleteExpense.mockResolvedValue(NO_CONTENT)
    const { wrapper } = await renderTwo()

    await confirmDelete(wrapper, 'First')

    expect(expensesApi.deleteExpense).toHaveBeenCalledWith(uid(1))
    expect(rows(wrapper)).toHaveLength(1)
    expect(wrapper.text()).not.toContain('First')
    expect(status(wrapper)).toBe('Expense deleted')
    expect(document.activeElement).toBe(wrapper.get('#results-heading').element)
  })

  it('removes the row on 404 and says "Already deleted"', async () => {
    expensesApi.deleteExpense.mockResolvedValue(failed({ kind: 'not-found', status: 404 }))
    const { wrapper } = await renderTwo()

    await confirmDelete(wrapper, 'Second')

    expect(rows(wrapper)).toHaveLength(1)
    expect(wrapper.text()).not.toContain('Second')
    expect(status(wrapper)).toBe('Already deleted')
  })

  it('keeps the row and shows the failure in it for any other error', async () => {
    expensesApi.deleteExpense.mockResolvedValue(failed({ kind: 'server', status: 500 }))
    const { wrapper } = await renderTwo()

    await confirmDelete(wrapper, 'First')

    expect(rows(wrapper)).toHaveLength(2)
    expect(rowAt(wrapper, 0).get('[role="alert"]').text()).toBe(
      'Something went wrong on our side. Try again.',
    )
  })

  it('leaves the rest of the list and the flash message alone', async () => {
    expensesApi.deleteExpense.mockResolvedValue(NO_CONTENT)
    const { wrapper } = await renderTwo()

    await confirmDelete(wrapper, 'First')

    expect(rows(wrapper)).toHaveLength(1)
    expect(wrapper.text()).toContain('Second')
  })
})

describe('ExpensesView: the flash message from the form', () => {
  it('neither clears nor repeats it', async () => {
    expensesApi.listExpenses.mockResolvedValue(pageOf([expense(uid(1))]))
    flashMessage.value = 'Expense saved'

    const { wrapper } = await render()

    expect(flashMessage.value).toBe('Expense saved')
    expect(wrapper.text()).not.toContain('Expense saved')
  })
})

describe('ExpensesView: focus after Load more', () => {
  it('goes to the results heading when an empty last page took the button away', async () => {
    expensesApi.listExpenses
      .mockResolvedValueOnce(pageOf([expense(uid(1))], 'c1'))
      .mockResolvedValueOnce(pageOf([]))
    const { wrapper } = await render()
    const trigger = button(wrapper, 'Load more')
    trigger.element.focus()

    await trigger.trigger('click')
    await flushPromises()

    expect(wrapper.find('tbody').exists()).toBe(true)
    expect(document.activeElement).toBe(wrapper.get('#results-heading').element)
  })

  it('goes to the results heading after a restart, when the table and button were unmounted', async () => {
    expensesApi.listExpenses
      .mockResolvedValueOnce(pageOf([expense(uid(1))], 'c1'))
      .mockResolvedValueOnce(failed({ kind: 'bad-request', status: 400 }))
      .mockResolvedValueOnce(pageOf([expense(uid(2))], 'c9'))
    const { wrapper } = await render()
    const trigger = button(wrapper, 'Load more')
    trigger.element.focus()

    await trigger.trigger('click')
    await flushPromises()

    expect(document.activeElement).toBe(wrapper.get('#results-heading').element)
  })

  it('goes to the results heading when the failed restart left only an alert', async () => {
    expensesApi.listExpenses
      .mockResolvedValueOnce(pageOf([expense(uid(1))], 'c1'))
      .mockResolvedValueOnce(failed({ kind: 'bad-request', status: 400 }))
      .mockResolvedValueOnce(failed({ kind: 'network' }))
    const { wrapper } = await render()
    const trigger = button(wrapper, 'Load more')
    trigger.element.focus()

    await trigger.trigger('click')
    await flushPromises()

    expect(document.activeElement).toBe(wrapper.get('#results-heading').element)
  })
})

describe('ExpensesView: what Apply and Clear filters announce', () => {
  async function applyQuery(wrapper: Page, text: string) {
    await wrapper.get('#filter-q').setValue(text)
    await wrapper.get('form').trigger('submit')
    await flushPromises()
  }

  it('says how many expenses are shown, with the singular and the plural', async () => {
    expensesApi.listExpenses.mockResolvedValueOnce(pageOf([]))
    const { wrapper } = await render()

    expensesApi.listExpenses.mockResolvedValueOnce(pageOf([expense(uid(1))]))
    await applyQuery(wrapper, 'a')
    expect(status(wrapper)).toBe('1 expense shown')

    expensesApi.listExpenses.mockResolvedValueOnce(pageOf([expense(uid(1)), expense(uid(2))]))
    await applyQuery(wrapper, 'b')
    expect(status(wrapper)).toBe('2 expenses shown')
  })

  it('says that more can be loaded', async () => {
    expensesApi.listExpenses.mockResolvedValueOnce(pageOf([]))
    const { wrapper } = await render()

    expensesApi.listExpenses.mockResolvedValueOnce(pageOf([expense(uid(1))], 'c1'))
    await applyQuery(wrapper, 'a')

    expect(status(wrapper)).toBe('1 expense shown. More can be loaded.')
  })

  it('says that nothing matches the filters, or that there are no expenses yet', async () => {
    expensesApi.listExpenses.mockResolvedValueOnce(pageOf([expense(uid(1))]))
    const { wrapper } = await render('/expenses?q=tea')

    expensesApi.listExpenses.mockResolvedValueOnce(pageOf([]))
    await applyQuery(wrapper, 'zzz')
    expect(status(wrapper)).toBe('No expenses match these filters')

    expensesApi.listExpenses.mockResolvedValueOnce(pageOf([]))
    await wrapper.get('#filter-q').setValue('')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(status(wrapper)).toBe('No expenses yet')
  })

  it('announces after Clear filters, and moves focus to the results heading', async () => {
    expensesApi.listExpenses.mockResolvedValueOnce(pageOf([]))
    const { wrapper } = await render('/expenses?q=tea')
    expensesApi.listExpenses.mockResolvedValueOnce(pageOf([expense(uid(1)), expense(uid(2))]))

    await button(wrapper, 'Clear filters').trigger('click')
    await flushPromises()

    expect(status(wrapper)).toBe('2 expenses shown')
    expect(document.activeElement).toBe(wrapper.get('#results-heading').element)
  })

  it('does not announce a summary for a search the user did not ask for', async () => {
    expensesApi.listExpenses.mockResolvedValueOnce(pageOf([]))
    const { wrapper, router } = await render()

    expensesApi.listExpenses.mockResolvedValueOnce(pageOf([expense(uid(1))]))
    await router.push('/expenses?q=tea')
    await flushPromises()

    expect(status(wrapper)).toBe('')
  })

  it('announces nothing when the search fails', async () => {
    expensesApi.listExpenses.mockResolvedValueOnce(pageOf([]))
    const { wrapper } = await render()

    expensesApi.listExpenses.mockResolvedValueOnce(failed({ kind: 'network' }))
    await applyQuery(wrapper, 'a')

    expect(status(wrapper)).toBe('')
  })
})

describe('ExpensesView: categories that cannot be looked up', () => {
  const UNKNOWN = '2b7c1f0e-5d3a-4c8e-9a41-2f6d8e7b1a91'
  const OTHER = '3b7c1f0e-5d3a-4c8e-9a41-2f6d8e7b1a92'

  it('shows "Category unavailable", not Uncategorized, when the refresh fails', async () => {
    expensesApi.listExpenses.mockResolvedValue(pageOf([expense(uid(1), { categoryId: UNKNOWN })]))

    const { wrapper } = await render('/expenses', CATEGORIES, failed({ kind: 'network' }))

    expect(categoriesApi.listCategories).toHaveBeenCalledTimes(2)
    expect(rowAt(wrapper, 0).text()).toContain('Category unavailable')
    expect(rowAt(wrapper, 0).text()).not.toContain('Uncategorized')
  })

  it('shows it too when the first category load fails, with no refresh storm', async () => {
    expensesApi.listExpenses.mockResolvedValue(pageOf([expense(uid(1), { categoryId: FOOD })]))

    const { wrapper } = await render('/expenses', failed({ kind: 'network' }))

    expect(categoriesApi.listCategories).toHaveBeenCalledTimes(1)
    expect(rowAt(wrapper, 0).text()).toContain('Category unavailable')
  })

  it('retries with a later page, and shows the names once it works', async () => {
    expensesApi.listExpenses
      .mockResolvedValueOnce(pageOf([expense(uid(1), { categoryId: UNKNOWN })], 'c1'))
      .mockResolvedValueOnce(pageOf([expense(uid(2), { categoryId: OTHER })]))
    const { wrapper } = await render('/expenses', CATEGORIES, failed({ kind: 'network' }))
    expect(categoriesApi.listCategories).toHaveBeenCalledTimes(2)
    categoriesApi.listCategories.mockResolvedValue({
      ok: true,
      status: 200,
      data: { items: [category(UNKNOWN, 'Gifts'), category(OTHER, 'Pets')] },
    })

    await button(wrapper, 'Load more').trigger('click')
    await flushPromises()

    expect(categoriesApi.listCategories).toHaveBeenCalledTimes(3)
    expect(rowAt(wrapper, 0).text()).toContain('Gifts')
    expect(rowAt(wrapper, 1).text()).toContain('Pets')
  })

  it('does not refresh again for a deletion while the failure stands', async () => {
    expensesApi.listExpenses.mockResolvedValue(
      pageOf([
        expense(uid(1), { categoryId: UNKNOWN, description: 'First' }),
        expense(uid(2), { description: 'Second' }),
      ]),
    )
    expensesApi.deleteExpense.mockResolvedValue(NO_CONTENT)
    const { wrapper } = await render('/expenses', CATEGORIES, failed({ kind: 'network' }))

    await wrapper.get('button[aria-label="Delete Second"]').trigger('click')
    await flushPromises()
    await wrapper.get('button[aria-label="Confirm delete Second"]').trigger('click')
    await flushPromises()

    expect(categoriesApi.listCategories).toHaveBeenCalledTimes(2)
  })
})

describe('ExpensesView: Retry, empty pages, row errors and the filter list', () => {
  it('moves focus to the results heading after Retry', async () => {
    expensesApi.listExpenses.mockResolvedValueOnce(failed({ kind: 'server', status: 500 }))
    const { wrapper } = await render()
    const retry = button(wrapper, 'Retry')
    retry.element.focus()
    expensesApi.listExpenses.mockResolvedValueOnce(pageOf([expense(uid(1))]))

    await retry.trigger('click')
    await flushPromises()

    expect(document.activeElement).toBe(wrapper.get('#results-heading').element)
  })

  it('says "No expenses on this page." when every row was deleted but more can be loaded', async () => {
    expensesApi.listExpenses.mockResolvedValue(
      pageOf([expense(uid(1), { description: 'Only' })], 'c1'),
    )
    expensesApi.deleteExpense.mockResolvedValue(NO_CONTENT)
    const { wrapper } = await render()

    await wrapper.get('button[aria-label="Delete Only"]').trigger('click')
    await flushPromises()
    await wrapper.get('button[aria-label="Confirm delete Only"]').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('No expenses on this page.')
    expect(wrapper.text()).not.toContain('No expenses yet')
    expect(button(wrapper, 'Load more')).toBeDefined()
  })

  it('shows no alert for a row whose id is "constructor"', async () => {
    expensesApi.listExpenses.mockResolvedValue(pageOf([expense('constructor')]))

    const { wrapper } = await render()

    expect(rows(wrapper)).toHaveLength(1)
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
  })

  it('shows a failed delete as an alert inside that row only, and the Edit label names the expense', async () => {
    expensesApi.listExpenses.mockResolvedValue(
      pageOf([
        expense(uid(1), { description: 'First' }),
        expense(uid(2), { description: 'Second' }),
      ]),
    )
    expensesApi.deleteExpense.mockResolvedValue(
      failed({ kind: 'conflict', status: 409, detail: 'Hand-written server detail' }),
    )
    const { wrapper } = await render()
    expect(rowAt(wrapper, 1).get('a').attributes('aria-label')).toBe('Edit Second')

    await wrapper.get('button[aria-label="Delete Second"]').trigger('click')
    await flushPromises()
    await wrapper.get('button[aria-label="Confirm delete Second"]').trigger('click')
    await flushPromises()

    expect(rowAt(wrapper, 0).find('[role="alert"]').exists()).toBe(false)
    expect(rowAt(wrapper, 1).get('[role="alert"]').text()).toBe(
      'Something went wrong on our side. Try again.',
    )
    expect(wrapper.html()).not.toContain('Hand-written')
  })

  it('shows the generic text for a 422 on the list', async () => {
    expensesApi.listExpenses.mockResolvedValue(
      failed({
        kind: 'validation',
        status: 422,
        fieldErrors: {},
        formErrors: ['Hand-written server detail'],
      }),
    )

    const { wrapper } = await render()

    expect(wrapper.get('[role="alert"]').text()).toBe(
      'Something went wrong on our side. Try again.',
    )
  })

  it('does not offer "Unknown category" while the categories are still loading', async () => {
    const unknown = '2b7c1f0e-5d3a-4c8e-9a41-2f6d8e7b1a91'
    const slow = deferred<unknown>()
    expensesApi.listExpenses.mockResolvedValue(pageOf([]))
    categoriesApi.listCategories.mockReturnValue(slow.promise)
    const pinia = createPinia()
    setActivePinia(pinia)
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/expenses', name: 'expenses', component: ExpensesView }],
    })
    await router.push(`/expenses?category=${unknown}`)
    const wrapper = mount(ExpensesView, { global: { plugins: [pinia, router] } })
    await flushPromises()

    expect(wrapper.text()).not.toContain('Unknown category')
    slow.resolve(CATEGORIES)
    await flushPromises()
    expect(wrapper.text()).toContain('Unknown category')
  })
})

describe('ExpensesView: the page URL through the real router', () => {
  it('goes back to an earlier filter URL: searches again and refills the form', async () => {
    expensesApi.listExpenses.mockResolvedValue(pageOf([]))
    const { wrapper, router } = await render('/expenses?q=one')
    await router.push('/expenses?q=two')
    await flushPromises()
    expect((wrapper.get('#filter-q').element as HTMLInputElement).value).toBe('two')

    router.back()
    await flushPromises()

    expect(paramsOfCall(2)).toBe('q=one&limit=50')
    expect((wrapper.get('#filter-q').element as HTMLInputElement).value).toBe('one')
  })

  it('sends exactly from and limit for a query with __proto__, constructor and a repeated name', async () => {
    expensesApi.listExpenses.mockResolvedValue(pageOf([]))

    await render('/expenses?__proto__=x&constructor=a&constructor=b&from=2026-10-01')

    expect(expensesApi.listExpenses).toHaveBeenCalledTimes(1)
    expect(paramsOfCall(0)).toBe('from=2026-10-01&limit=50')
  })

  it('reads q=a+b as "a b" and q=a%2Bb as "a+b"', async () => {
    expensesApi.listExpenses.mockResolvedValue(pageOf([]))
    const { wrapper, router } = await render('/expenses?q=a+b')
    expect((expensesApi.listExpenses.mock.calls.at(0)?.[0] as URLSearchParams).get('q')).toBe('a b')
    expect((wrapper.get('#filter-q').element as HTMLInputElement).value).toBe('a b')

    await router.push('/expenses?q=a%2Bb')
    await flushPromises()

    expect((expensesApi.listExpenses.mock.calls.at(1)?.[0] as URLSearchParams).get('q')).toBe('a+b')
    expect((wrapper.get('#filter-q').element as HTMLInputElement).value).toBe('a+b')
  })
})

describe('ExpensesView: the summary when the search is still empty', () => {
  it('tells the user to keep searching when only empty pages came back', async () => {
    expensesApi.listExpenses.mockResolvedValueOnce(pageOf([]))
    const { wrapper } = await render()
    expensesApi.listExpenses.mockResolvedValue(pageOf([], 'cn'))

    await wrapper.get('#filter-q').setValue('rare')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(status(wrapper)).toBe('No expenses found yet. Keep searching to look further.')
  })
})

describe('ExpensesView: focus is rescued only when the Load more button went away', () => {
  it('leaves focus alone when it is on the body but the button is still there', async () => {
    expensesApi.listExpenses
      .mockResolvedValueOnce(pageOf([expense(uid(1))], 'c1'))
      .mockResolvedValueOnce(pageOf([expense(uid(2))], 'c2'))
    const { wrapper } = await render()
    ;(document.activeElement as HTMLElement | null)?.blur()

    await button(wrapper, 'Load more').trigger('click')
    await flushPromises()

    expect(document.activeElement).toBe(document.body)
  })

  it('leaves focus alone after a failed Load more (nothing was added)', async () => {
    expensesApi.listExpenses
      .mockResolvedValueOnce(pageOf([expense(uid(1))], 'c1'))
      .mockResolvedValueOnce(failed({ kind: 'network' }))
    const { wrapper } = await render()
    ;(document.activeElement as HTMLElement | null)?.blur()

    await button(wrapper, 'Load more').trigger('click')
    await flushPromises()

    expect(document.activeElement).toBe(document.body)
  })
})

describe('ExpensesView: one category lookup at a time', () => {
  it('does not start a second lookup when Load more adds rows while one is pending', async () => {
    const unknown = '2b7c1f0e-5d3a-4c8e-9a41-2f6d8e7b1a91'
    const slow = deferred<unknown>()
    expensesApi.listExpenses
      .mockResolvedValueOnce(pageOf([expense(uid(1), { categoryId: unknown })], 'c1'))
      .mockResolvedValueOnce(pageOf([expense(uid(2), { categoryId: unknown })]))
    const { wrapper } = await render('/expenses', CATEGORIES, slow.promise)
    expect(categoriesApi.listCategories).toHaveBeenCalledTimes(2)

    await button(wrapper, 'Load more').trigger('click')
    await flushPromises()
    slow.resolve(CATEGORIES)
    await flushPromises()

    expect(categoriesApi.listCategories).toHaveBeenCalledTimes(2)
  })
})

describe('ExpensesView: a summary is only for a search Apply started', () => {
  it('does not announce for a later search when the push of Apply was cancelled', async () => {
    expensesApi.listExpenses.mockResolvedValue(pageOf([expense(uid(1))]))
    const { wrapper, router } = await render()
    const remove = router.beforeEach(() => false)

    await wrapper.get('#filter-q').setValue('tea')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    remove()
    await router.push('/expenses?q=other')
    await flushPromises()

    expect(expensesApi.listExpenses).toHaveBeenCalledTimes(2)
    expect(status(wrapper)).toBe('')
  })

  it('does not announce after Clear filters when the push was cancelled', async () => {
    expensesApi.listExpenses
      .mockResolvedValueOnce(pageOf([]))
      .mockResolvedValue(pageOf([expense(uid(1))]))
    const { wrapper, router } = await render('/expenses?q=tea')
    const remove = router.beforeEach(() => false)

    await button(wrapper, 'Clear filters').trigger('click')
    await flushPromises()
    remove()
    await router.push('/expenses?q=other')
    await flushPromises()

    expect(status(wrapper)).toBe('')
  })

  it('does not announce after Apply refused from after to, then a later search', async () => {
    expensesApi.listExpenses.mockResolvedValue(pageOf([expense(uid(1))]))
    const { wrapper, router } = await render()
    await wrapper.get('#filter-from').setValue('2026-10-02')
    await wrapper.get('#filter-to').setValue('2026-10-01')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    await router.push('/expenses?q=x')
    await flushPromises()

    expect(status(wrapper)).toBe('')
  })
})

// What these tests can and cannot prove: jsdom applies no scoped styles and has no screen reader, so the DOM checks and
// the source checks below only catch a regression in how the region is built. Whether a screen reader announces it
// is for the manual walkthrough in WP11.
describe('ExpensesView: the live region', () => {
  const HIDING = /display:\s*none|visibility:\s*hidden|clip(-path)?:/

  it('stays in the page and is never hidden while empty', async () => {
    expensesApi.listExpenses.mockResolvedValue(pageOf([]))

    const { wrapper } = await render()

    const region = wrapper.get('.status')
    expect(region.text()).toBe('')
    expect(region.attributes('aria-live')).toBe('polite')
    expect(region.attributes('hidden')).toBeUndefined()
    expect(getComputedStyle(region.element).display).not.toBe('none')
  })

  it('sits right after the results heading, before the table and the Load more button', async () => {
    expensesApi.listExpenses.mockResolvedValue(pageOf([expense(uid(1))], 'c1'))

    const { wrapper } = await render()

    const heading = wrapper.get('#results-heading').element
    const region = wrapper.get('.status').element
    const table = wrapper.get('table').element
    const more = button(wrapper, 'Load more').element
    const FOLLOWING = Node.DOCUMENT_POSITION_FOLLOWING
    expect(heading.compareDocumentPosition(region) & FOLLOWING).toBeTruthy()
    expect(region.compareDocumentPosition(table) & FOLLOWING).toBeTruthy()
    expect(region.compareDocumentPosition(more) & FOLLOWING).toBeTruthy()
  })

  it('has no hiding rule in the source for the region or for any :empty selector', () => {
    const rules = [...source.matchAll(/([^{}]+)\{([^{}]*)\}/g)].map(([, selector, body]) => ({
      selector: selector ?? '',
      body: body ?? '',
    }))

    const hidingRules = rules.filter(
      (rule) => /\.status|:empty/.test(rule.selector) && HIDING.test(rule.body),
    )

    expect(hidingRules).toEqual([])
    expect(rules.some((rule) => /\.status/.test(rule.selector))).toBe(true)
  })

  it('has no v-if, v-show or hidden on the region in the template', () => {
    const tag = /<div[^>]*class="status"[^>]*>/.exec(source)?.[0]

    expect(tag).toBeDefined()
    expect(tag).not.toMatch(/v-if|v-show|v-else|\bhidden\b/)
  })
})
