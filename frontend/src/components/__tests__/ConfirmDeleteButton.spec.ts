import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'

import ConfirmDeleteButton from '../ConfirmDeleteButton.vue'

let mounted: ReturnType<typeof mount> | null = null
afterEach(() => {
  mounted?.unmount()
  mounted = null
})

function render(props: Record<string, unknown> = {}) {
  mounted = mount(ConfirmDeleteButton, { props, attachTo: document.body })
  return mounted
}

const buttonLabels = (wrapper: ReturnType<typeof mount>) =>
  wrapper.findAll('button').map((b) => b.text())

// The n-th button, or a failure that names the problem instead of a type error.
function nth(wrapper: ReturnType<typeof mount>, index: number) {
  const button = wrapper.findAll('button')[index]
  if (!button) {
    throw new Error(`no button at ${index}`)
  }
  return button
}

describe('ConfirmDeleteButton', () => {
  it('starts with a single Delete button and emits nothing', () => {
    const wrapper = render()

    expect(buttonLabels(wrapper)).toEqual(['Delete'])
    expect(wrapper.emitted('confirm')).toBeUndefined()
  })

  it('asks first: Delete shows Confirm delete and Cancel, and still emits nothing', async () => {
    const wrapper = render()

    await wrapper.get('button').trigger('click')

    expect(buttonLabels(wrapper)).toEqual(['Confirm delete', 'Cancel'])
    expect(wrapper.emitted('confirm')).toBeUndefined()
  })

  it('moves focus to Cancel when it asks', async () => {
    const wrapper = render()

    await wrapper.get('button').trigger('click')

    const cancel = nth(wrapper, 1)
    expect(document.activeElement).toBe(cancel.element)
  })

  it('Cancel goes back to Delete and puts focus back on it', async () => {
    const wrapper = render()
    await wrapper.get('button').trigger('click')

    await nth(wrapper, 1).trigger('click')

    expect(buttonLabels(wrapper)).toEqual(['Delete'])
    expect(document.activeElement).toBe(wrapper.get('button').element)
    expect(wrapper.emitted('confirm')).toBeUndefined()
  })

  it('Confirm emits exactly once, even when clicked twice', async () => {
    const wrapper = render()
    await wrapper.get('button').trigger('click')
    const confirm = nth(wrapper, 0)

    // Both land before Vue has replaced the button, as with a fast double click.
    confirm.element.dispatchEvent(new Event('click'))
    confirm.element.dispatchEvent(new Event('click'))
    await nextTick()

    expect(wrapper.emitted('confirm')).toHaveLength(1)
  })

  it('goes back to Delete after Confirm, ready for another round', async () => {
    const wrapper = render()
    await wrapper.get('button').trigger('click')
    await nth(wrapper, 0).trigger('click')

    expect(buttonLabels(wrapper)).toEqual(['Delete'])
  })

  it('uses the given labels and an accessible name that says what is deleted', async () => {
    const wrapper = render({ label: 'Delete category', confirmLabel: 'Confirm delete category' })

    expect(buttonLabels(wrapper)).toEqual(['Delete category'])
    await wrapper.get('button').trigger('click')
    expect(buttonLabels(wrapper)).toEqual(['Confirm delete category', 'Cancel'])
  })

  it('disabled: Delete is aria-disabled, not native disabled, and a click does not ask', async () => {
    const wrapper = render({ disabled: true })

    expect(wrapper.get('button').attributes('aria-disabled')).toBe('true')
    expect(wrapper.get('button').attributes('disabled')).toBeUndefined()
    await wrapper.get('button').trigger('click')
    expect(buttonLabels(wrapper)).toEqual(['Delete'])
  })

  it('disabled while asking (a request is pending): Confirm and Cancel are aria-disabled and inert', async () => {
    const wrapper = render()
    await wrapper.get('button').trigger('click')

    await wrapper.setProps({ disabled: true })

    expect(wrapper.findAll('button').map((b) => b.attributes('aria-disabled'))).toEqual([
      'true',
      'true',
    ])
    await nth(wrapper, 0).trigger('click')
    await nth(wrapper, 1).trigger('click')
    expect(wrapper.emitted('confirm')).toBeUndefined()
    expect(buttonLabels(wrapper)).toEqual(['Confirm delete', 'Cancel'])
  })

  it('keeps focus on Delete when it becomes disabled right after Confirm', async () => {
    const wrapper = render()
    await wrapper.get('button').trigger('click')
    await nth(wrapper, 0).trigger('click')
    await wrapper.setProps({ disabled: true })
    await nextTick()

    expect(document.activeElement).toBe(wrapper.get('button').element)
  })

  it('asks a question: the choices are a group named by the question prop', async () => {
    const wrapper = render({ question: 'Delete this expense?' })

    await wrapper.get('button').trigger('click')

    expect(wrapper.get('[role="group"]').attributes('aria-label')).toBe('Delete this expense?')
  })

  it('has a default question', async () => {
    const wrapper = render()

    await wrapper.get('button').trigger('click')

    expect(wrapper.get('[role="group"]').attributes('aria-label')).toBe('Delete this item?')
  })

  it('Escape closes the question and puts focus back on Delete', async () => {
    const wrapper = render()
    await wrapper.get('button').trigger('click')

    await wrapper.get('[role="group"]').trigger('keydown', { key: 'Escape' })

    expect(buttonLabels(wrapper)).toEqual(['Delete'])
    expect(document.activeElement).toBe(wrapper.get('button').element)
    expect(wrapper.emitted('confirm')).toBeUndefined()
  })

  it('other keys do not close it', async () => {
    const wrapper = render()
    await wrapper.get('button').trigger('click')

    await wrapper.get('[role="group"]').trigger('keydown', { key: 'a' })

    expect(buttonLabels(wrapper)).toEqual(['Confirm delete', 'Cancel'])
  })

  it('after Confirm, focus goes back to Delete when the button is still there', async () => {
    const wrapper = render()
    await wrapper.get('button').trigger('click')

    await nth(wrapper, 0).trigger('click')
    await nextTick()

    expect(document.activeElement).toBe(wrapper.get('button').element)
  })

  it('after Confirm, nothing breaks when the parent removed the button meanwhile', async () => {
    const wrapper = render()
    await wrapper.get('button').trigger('click')

    await nth(wrapper, 0).trigger('click')
    wrapper.unmount()
    await nextTick()

    expect(document.activeElement).toBe(document.body)
  })

  it('after Confirm, leaves focus where the parent put it', async () => {
    const other = document.createElement('button')
    document.body.append(other)
    mounted = mount(ConfirmDeleteButton, {
      attachTo: document.body,
      attrs: { onConfirm: () => other.focus() },
    })
    await mounted.get('button').trigger('click')

    await nth(mounted, 0).trigger('click')
    await nextTick()

    expect(document.activeElement).toBe(other)
    other.remove()
  })

  it('Escape does not reach an outer Escape handler', async () => {
    const wrapper = render()
    const outer = vi.fn()
    document.body.addEventListener('keydown', outer)
    await wrapper.get('button').trigger('click')

    await wrapper.get('[role="group"]').trigger('keydown', { key: 'Escape' })

    document.body.removeEventListener('keydown', outer)
    expect(outer).not.toHaveBeenCalled()
  })

  describe('accessible names and description', () => {
    it('adds no aria-label or aria-describedby when none is given', async () => {
      const wrapper = render()

      expect(wrapper.get('button').attributes('aria-label')).toBeUndefined()
      expect(wrapper.get('button').attributes('aria-describedby')).toBeUndefined()
    })

    it('names Delete and Confirm after the subject, starting with the visible text', async () => {
      const wrapper = render({ subject: 'Food' })
      expect(wrapper.get('button').attributes('aria-label')).toBe('Delete Food')

      await wrapper.get('button').trigger('click')

      expect(nth(wrapper, 0).attributes('aria-label')).toBe('Confirm delete Food')
      expect(nth(wrapper, 1).attributes('aria-label')).toBeUndefined()
    })

    it('points Delete and Confirm at the description element', async () => {
      const wrapper = render({ describedby: 'hint-id' })
      expect(wrapper.get('button').attributes('aria-describedby')).toBe('hint-id')

      await wrapper.get('button').trigger('click')

      expect(nth(wrapper, 0).attributes('aria-describedby')).toBe('hint-id')
    })
  })
})
