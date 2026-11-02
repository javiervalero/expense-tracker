import { mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { describe, expect, it } from 'vitest'

import { FOOD } from '@/api/__tests__/expenseFixtures'
import { categoryTotal, monthlyReport } from '@/api/__tests__/reportFixtures'
import type { MonthlyReport } from '@/api/types'
import SummaryTable from '../SummaryTable.vue'
import source from '../SummaryTable.vue?raw'

const stub = { template: '<p>stub</p>' }

async function table(report: MonthlyReport = monthlyReport(), month = '2026-10') {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/expenses', name: 'expenses', component: stub }],
  })
  await router.push('/expenses')
  return mount(SummaryTable, {
    props: {
      month,
      categories: report.categories,
      totalCents: report.totalCents,
      expenseCount: report.expenseCount,
    },
    global: { plugins: [router] },
  })
}

describe('SummaryTable: structure', () => {
  it('is a table in a focusable named region with the month in its caption', async () => {
    const wrapper = await table()

    const region = wrapper.get('[role="region"]')
    expect(region.attributes('tabindex')).toBe('0')
    expect(region.attributes('aria-label')).toBeUndefined()
    const caption = wrapper.get('table caption')
    expect(caption.attributes('id')).toBeTruthy()
    expect(region.attributes('aria-labelledby')).toBe(caption.attributes('id'))
    expect(region.find('table').exists()).toBe(true)
    expect(caption.text()).toBe('Spending by category, October 2026')
  })

  it('has a caption whose text has no surrounding whitespace', async () => {
    const wrapper = await table()

    expect(wrapper.get('caption').element.textContent).toBe('Spending by category, October 2026')
  })

  it('has the column headers Category, Expenses and Total', async () => {
    const wrapper = await table()

    const headers = wrapper.findAll('thead th')
    expect(headers.map((h) => h.text())).toEqual(['Category', 'Expenses', 'Total'])
    expect(headers.map((h) => h.attributes('scope'))).toEqual(['col', 'col', 'col'])
    expect(headers[2]?.classes()).toContain('amount')
  })

  it('shows one row per category in the given order with count and money', async () => {
    const wrapper = await table()

    const rows = wrapper.findAll('tbody tr')
    expect(rows).toHaveLength(3)
    const first = rows[0]!
    expect(first.get('th').attributes('scope')).toBe('row')
    expect(first.get('th bdi').text()).toBe('Travel')
    const cells = first.findAll('td')
    expect(cells[0]?.text()).toBe('2')
    expect(cells[1]?.text()).toBe('€20.04')
    expect(cells[1]?.classes()).toContain('amount')
    expect(rows[1]?.get('th bdi').text()).toBe('Food')
  })

  it('labels the row without a name Uncategorized', async () => {
    const wrapper = await table()

    expect(wrapper.findAll('tbody tr').at(2)?.get('th bdi').text()).toBe('Uncategorized')
  })

  it('formats a large count with the locale grouping', async () => {
    const wrapper = await table(
      monthlyReport({ categories: [categoryTotal({ expenseCount: 1234 })], expenseCount: 1234 }),
    )

    expect(wrapper.get('tbody td').text()).toBe('1,234')
    expect(wrapper.get('tfoot td').text()).toBe('1,234')
  })

  it('ends with a Total row holding the grand count and total', async () => {
    const wrapper = await table()

    const foot = wrapper.get('tfoot tr')
    expect(foot.get('th').text()).toBe('Total')
    expect(foot.get('th').attributes('scope')).toBe('row')
    const cells = foot.findAll('td')
    expect(cells[0]?.text()).toBe('5')
    expect(cells[1]?.text()).toBe('€50.10')
    expect(cells[1]?.classes()).toContain('amount')
  })

  it('shows a dash in the caption for a month that is not valid', async () => {
    const wrapper = await table(monthlyReport(), 'nope')

    expect(wrapper.get('caption').text()).toBe('Spending by category, —')
  })
})

describe('SummaryTable: drill-down links', () => {
  it('links a category to the expense list of the month for that category', async () => {
    const wrapper = await table()

    const href = wrapper.findAll('tbody tr').at(1)?.get('a').attributes('href')
    expect(href).toBe(`/expenses?from=2026-10-01&to=2026-10-31&category=${FOOD}`)
  })

  it('links Uncategorized with category=none', async () => {
    const wrapper = await table()

    const link = wrapper.findAll('tbody tr').at(2)?.get('a')
    expect(link?.attributes('href')).toBe('/expenses?from=2026-10-01&to=2026-10-31&category=none')
    expect(link?.get('bdi').text()).toBe('Uncategorized')
  })

  it('uses the last day of a leap February', async () => {
    const wrapper = await table(monthlyReport({ month: '2028-02' }), '2028-02')

    expect(wrapper.get('a').attributes('href')).toContain('from=2028-02-01&to=2028-02-29')
  })

  it('gives plain text, no link, for an id that is not a version 4 uuid', async () => {
    const wrapper = await table(
      monthlyReport({
        categories: [categoryTotal({ categoryId: '../../x?y=1', name: 'Odd' })],
      }),
    )

    expect(wrapper.find('a').exists()).toBe(false)
    expect(wrapper.get('tbody th').text()).toBe('Odd')
  })

  it('gives plain text for every row when the month is not valid', async () => {
    const wrapper = await table(monthlyReport(), 'nope')

    expect(wrapper.find('a').exists()).toBe(false)
    expect(wrapper.get('tbody th').text()).toBe('Travel')
  })
})

describe('SummaryTable: hostile names', () => {
  it('renders markup in a name as text, never as elements', async () => {
    const hostile = '<img src=x onerror=alert(1)>'
    const wrapper = await table(monthlyReport({ categories: [categoryTotal({ name: hostile })] }))

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.get('tbody th bdi').text()).toBe(hostile)
  })

  it('never uses v-html', () => {
    expect(source).not.toContain('v-html')
  })
})
