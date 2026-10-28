import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import FormField from '../FormField.vue'

function field(props: Record<string, unknown> = {}) {
  return mount(FormField, { props: { id: 'amount', label: 'Amount', modelValue: '', ...props } })
}

describe('FormField', () => {
  it('links the label to the input', () => {
    const wrapper = field()

    expect(wrapper.get('label').attributes('for')).toBe('amount')
    expect(wrapper.get('input').attributes('id')).toBe('amount')
    expect(wrapper.get('label').text()).toBe('Amount')
  })

  it('without an error: not invalid and nothing to describe it', () => {
    const input = field().get('input')

    expect(input.attributes('aria-invalid')).toBeUndefined()
    expect(input.attributes('aria-describedby')).toBeUndefined()
  })

  it('with an error: aria-invalid, and aria-describedby points at the visible message', () => {
    const wrapper = field({ error: 'Enter an amount.' })
    const input = wrapper.get('input')

    expect(input.attributes('aria-invalid')).toBe('true')
    const message = wrapper.get(`#${input.attributes('aria-describedby')}`)
    expect(message.text()).toBe('Enter an amount.')
  })

  it('describes the input by both the hint and the error', () => {
    const wrapper = field({ hint: 'Up to 2 decimals', error: 'Too many decimals.' })
    const ids = wrapper.get('input').attributes('aria-describedby')?.split(' ') ?? []

    expect(ids.map((id) => wrapper.get(`#${id}`).text())).toEqual([
      'Up to 2 decimals',
      'Too many decimals.',
    ])
  })

  it('describes the input by the hint alone when there is no error', () => {
    const wrapper = field({ hint: 'Up to 2 decimals' })
    const input = wrapper.get('input')

    expect(wrapper.get(`#${input.attributes('aria-describedby')}`).text()).toBe('Up to 2 decimals')
    expect(input.attributes('aria-invalid')).toBeUndefined()
  })

  it('derives the message ids from the field id, so two fields never share one', () => {
    const first = field({ id: 'a', error: 'x' }).get('input').attributes('aria-describedby')
    const second = field({ id: 'b', error: 'x' }).get('input').attributes('aria-describedby')

    expect(first).not.toBe(second)
  })

  it('renders an error that looks like markup as text', () => {
    const wrapper = field({ error: '<i>bad</i>' })

    expect(wrapper.find('i').exists()).toBe(false)
    expect(wrapper.text()).toContain('<i>bad</i>')
  })

  it('works as v-model and passes type, autocomplete and inputmode to the input', async () => {
    const wrapper = field({ type: 'password', autocomplete: 'new-password', inputmode: 'decimal' })
    const input = wrapper.get('input')

    await input.setValue('hunter2')

    expect(wrapper.emitted('update:modelValue')).toEqual([['hunter2']])
    expect(input.attributes('type')).toBe('password')
    expect(input.attributes('autocomplete')).toBe('new-password')
    expect(input.attributes('inputmode')).toBe('decimal')
  })

  it('shows the model value', () => {
    expect((field({ modelValue: '12.50' }).get('input').element as HTMLInputElement).value).toBe(
      '12.50',
    )
  })

  it('lets the slot supply another control and hands it the same wiring', () => {
    const wrapper = mount(FormField, {
      props: { id: 'cat', label: 'Category', error: 'Pick one.' },
      slots: {
        default: `<template #default="{ id, describedby, invalid }">
          <select :id="id" :aria-describedby="describedby" :aria-invalid="invalid"></select>
        </template>`,
      },
    })
    const select = wrapper.get('select')

    expect(wrapper.find('input').exists()).toBe(false)
    expect(wrapper.get('label').attributes('for')).toBe(select.attributes('id'))
    expect(select.attributes('aria-invalid')).toBe('true')
    expect(wrapper.get(`#${select.attributes('aria-describedby')}`).text()).toBe('Pick one.')
  })

  it('passes required, maxlength and name to the input, not to the wrapper', () => {
    const wrapper = field({ required: true, maxlength: 20, name: 'email' })
    const input = wrapper.get('input')

    expect(input.attributes('required')).toBeDefined()
    expect(input.attributes('maxlength')).toBe('20')
    expect(input.attributes('name')).toBe('email')
    expect(wrapper.element.hasAttribute('name')).toBe(false)
    expect(wrapper.element.hasAttribute('required')).toBe(false)
  })

  it('gives the slot the attributes too', () => {
    const wrapper = mount(FormField, {
      props: { id: 'cat', label: 'Category' },
      attrs: { required: true, name: 'categoryId' },
      slots: {
        default: `<template #default="{ id, attrs }"><select :id="id" v-bind="attrs"></select></template>`,
      },
    })

    expect(wrapper.get('select').attributes('name')).toBe('categoryId')
    expect(wrapper.get('select').attributes('required')).toBeDefined()
    expect(wrapper.element.hasAttribute('name')).toBe(false)
  })
})
