import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import FormAlert from '../FormAlert.vue'

describe('FormAlert', () => {
  it('renders nothing without a message', () => {
    expect(
      mount(FormAlert, { props: { message: '' } })
        .find('[role="alert"]')
        .exists(),
    ).toBe(false)
    expect(mount(FormAlert).find('[role="alert"]').exists()).toBe(false)
  })

  it('shows the message in an alert', () => {
    const wrapper = mount(FormAlert, { props: { message: 'Email or password is incorrect.' } })

    expect(wrapper.get('[role="alert"]').text()).toBe('Email or password is incorrect.')
  })

  it('renders a message that looks like markup as text', () => {
    const wrapper = mount(FormAlert, { props: { message: '<b>no</b>' } })

    expect(wrapper.find('b').exists()).toBe(false)
    expect(wrapper.text()).toBe('<b>no</b>')
  })

  it('can take focus, for a failed submit with no invalid field', () => {
    const wrapper = mount(FormAlert, { props: { message: 'x' }, attachTo: document.body })

    ;(wrapper.vm as unknown as { focus: () => void }).focus()

    expect(document.activeElement).toBe(wrapper.get('[role="alert"]').element)
    wrapper.unmount()
  })

  it('does nothing when asked to focus while empty', () => {
    const wrapper = mount(FormAlert, { props: { message: '' } })

    expect(() => (wrapper.vm as unknown as { focus: () => void }).focus()).not.toThrow()
  })
})
