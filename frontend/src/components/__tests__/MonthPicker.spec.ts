import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import { isIsoMonth } from '@/lib/months'
import MonthPicker from '../MonthPicker.vue'

function picker(month: string) {
  return mount(MonthPicker, { props: { month } })
}
function button(wrapper: ReturnType<typeof picker>, text: string) {
  const found = wrapper.findAll('button').find((b) => b.text() === text)
  if (!found) throw new Error(`no button "${text}"`)
  return found
}

describe('MonthPicker: structure', () => {
  it('is a form with a labelled Month select of the twelve month names, in order', () => {
    const wrapper = picker('2026-10')

    expect(wrapper.find('form').exists()).toBe(true)
    const select = wrapper.get('select#summary-month')
    expect(wrapper.get('label[for="summary-month"]').text()).toBe('Month')
    const names = select.findAll('option').map((o) => o.text())
    expect(names).toHaveLength(12)
    expect(names[0]).toBe('January')
    expect(names[11]).toBe('December')
    expect(select.findAll('option').map((o) => o.attributes('value'))[9]).toBe('10')
  })

  it('has a labelled Year select from 2000 to 2099', () => {
    const wrapper = picker('2026-10')

    expect(wrapper.get('label[for="summary-year"]').text()).toBe('Year')
    const years = wrapper.get('select#summary-year').findAll('option')
    expect(years).toHaveLength(100)
    expect(years[0]?.text()).toBe('2000')
    expect(years[99]?.text()).toBe('2099')
  })

  it('shows the month it was given as selected', () => {
    const wrapper = picker('2026-10')

    expect((wrapper.get('#summary-month').element as HTMLSelectElement).value).toBe('10')
    expect((wrapper.get('#summary-year').element as HTMLSelectElement).value).toBe('2026')
  })

  it('follows a new month prop', async () => {
    const wrapper = picker('2026-10')

    await wrapper.setProps({ month: '2024-02' })

    expect((wrapper.get('#summary-month').element as HTMLSelectElement).value).toBe('02')
    expect((wrapper.get('#summary-year').element as HTMLSelectElement).value).toBe('2024')
  })

  it('has a Show submit button and two plain buttons for the neighbours', () => {
    const wrapper = picker('2026-10')

    expect(button(wrapper, 'Show').attributes('type')).toBe('submit')
    expect(button(wrapper, 'Previous month').attributes('type')).toBe('button')
    expect(button(wrapper, 'Next month').attributes('type')).toBe('button')
  })
})

describe('MonthPicker: choosing a month', () => {
  it('emits the chosen month when the form is submitted, and not before', async () => {
    const wrapper = picker('2026-10')

    await wrapper.get('#summary-month').setValue('03')
    await wrapper.get('#summary-year').setValue('2031')
    expect(wrapper.emitted('change')).toBeUndefined()
    await wrapper.get('form').trigger('submit')

    expect(wrapper.emitted('change')).toEqual([['2031-03']])
  })

  it('emits only valid months for every month and the first and last year', async () => {
    const wrapper = picker('2026-10')
    for (const year of ['2000', '2099']) {
      for (let m = 1; m <= 12; m += 1) {
        await wrapper.get('#summary-month').setValue(String(m).padStart(2, '0'))
        await wrapper.get('#summary-year').setValue(year)
        await wrapper.get('form').trigger('submit')
      }
    }

    const emitted = wrapper.emitted('change') ?? []
    expect(emitted).toHaveLength(24)
    expect(emitted.every(([value]) => isIsoMonth(value as string))).toBe(true)
  })

  it('Previous month and Next month emit the neighbours', async () => {
    const wrapper = picker('2026-12')

    await button(wrapper, 'Next month').trigger('click')
    await button(wrapper, 'Previous month').trigger('click')

    expect(wrapper.emitted('change')).toEqual([['2027-01'], ['2026-11']])
  })
})

describe('MonthPicker: the ends', () => {
  it('marks Previous month aria-disabled at 2000-01, keeps it focusable and emits nothing', async () => {
    const wrapper = picker('2000-01')
    const previous = button(wrapper, 'Previous month')

    await previous.trigger('click')

    expect(previous.attributes('aria-disabled')).toBe('true')
    expect(previous.attributes('disabled')).toBeUndefined()
    expect(button(wrapper, 'Next month').attributes('aria-disabled')).toBeUndefined()
    expect(wrapper.emitted('change')).toBeUndefined()
  })

  it('marks Next month aria-disabled at 2099-12 and emits nothing', async () => {
    const wrapper = picker('2099-12')
    const next = button(wrapper, 'Next month')

    await next.trigger('click')

    expect(next.attributes('aria-disabled')).toBe('true')
    expect(next.attributes('disabled')).toBeUndefined()
    expect(button(wrapper, 'Previous month').attributes('aria-disabled')).toBeUndefined()
    expect(wrapper.emitted('change')).toBeUndefined()
  })
})
