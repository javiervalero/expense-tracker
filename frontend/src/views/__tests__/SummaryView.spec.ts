import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory } from 'vue-router'

import { deferred, failed } from '@/api/__tests__/expenseFixtures'
import { categoryTotal, monthlyReport, reportOk } from '@/api/__tests__/reportFixtures'
import { createAppRouter } from '@/router'
import { INCOMPLETE_NOTICE, describeSummaryError } from '@/lib/summaryMessages'
import SummaryView from '../SummaryView.vue'
import source from '../SummaryView.vue?raw'

const auth = vi.hoisted(() => ({ me: vi.fn() }))
const reports = vi.hoisted(() => ({ getMonthlyReport: vi.fn() }))
vi.mock('@/api/auth', () => auth)
vi.mock('@/api/reports', () => reports)

const SIGNED_IN = { ok: true, status: 200, data: { id: 'u', email: 'a@example.com' } }
const SIGNED_OUT = { ok: false, error: { kind: 'unauthorized', status: 401 } }

async function render(path: string, signedIn = true) {
  auth.me.mockResolvedValue(signedIn ? SIGNED_IN : SIGNED_OUT)
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = createAppRouter(createMemoryHistory())
  await router.push(path)
  const host = document.createElement('div')
  document.body.appendChild(host)
  const wrapper = mount(SummaryView, { attachTo: host, global: { plugins: [pinia, router] } })
  await flushPromises()
  return { wrapper, router }
}
type Page = Awaited<ReturnType<typeof render>>['wrapper']

function button(page: Page, text: string) {
  const found = page.findAll('button').find((b) => b.text() === text)
  if (!found) throw new Error(`no button "${text}"`)
  return found
}
function requestedMonths(): unknown[] {
  return reports.getMonthlyReport.mock.calls.map((call) => call[0])
}
function signalOfCall(index: number): AbortSignal {
  return reports.getMonthlyReport.mock.calls.at(index)?.[1] as AbortSignal
}

beforeEach(() => {
  auth.me.mockReset()
  reports.getMonthlyReport.mockReset()
})
afterEach(() => {
  vi.useRealTimers()
  document.body.replaceChildren()
})

describe('SummaryView: route', () => {
  it('is a private route called summary with a fixed title', async () => {
    const { router } = await render('/summary?month=2026-10', false)

    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.redirect).toBe('/summary?month=2026-10')
    expect(router.resolve('/summary').name).toBe('summary')
    expect(router.resolve('/summary').meta.title).toBe('Monthly summary')
    expect(reports.getMonthlyReport).not.toHaveBeenCalled()
  })

  it.each([
    ['no month', '/summary'],
    ['a month out of range', '/summary?month=2100-01'],
    ['a month that is not a month', '/summary?month=2026-13'],
    ['text', '/summary?month=%3Cimg%3E'],
    ['a repeated month', '/summary?month=2026-10&month=2026-09'],
    ['an empty month', '/summary?month'],
  ])('replaces %s with the current month, with no request and no message', async (_name, path) => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date(2026, 9, 15, 12))
    reports.getMonthlyReport.mockResolvedValue(reportOk())

    const { wrapper, router } = await render(path)

    expect(router.currentRoute.value.fullPath).toBe('/summary?month=2026-10')
    expect(requestedMonths()).toEqual(['2026-10'])
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
  })

  it('does nothing when the router already moved to another page', async () => {
    reports.getMonthlyReport.mockResolvedValue(reportOk())
    const { wrapper, router } = await render('/summary?month=2026-10')

    await router.push('/categories')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('categories')
    expect(requestedMonths()).toEqual(['2026-10'])
    wrapper.unmount()
  })
})

describe('SummaryView: states', () => {
  it('has one h1 that can take focus', async () => {
    reports.getMonthlyReport.mockResolvedValue(reportOk())

    const { wrapper } = await render('/summary?month=2026-10')

    expect(wrapper.findAll('h1')).toHaveLength(1)
    expect(wrapper.get('h1').text()).toBe('Monthly summary')
    expect(wrapper.get('h1').attributes('tabindex')).toBe('-1')
  })

  it('says Loading summary… and marks the region busy while the request is out', async () => {
    reports.getMonthlyReport.mockReturnValue(new Promise(() => undefined))

    const { wrapper } = await render('/summary?month=2026-10')

    expect(wrapper.text()).toContain('Loading summary…')
    expect(wrapper.get('[aria-busy="true"]').text()).toContain('Loading summary…')
    expect(wrapper.find('table').exists()).toBe(false)
  })

  it('shows the table, no busy flag and a fixed polite announcement once loaded', async () => {
    reports.getMonthlyReport.mockResolvedValue(reportOk())

    const { wrapper } = await render('/summary?month=2026-10')

    expect(wrapper.get('caption').text()).toBe('Spending by category, October 2026')
    expect(wrapper.find('[aria-busy="true"]').exists()).toBe(false)
    const live = wrapper.get('[aria-live="polite"]')
    expect(live.text()).toBe('Showing October 2026')
    expect(live.text()).not.toMatch(/\d\.\d\d|€/)
    expect(wrapper.text()).not.toContain('Loading summary')
  })

  it('leaves the announcement empty while loading', async () => {
    reports.getMonthlyReport.mockReturnValue(new Promise(() => undefined))

    const { wrapper } = await render('/summary?month=2026-10')

    expect(wrapper.get('[aria-live="polite"]').text()).toBe('')
  })

  it('shows the empty month with a link to add an expense', async () => {
    reports.getMonthlyReport.mockResolvedValue(
      reportOk(monthlyReport({ totalCents: 0, expenseCount: 0, categories: [] })),
    )

    const { wrapper } = await render('/summary?month=2026-10')

    expect(wrapper.text()).toContain('No expenses in October 2026.')
    const link = wrapper.get('a[href="/expenses/new"]')
    expect(link.text()).toBe('Add an expense')
    expect(wrapper.find('table').exists()).toBe(false)
    expect(wrapper.get('[aria-live="polite"]').text()).toBe('Showing October 2026')
  })

  it('shows the notice above the table when the month is incomplete', async () => {
    reports.getMonthlyReport.mockResolvedValue(reportOk(monthlyReport({ complete: false })))

    const { wrapper } = await render('/summary?month=2026-10')

    const notice = wrapper.get('.incomplete')
    expect(notice.text()).toBe(INCOMPLETE_NOTICE)
    const position = notice.element.compareDocumentPosition(wrapper.get('table').element)
    expect(position & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
  })

  it('shows no notice when the month is complete', async () => {
    reports.getMonthlyReport.mockResolvedValue(reportOk())

    const { wrapper } = await render('/summary?month=2026-10')

    expect(wrapper.text()).not.toContain(INCOMPLETE_NOTICE)
  })

  it('renders a hostile category name as text', async () => {
    const hostile = '<img src=x onerror=alert(1)>'
    reports.getMonthlyReport.mockResolvedValue(
      reportOk(monthlyReport({ categories: [categoryTotal({ name: hostile })] })),
    )

    const { wrapper } = await render('/summary?month=2026-10')

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.text()).toContain(hostile)
  })

  it('never uses v-html', () => {
    expect(source).not.toContain('v-html')
  })
})

describe('SummaryView: errors', () => {
  const error = { kind: 'server', status: 500 } as const

  it('shows the alert for the error kind with a Retry button, and focuses the alert', async () => {
    reports.getMonthlyReport.mockResolvedValue(failed(error))

    const { wrapper } = await render('/summary?month=2026-10')

    const alert = wrapper.get('[role="alert"]')
    expect(alert.text()).toBe(describeSummaryError(error))
    expect(document.activeElement).toBe(alert.element)
    expect(button(wrapper, 'Retry').attributes('type')).toBe('button')
    expect(wrapper.find('table').exists()).toBe(false)
    expect(wrapper.get('[aria-live="polite"]').text()).toBe('')
  })

  it('Retry asks again for the same month, shows the result and puts focus on the heading', async () => {
    reports.getMonthlyReport.mockResolvedValueOnce(failed(error))
    reports.getMonthlyReport.mockResolvedValueOnce(reportOk())
    const { wrapper } = await render('/summary?month=2026-10')

    await button(wrapper, 'Retry').trigger('click')
    await flushPromises()

    expect(requestedMonths()).toEqual(['2026-10', '2026-10'])
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(wrapper.find('table').exists()).toBe(true)
    expect(document.activeElement).toBe(wrapper.get('h1').element)
  })
})

describe('SummaryView: changing the month', () => {
  it('puts the month in the URL, asks once for it, and keeps focus on the control used', async () => {
    reports.getMonthlyReport.mockResolvedValue(reportOk())
    const { wrapper, router } = await render('/summary?month=2026-10')
    const next = button(wrapper, 'Next month')
    next.element.focus()

    await next.trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.fullPath).toBe('/summary?month=2026-11')
    expect(requestedMonths()).toEqual(['2026-10', '2026-11'])
    expect(document.activeElement).toBe(next.element)
  })

  it('shows the picker on the month of the URL and applies Show', async () => {
    reports.getMonthlyReport.mockResolvedValue(reportOk())
    const { wrapper, router } = await render('/summary?month=2026-10')
    expect((wrapper.get('#summary-month').element as HTMLSelectElement).value).toBe('10')

    await wrapper.get('#summary-month').setValue('02')
    await wrapper.get('#summary-year').setValue('2024')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(router.currentRoute.value.query.month).toBe('2024-02')
    expect(requestedMonths()).toEqual(['2026-10', '2024-02'])
    expect(wrapper.get('caption').text()).toContain('2024')
  })

  it('retries when Show is pressed on the month already shown after a failure', async () => {
    reports.getMonthlyReport.mockResolvedValueOnce(failed({ kind: 'server', status: 500 }))
    reports.getMonthlyReport.mockResolvedValueOnce(reportOk())
    const { wrapper, router } = await render('/summary?month=2026-10')
    const push = vi.spyOn(router, 'push')

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(requestedMonths()).toEqual(['2026-10', '2026-10'])
    expect(push).not.toHaveBeenCalled()
    expect(wrapper.find('table').exists()).toBe(true)
  })

  it('does nothing when Show is pressed on the month already shown and loaded', async () => {
    reports.getMonthlyReport.mockResolvedValue(reportOk())
    const { wrapper, router } = await render('/summary?month=2026-10')
    const push = vi.spyOn(router, 'push')

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(requestedMonths()).toEqual(['2026-10'])
    expect(push).not.toHaveBeenCalled()
  })

  it('pushes and loads when Show is pressed on a different month', async () => {
    reports.getMonthlyReport.mockResolvedValue(reportOk())
    const { wrapper, router } = await render('/summary?month=2026-10')
    const push = vi.spyOn(router, 'push')

    await wrapper.get('#summary-month').setValue('03')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(push).toHaveBeenCalledTimes(1)
    expect(requestedMonths()).toEqual(['2026-10', '2026-03'])
  })

  it('aborts the request in flight when the month changes', async () => {
    const first = deferred<unknown>()
    reports.getMonthlyReport.mockReturnValueOnce(first.promise)
    reports.getMonthlyReport.mockResolvedValueOnce(reportOk(monthlyReport({ month: '2026-09' })))
    const { wrapper } = await render('/summary?month=2026-10')

    await button(wrapper, 'Previous month').trigger('click')
    first.resolve(reportOk())
    await flushPromises()

    expect(signalOfCall(0).aborted).toBe(true)
    expect(wrapper.get('caption').text()).toBe('Spending by category, September 2026')
    expect(wrapper.get('[aria-live="polite"]').text()).toBe('Showing September 2026')
  })

  it('goes back to the earlier month with Back and asks for it again', async () => {
    reports.getMonthlyReport.mockResolvedValue(reportOk())
    const { wrapper, router } = await render('/summary?month=2026-10')
    await button(wrapper, 'Next month').trigger('click')
    await flushPromises()

    router.back()
    await flushPromises()

    expect(router.currentRoute.value.query.month).toBe('2026-10')
    expect(requestedMonths()).toEqual(['2026-10', '2026-11', '2026-10'])
  })

  it('aborts the request when the page goes away', async () => {
    reports.getMonthlyReport.mockReturnValue(new Promise(() => undefined))
    const { wrapper } = await render('/summary?month=2026-10')

    wrapper.unmount()

    expect(signalOfCall(0).aborted).toBe(true)
  })
})
