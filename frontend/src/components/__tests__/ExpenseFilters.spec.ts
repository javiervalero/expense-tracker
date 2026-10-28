import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import { FOOD, category } from '@/api/__tests__/expenseFixtures'
import ExpenseFilters from '../ExpenseFilters.vue'

const CATEGORIES = [category(FOOD, 'Food')]

describe('ExpenseFilters', () => {
  it('links a category error to the select and marks it invalid', () => {
    const wrapper = mount(ExpenseFilters, {
      props: { categories: CATEGORIES, errors: { category: 'Choose a category from the list.' } },
    })

    const select = wrapper.get('#filter-category')
    expect(select.attributes('aria-invalid')).toBe('true')
    expect(select.attributes('aria-describedby')).toBe('filter-category-error')
    expect(wrapper.get('#filter-category-error').text()).toBe('Choose a category from the list.')
  })

  it('has no invalid marks when there are no errors', () => {
    const wrapper = mount(ExpenseFilters, { props: { categories: CATEGORIES, errors: {} } })

    expect(wrapper.find('[aria-invalid]').exists()).toBe(false)
  })

  it('emits apply on submit and never submits natively', async () => {
    const wrapper = mount(ExpenseFilters, { props: { categories: CATEGORIES, errors: {} } })

    await wrapper.get('form').trigger('submit')

    expect(wrapper.emitted('apply')).toHaveLength(1)
  })

  it('has a Clear filters button that only emits clear, without submitting', async () => {
    const wrapper = mount(ExpenseFilters, { props: { categories: CATEGORIES, errors: {} } })
    const clear = wrapper.findAll('button').find((b) => b.text() === 'Clear filters')

    expect(clear?.attributes('type')).toBe('button')
    await clear?.trigger('click')

    expect(wrapper.emitted('clear')).toHaveLength(1)
    expect(wrapper.emitted('apply')).toBeUndefined()
  })

  it('shows the user text of a category name as an option label, as text', () => {
    const name = '<img src=x onerror=alert(1)>'
    const wrapper = mount(ExpenseFilters, {
      props: { categories: [category(FOOD, name)], errors: {} },
    })

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.findAll('option').some((o) => o.text() === name)).toBe(true)
  })
})

describe('ExpenseFilters: a category that is not in the list', () => {
  const UNKNOWN = '2b7c1f0e-5d3a-4c8e-9a41-2f6d8e7b1a91'

  it('offers "Unknown category" only once the categories have loaded', async () => {
    const wrapper = mount(ExpenseFilters, {
      props: { categories: [], categoriesLoaded: false, errors: {}, category: UNKNOWN },
    })
    expect(wrapper.text()).not.toContain('Unknown category')

    await wrapper.setProps({ categoriesLoaded: true })

    expect(wrapper.text()).toContain('Unknown category')
  })
})
