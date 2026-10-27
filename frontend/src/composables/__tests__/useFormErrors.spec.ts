import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, nextTick } from 'vue'

import { useFormErrors } from '../useFormErrors'

afterEach(() => {
  document.body.replaceChildren()
})

function setup() {
  let api!: ReturnType<typeof useFormErrors<'email' | 'password'>>
  const host = document.createElement('div')
  document.body.appendChild(host)
  mount(
    defineComponent({
      setup() {
        api = useFormErrors(['email', 'password'] as const, 'f')
        return () => h('div', [h('input', { id: 'f-email' }), h('input', { id: 'f-password' })])
      },
    }),
    { attachTo: host },
  )
  return api
}

describe('useFormErrors', () => {
  it('starts with no errors', () => {
    const errors = setup()

    expect(errors.fieldErrors.value).toEqual({})
    expect(errors.formMessage.value).toBe('')
    expect(errors.hasErrors()).toBe(false)
  })

  it('show replaces everything; clear empties it', () => {
    const errors = setup()

    errors.show({ password: 'Short.' }, 'Form message')
    expect(errors.fieldErrors.value).toEqual({ password: 'Short.' })
    expect(errors.formMessage.value).toBe('Form message')
    expect(errors.hasErrors()).toBe(true)

    errors.show({ email: 'Bad.' }, '')
    expect(errors.fieldErrors.value).toEqual({ email: 'Bad.' })

    errors.clear()
    expect(errors.hasErrors()).toBe(false)
  })

  it('focusFirst focuses the first field in declared order that has an error', async () => {
    const errors = setup()
    errors.show({ password: 'b', email: 'a' }, '')

    await errors.focusFirst(undefined)

    expect(document.activeElement?.id).toBe('f-email')
  })

  it('focusFirst falls back to the alert when no field has an error', async () => {
    const errors = setup()
    const alert = { focus: vi.fn() }
    errors.show({}, 'Oops')

    await errors.focusFirst(alert)

    expect(alert.focus).toHaveBeenCalledTimes(1)
  })

  it('focusFirst waits for the DOM to show the messages first', async () => {
    const errors = setup()
    const alert = { focus: vi.fn() }
    errors.show({}, 'Oops')

    const pending = errors.focusFirst(alert)
    expect(alert.focus).not.toHaveBeenCalled()
    await pending
    await nextTick()

    expect(alert.focus).toHaveBeenCalledTimes(1)
  })
})
