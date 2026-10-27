import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import PasswordField from '../PasswordField.vue'

function field(props: Record<string, unknown> = {}) {
  return mount(PasswordField, {
    props: {
      id: 'pw',
      label: 'Password',
      autocomplete: 'current-password',
      modelValue: '',
      ...props,
    },
  })
}

describe('PasswordField', () => {
  it('is a labelled password input with the autocomplete it is given', () => {
    const wrapper = field({ autocomplete: 'new-password' })
    const input = wrapper.get('input')

    expect(input.attributes('type')).toBe('password')
    expect(input.attributes('autocomplete')).toBe('new-password')
    expect(wrapper.get('label').attributes('for')).toBe('pw')
  })

  it('the toggle keeps one label, switches the type and says it is pressed, and controls the input', async () => {
    const wrapper = field()
    const toggle = wrapper.get('button')
    expect(toggle.attributes('type')).toBe('button')
    expect(toggle.text()).toBe('Show password')
    expect(toggle.attributes('aria-pressed')).toBe('false')
    expect(toggle.attributes('aria-controls')).toBe('pw')

    await toggle.trigger('click')
    expect(wrapper.get('input').attributes('type')).toBe('text')
    expect(toggle.text()).toBe('Show password')
    expect(toggle.attributes('aria-pressed')).toBe('true')

    await toggle.trigger('click')
    expect(wrapper.get('input').attributes('type')).toBe('password')
    expect(toggle.attributes('aria-pressed')).toBe('false')
  })

  it('can be hidden again from outside through v-model:visible', async () => {
    const wrapper = field({ visible: true })
    expect(wrapper.get('input').attributes('type')).toBe('text')

    await wrapper.setProps({ visible: false })

    expect(wrapper.get('input').attributes('type')).toBe('password')
  })

  it('keeps the browser from helping with the password', () => {
    const input = field().get('input')

    expect(input.attributes('spellcheck')).toBe('false')
    expect(input.attributes('autocapitalize')).toBe('none')
    expect(input.attributes('autocorrect')).toBe('off')
    expect(input.attributes('name')).toBeUndefined()
  })

  it('has one root element, so attributes given to it land on it', () => {
    const wrapper = field({ 'data-x': '1' })

    expect(wrapper.attributes('data-x')).toBe('1')
  })

  it('does not stop paste', () => {
    const input = field().get('input')
    const paste = new Event('paste', { bubbles: true, cancelable: true })

    input.element.dispatchEvent(paste)

    expect(paste.defaultPrevented).toBe(false)
  })

  it('links the error to the input and updates the model', async () => {
    const wrapper = field({ error: 'Too short.' })
    const input = wrapper.get('input')

    expect(input.attributes('aria-invalid')).toBe('true')
    expect(wrapper.get(`#${input.attributes('aria-describedby')}`).text()).toBe('Too short.')
    await input.setValue('abc')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['abc'])
  })
})
