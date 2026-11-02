import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h, nextTick } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { failedReceipt, pngFile, readyReceipt } from '@/composables/__tests__/receiptHarness'
import { LOAD_FIRST_TEXT, STILL_PROCESSING_MESSAGE, preCheckMessage } from '@/lib/receiptMessages'
import {
  ANNOUNCE_FAILED,
  ANNOUNCE_PROCESSING,
  ANNOUNCE_READY,
  ANNOUNCE_UPLOADING,
} from '@/lib/receiptState'
import type { ReceiptPhase } from '@/lib/receiptState'
import ReceiptPanel from '../ReceiptPanel.vue'
import ConfirmDeleteButton from '../ConfirmDeleteButton.vue'
import { elementsWithOwnText, fakeReceiptState, pickFiles } from './receiptPanelHarness'
import type { FakeInit } from './receiptPanelHarness'

// Accessibility of the receipt panel (plan C21): descriptions, the always-present live region, focus on errors.

const composable = vi.hoisted(() => ({ useReceipt: vi.fn() }))
vi.mock('@/composables/useReceipt', () => composable)

const RULES = 'JPEG, PNG, WebP or PDF, up to 10 MB.'
const ALL_PHASES: readonly ReceiptPhase[] = [
  'loading',
  'none',
  'requesting',
  'uploading',
  'processing',
  'ready',
  'failed',
]

const mounts: ReturnType<typeof mount>[] = []
afterEach(() => {
  mounts.splice(0).forEach((wrapper) => wrapper.unmount())
  document.body.replaceChildren()
})
beforeEach(() => {
  composable.useReceipt.mockReset()
})

function render(init: FakeInit = {}) {
  const fake = fakeReceiptState(init)
  composable.useReceipt.mockReturnValue(fake.state)
  const wrapper = mount(ReceiptPanel, { props: { expenseId: 'x' }, attachTo: document.body })
  mounts.push(wrapper)
  return { ...fake, wrapper }
}
function receiptFor(phase: ReceiptPhase) {
  if (phase === 'ready') return readyReceipt()
  return phase === 'failed' ? failedReceipt() : null
}
function liveRegion(wrapper: ReturnType<typeof mount>): HTMLElement {
  const regions = wrapper.element.ownerDocument.querySelectorAll('[aria-live="polite"]')
  const inside = Array.from(regions).filter((region) => wrapper.element.contains(region))
  expect(inside).toHaveLength(1)
  return inside[0] as HTMLElement
}
function buttonWithText(wrapper: ReturnType<typeof mount>, text: string) {
  const found = wrapper.findAll('button').find((b) => b.text() === text)
  if (!found) throw new Error(`no button "${text}"`)
  return found
}
function describedByTexts(input: Element): string[] {
  const ids = (input.getAttribute('aria-describedby') ?? '').split(/\s+/).filter(Boolean)
  return ids.map((id) => document.getElementById(id)?.textContent?.trim() ?? `<missing ${id}>`)
}

describe('ReceiptPanel a11y: the file input description', () => {
  it('lists the rules text in aria-describedby', () => {
    const { wrapper } = render({ phase: 'none' })

    const input = wrapper.get('input[type="file"]').element
    expect(describedByTexts(input)).toEqual([RULES])
  })

  it('adds the file message to aria-describedby when there is one, after the rules', async () => {
    const { wrapper, set } = render({ phase: 'none' })

    set.fileMessage('Choose a JPEG, PNG, WebP or PDF file up to 10 MB.')
    await nextTick()

    const input = wrapper.get('input[type="file"]').element
    expect(describedByTexts(input)).toEqual([
      RULES,
      'Choose a JPEG, PNG, WebP or PDF file up to 10 MB.',
    ])
  })

  it('drops the file message from aria-describedby when it clears', async () => {
    const { wrapper, set } = render({ phase: 'none', fileMessage: 'x' })

    set.fileMessage('')
    await nextTick()

    expect(describedByTexts(wrapper.get('input[type="file"]').element)).toEqual([RULES])
  })
})

describe('ReceiptPanel a11y: the live region', () => {
  it.each(ALL_PHASES)(
    'exists from the first render in phase %s, polite and not hidden',
    (phase) => {
      const { wrapper } = render({ phase, receipt: receiptFor(phase) })

      const region = liveRegion(wrapper)
      expect(region.hasAttribute('hidden')).toBe(false)
      expect(region.hasAttribute('aria-hidden')).toBe(false)
      expect(region.classList.contains('visually-hidden')).toBe(true)
    },
  )

  it('keeps the very same element while the phase changes through every phase', async () => {
    const { wrapper, set } = render({ phase: 'loading' })
    const first = liveRegion(wrapper)

    for (const phase of ALL_PHASES) {
      set.phase(phase)
      set.receipt(receiptFor(phase))
      await nextTick()
      expect(liveRegion(wrapper)).toBe(first)
    }
  })

  it('is empty when there is nothing to announce', () => {
    const { wrapper } = render({ phase: 'none' })

    expect(liveRegion(wrapper).textContent).toBe('')
  })

  it.each([ANNOUNCE_UPLOADING, ANNOUNCE_PROCESSING, ANNOUNCE_READY, ANNOUNCE_FAILED])(
    'shows exactly the milestone "%s"',
    async (milestone) => {
      const { wrapper, set } = render({ phase: 'none' })

      set.announcement(milestone)
      await nextTick()

      expect(liveRegion(wrapper).textContent).toBe(milestone)
    },
  )

  it('follows the announcement through the four milestones in order', async () => {
    const { wrapper, set } = render({ phase: 'none' })
    const seen: string[] = []

    for (const milestone of [ANNOUNCE_UPLOADING, ANNOUNCE_PROCESSING, ANNOUNCE_READY]) {
      set.announcement(milestone)
      await nextTick()
      seen.push(liveRegion(wrapper).textContent ?? '')
    }

    expect(seen).toEqual([ANNOUNCE_UPLOADING, ANNOUNCE_PROCESSING, ANNOUNCE_READY])
  })

  it('never contains a percent, and progress changes do not touch it at all', async () => {
    const { wrapper, set } = render({
      phase: 'uploading',
      progress: 10,
      announcement: ANNOUNCE_UPLOADING,
    })
    const region = liveRegion(wrapper)
    const observer = new MutationObserver(() => {})
    observer.observe(region, {
      subtree: true,
      childList: true,
      characterData: true,
      attributes: true,
    })

    for (const percent of [45, 80, 100]) {
      set.progress(percent)
      await nextTick()
    }

    expect(observer.takeRecords()).toHaveLength(0)
    observer.disconnect()
    expect(region.textContent).toBe(ANNOUNCE_UPLOADING)
    expect(region.textContent).not.toMatch(/%/)
    expect(wrapper.text()).toContain('Uploading… 100%')
  })

  it('is a different element from the visible progress text, and neither contains the other', () => {
    const { wrapper } = render({
      phase: 'uploading',
      progress: 45,
      announcement: ANNOUNCE_UPLOADING,
    })

    const region = liveRegion(wrapper)
    const texts = elementsWithOwnText(wrapper.element, 'Uploading… 45%')
    expect(texts).toHaveLength(1)
    const text = texts[0] as Element
    expect(text).not.toBe(region)
    expect(region.contains(text)).toBe(false)
    expect(text.contains(region)).toBe(false)
    expect(region.contains(wrapper.get('progress').element)).toBe(false)
  })

  it('is not part of the alert, the input or the buttons', () => {
    const { wrapper } = render({ phase: 'ready', receipt: readyReceipt(), message: 'm' })

    const region = liveRegion(wrapper)
    expect(region.contains(wrapper.get('[role="alert"]').element)).toBe(false)
    expect(region.querySelector('button, input, a')).toBeNull()
  })
})

type Rendered = ReturnType<typeof render>
// Waits until every pending promise and render has settled, however many awaits the panel needs.
const ticks = async (): Promise<void> => {
  await flushPromises()
}

// The user picks a file and the upload ends in a failure: busy first, then back to idle with the text, in one step.
async function startUpload({ wrapper, set, actions }: Rendered): Promise<void> {
  actions.choose.mockImplementation(() => {
    set.phase('requesting')
    return Promise.resolve('started')
  })
  const input = wrapper.get('input[type="file"]')
  pickFiles(input.element as HTMLInputElement, [pngFile()])
  await input.trigger('change')
  await ticks()
}
async function failUpload(rendered: Rendered, text: string): Promise<void> {
  await startUpload(rendered)
  rendered.set.phase('none')
  rendered.set.message(text)
  await ticks()
}
// An unrelated text field of the page (the expense form) that holds focus.
function unrelatedField(): HTMLInputElement {
  const field = document.createElement('input')
  field.type = 'text'
  document.body.append(field)
  field.focus()
  return field
}

describe('ReceiptPanel a11y: focus goes to the alert only for what the user did', () => {
  it('moves focus to the alert when an upload the user started fails', async () => {
    const rendered = render({ phase: 'none' })

    await failUpload(rendered, 'Upload interrupted. Try again.')

    expect(document.activeElement).toBe(rendered.wrapper.get('[role="alert"]').element)
  })

  it('does it again for a later failure, after the earlier message was cleared', async () => {
    const rendered = render({ phase: 'none' })
    await failUpload(rendered, 'First.')
    rendered.set.message('')
    await ticks()
    const input = rendered.wrapper.get('input[type="file"]').element as HTMLInputElement
    input.focus()

    await failUpload(rendered, 'Second.')

    expect(document.activeElement).toBe(rendered.wrapper.get('[role="alert"]').element)
    expect(rendered.wrapper.get('[role="alert"]').text()).toBe('Second.')
  })

  it('moves focus to the alert when the server refuses the policy (back to the earlier receipt)', async () => {
    const rendered = render({ phase: 'ready', receipt: readyReceipt() })
    await startUpload(rendered)

    rendered.set.phase('ready')
    rendered.set.message('The receipt changed in another tab. Reload.')
    await ticks()

    expect(document.activeElement).toBe(rendered.wrapper.get('[role="alert"]').element)
  })

  it('moves focus to the alert when a delete the user confirmed fails', async () => {
    const { wrapper, set, actions } = render({ phase: 'ready', receipt: readyReceipt() })
    actions.remove.mockImplementation(() => {
      set.message('Could not delete. Try again.')
      return Promise.resolve()
    })

    wrapper
      .findAllComponents(ConfirmDeleteButton)
      .find((c) => c.props('label') === 'Delete receipt')
      ?.vm.$emit('confirm')
    await ticks()

    expect(document.activeElement).toBe(wrapper.get('[role="alert"]').element)
  })

  it('moves focus to the alert when "Try again" after a failed first read fails again', async () => {
    const { wrapper, set, actions } = render({ phase: 'none', loadFailed: true, message: 'Down.' })
    actions.start.mockImplementation(() => {
      set.phase('loading')
      set.loadFailed(false)
      set.message('')
      return Promise.resolve()
    })
    await buttonWithText(wrapper, 'Try again').trigger('click')
    await ticks()

    set.phase('none')
    set.loadFailed(true)
    set.message('Still down.')
    await ticks()

    expect(document.activeElement).toBe(wrapper.get('[role="alert"]').element)
  })

  it('does not move focus for a message nobody asked for (the page loaded with an error)', async () => {
    const field = unrelatedField()
    const { wrapper, set } = render({ phase: 'loading' })

    set.phase('none')
    set.loadFailed(true)
    set.message('Down.')
    await ticks()

    expect(wrapper.get('[role="alert"]').text()).toBe('Down.')
    expect(document.activeElement).toBe(field)
  })

  it('does not move focus for the still-processing text, which is a neutral status', async () => {
    const field = unrelatedField()
    const { wrapper, set } = render({ phase: 'processing' })

    set.stalled(true)
    set.message(STILL_PROCESSING_MESSAGE)
    await ticks()

    expect(wrapper.get('[role="status"]').text()).toBe(STILL_PROCESSING_MESSAGE)
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(document.activeElement).toBe(field)
  })

  it('does not move focus for a poll error that stays an alert', async () => {
    const field = unrelatedField()
    const { wrapper, set } = render({ phase: 'processing' })

    set.stalled(true)
    set.message('Something went wrong on our side. Try again.')
    await ticks()

    expect(wrapper.get('[role="alert"]').text()).toBe(
      'Something went wrong on our side. Try again.',
    )
    expect(document.activeElement).toBe(field)
  })

  it('does not move focus when a failed status arrives from polling', async () => {
    const field = unrelatedField()
    const { wrapper, set } = render({ phase: 'processing' })

    set.receipt(failedReceipt())
    set.phase('failed')
    set.message('The file could not be read.')
    await ticks()

    expect(wrapper.get('[role="alert"]').text()).toBe('The file could not be read.')
    expect(document.activeElement).toBe(field)
  })

  it("does not move focus for a poll message after the user's upload succeeded", async () => {
    const rendered = render({ phase: 'none' })
    await startUpload(rendered)
    rendered.set.phase('processing')
    await ticks()
    const field = unrelatedField()

    rendered.set.stalled(true)
    rendered.set.message('Something went wrong on our side. Try again.')
    await ticks()

    expect(document.activeElement).toBe(field)
  })

  it('does not move focus for a picked file the browser pre-check refused', async () => {
    const { wrapper, set, actions } = render({ phase: 'none' })
    actions.choose.mockImplementation(() => {
      set.fileMessage('Choose a JPEG, PNG, WebP or PDF file up to 10 MB.')
      return Promise.resolve('rejected')
    })
    const input = wrapper.get('input[type="file"]')
    pickFiles(input.element as HTMLInputElement, [pngFile()])
    const field = unrelatedField()

    await input.trigger('change')
    await ticks()
    set.phase('processing')
    set.stalled(true)
    set.message('Something went wrong on our side. Try again.')
    await ticks()

    expect(document.activeElement).toBe(field)
  })

  it('does not mark a delete that is a no-op (the panel is busy): a later background message keeps focus away', async () => {
    const { wrapper, set, actions } = render({ phase: 'ready', receipt: readyReceipt() })
    const field = unrelatedField()
    const remove = wrapper
      .findAllComponents(ConfirmDeleteButton)
      .find((c) => c.props('label') === 'Delete receipt')
    // A no-op remove whose answer is slow: nothing was started, so the window must not count as the user's.
    actions.remove.mockImplementation(() => new Promise<void>(() => {}))
    set.phase('loading')

    remove?.vm.$emit('confirm')
    set.message('Something went wrong on our side. Try again.')
    await ticks()

    expect(actions.remove).toHaveBeenCalledOnce()
    expect(wrapper.get('[role="alert"]').text()).toBe(
      'Something went wrong on our side. Try again.',
    )
    expect(document.activeElement).toBe(field)
  })

  it('does not steal focus when progress or other state changes while a message stays', async () => {
    const { wrapper, set } = render({ phase: 'none', message: 'Already shown.' })
    const input = wrapper.get('input[type="file"]').element as HTMLInputElement
    input.focus()

    set.announcement(ANNOUNCE_READY)
    await nextTick()
    await nextTick()

    expect(document.activeElement).toBe(input)
  })

  it('does not move focus when the message clears', async () => {
    const { wrapper, set } = render({ phase: 'none', message: 'Gone soon.' })
    const input = wrapper.get('input[type="file"]').element as HTMLInputElement
    input.focus()

    set.message('')
    await nextTick()
    await nextTick()

    expect(document.activeElement).toBe(input)
  })
})

describe('ReceiptPanel a11y: status is text, and controls are named', () => {
  it('names the buttons by their text', () => {
    const { wrapper } = render({ phase: 'processing', stalled: true, message: 'Still.' })

    const names = wrapper.findAll('button').map((b) => b.text())
    expect(names).toContain('Check again')
    expect(names).toContain('Delete receipt')
  })

  it('shows the failure as text, not by colour alone', () => {
    const { wrapper } = render({ phase: 'failed', receipt: failedReceipt() })

    expect(wrapper.text()).toContain('The file could not be read.')
  })
})

describe('ReceiptPanel a11y: a file message is announced and marks the input invalid', () => {
  it.each([
    ['a wrong type', preCheckMessage('type')],
    ['a file that is too big', preCheckMessage('too-large')],
    ['an empty file', preCheckMessage('empty')],
    ['a file picked before the receipt was read', LOAD_FIRST_TEXT],
  ])('for %s: role=alert, aria-invalid, and still described by it', async (_name, text) => {
    const { wrapper, set } = render({ phase: 'none' })
    const input = wrapper.get('input[type="file"]')
    expect(input.attributes('aria-invalid')).toBeUndefined()
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)

    set.fileMessage(text)
    await nextTick()

    const alert = wrapper.get('[role="alert"]')
    expect(alert.text()).toBe(text)
    expect(input.attributes('aria-invalid')).toBe('true')
    const ids = (input.attributes('aria-describedby') ?? '').split(/\s+/)
    expect(ids).toContain(alert.attributes('id'))
    expect(describedByTexts(input.element)).toEqual([RULES, text])
  })

  it('takes both back when the message clears', async () => {
    const { wrapper, set } = render({ phase: 'none', fileMessage: 'x' })

    set.fileMessage('')
    await nextTick()

    expect(wrapper.get('input[type="file"]').attributes('aria-invalid')).toBeUndefined()
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
  })

  it('does not take focus from the user', async () => {
    const { wrapper, set } = render({ phase: 'none' })
    const input = wrapper.get('input[type="file"]').element as HTMLInputElement
    input.focus()

    set.fileMessage('Choose a JPEG, PNG, WebP or PDF file up to 10 MB.')
    await ticks()

    expect(document.activeElement).toBe(input)
  })

  it('announces a repeated identical message again: the alert element is replaced by a new one', async () => {
    const { wrapper, set, actions } = render({ phase: 'none' })
    const text = preCheckMessage('type')
    // What the real composable does for the same refused file: the message is set to the very same text.
    actions.choose.mockImplementation(() => {
      set.fileMessage(text)
      return Promise.resolve('rejected')
    })
    const input = wrapper.get('input[type="file"]')

    pickFiles(input.element as HTMLInputElement, [pngFile()])
    await input.trigger('change')
    await ticks()
    const first = wrapper.get('[role="alert"]').element
    pickFiles(input.element as HTMLInputElement, [pngFile()])
    await input.trigger('change')
    await ticks()
    const second = wrapper.get('[role="alert"]').element

    expect(second).not.toBe(first)
    expect(first.isConnected).toBe(false)
    expect(second.textContent?.trim()).toBe(text)
    expect(wrapper.findAll('[role="alert"]')).toHaveLength(1)
    expect(describedByTexts(input.element)).toEqual([RULES, text])
  })

  it('does not replace the alert when the message does not come from a pick', async () => {
    const { wrapper, set } = render({ phase: 'none', fileMessage: 'x' })
    const first = wrapper.get('[role="alert"]').element

    set.fileMessage('y')
    await ticks()

    expect(wrapper.get('[role="alert"]').element).toBe(first)
  })
})

describe('ReceiptPanel a11y: the panel heading', () => {
  it('names the section, with an id of its own, and can take focus by script only', () => {
    const { wrapper } = render({ phase: 'none' })

    const heading = wrapper.get('h2')
    expect(heading.text()).toBe('Receipt')
    expect(heading.attributes('tabindex')).toBe('-1')
    expect(heading.attributes('id')).toBeTruthy()
    expect(wrapper.get('section').attributes('aria-labelledby')).toBe(heading.attributes('id'))
  })

  it('has a different id for every panel on the page', () => {
    composable.useReceipt.mockReturnValue(fakeReceiptState({ phase: 'none' }).state)
    const page = defineComponent({
      render: () =>
        h('div', [h(ReceiptPanel, { expenseId: 'a' }), h(ReceiptPanel, { expenseId: 'b' })]),
    })
    const wrapper = mount(page, { attachTo: document.body })
    mounts.push(wrapper)

    const ids = wrapper.findAll('h2').map((heading) => heading.attributes('id'))
    expect(ids).toHaveLength(2)
    expect(new Set(ids).size).toBe(2)
  })
})

describe('ReceiptPanel a11y: focus lands somewhere defined when the focused control goes away', () => {
  const fileInputOf = (wrapper: ReturnType<typeof mount>) =>
    wrapper.get('input[type="file"]').element as HTMLInputElement
  function focused(wrapper: ReturnType<typeof mount>, text: string) {
    const button = buttonWithText(wrapper, text)
    ;(button.element as HTMLButtonElement).focus()
    expect(document.activeElement).toBe(button.element)
    return button
  }

  it('after a successful Delete: the file input', async () => {
    const { wrapper, set, actions } = render({ phase: 'ready', receipt: readyReceipt() })
    actions.remove.mockImplementation(() => {
      set.receipt(null)
      set.phase('none')
      return Promise.resolve()
    })
    focused(wrapper, 'Delete receipt')

    wrapper
      .findAllComponents(ConfirmDeleteButton)
      .find((c) => c.props('label') === 'Delete receipt')
      ?.vm.$emit('confirm')
    await ticks()

    expect(wrapper.find('button').exists()).toBe(false)
    expect(document.activeElement).toBe(fileInputOf(wrapper))
  })

  it('after a Delete that failed: stays where it is (the Delete button is still there)', async () => {
    const { wrapper, actions } = render({ phase: 'ready', receipt: readyReceipt() })
    actions.remove.mockResolvedValue(undefined)
    const button = focused(wrapper, 'Delete receipt')

    wrapper
      .findAllComponents(ConfirmDeleteButton)
      .find((c) => c.props('label') === 'Delete receipt')
      ?.vm.$emit('confirm')
    await ticks()

    expect(document.activeElement).toBe(button.element)
  })

  it('after a Delete, when the user has moved on meanwhile: does not steal focus', async () => {
    const { wrapper, set, actions } = render({ phase: 'ready', receipt: readyReceipt() })
    let finish: () => void = () => {}
    actions.remove.mockImplementation(
      () =>
        new Promise<void>((resolve) => {
          finish = () => {
            set.receipt(null)
            set.phase('none')
            resolve()
          }
        }),
    )
    focused(wrapper, 'Delete receipt')
    wrapper
      .findAllComponents(ConfirmDeleteButton)
      .find((c) => c.props('label') === 'Delete receipt')
      ?.vm.$emit('confirm')
    const field = unrelatedField()

    finish()
    await ticks()

    expect(document.activeElement).toBe(field)
  })

  it('after Cancel (replace): the file input', async () => {
    const { wrapper, set, actions } = render({
      phase: 'ready',
      receipt: readyReceipt(),
      pendingReplacement: pngFile(),
    })
    actions.cancelReplace.mockImplementation(() => set.pendingReplacement(null))
    const cancel = wrapper.findAll('button').filter((b) => b.text() === 'Cancel')
    ;(cancel[cancel.length - 1]?.element as HTMLButtonElement).focus()

    await cancel[cancel.length - 1]?.trigger('click')
    await ticks()

    expect(document.activeElement).toBe(fileInputOf(wrapper))
  })

  it('after Confirm replace: the file input', async () => {
    const { wrapper, set, actions } = render({
      phase: 'ready',
      receipt: readyReceipt(),
      pendingReplacement: pngFile(),
    })
    actions.confirmReplace.mockImplementation(() => {
      set.pendingReplacement(null)
      set.phase('requesting')
      return Promise.resolve()
    })
    const confirm = wrapper
      .findAllComponents(ConfirmDeleteButton)
      .find((c) => c.props('label') === 'Replace receipt')
    // The ask step of the confirm button, as a user does it: Replace, then Confirm replace.
    await confirm?.get('button').trigger('click')
    await ticks()
    const button = buttonWithText(wrapper, 'Confirm replace')
    ;(button.element as HTMLButtonElement).focus()

    await button.trigger('click')
    await ticks()

    expect(document.activeElement).toBe(fileInputOf(wrapper))
  })

  it('after Retry: the file input', async () => {
    const { wrapper, set, actions } = render({ phase: 'none', canRetry: true })
    actions.retry.mockImplementation(() => {
      set.canRetry(false)
      set.phase('requesting')
      return Promise.resolve()
    })

    await focused(wrapper, 'Retry').trigger('click')
    await ticks()

    expect(document.activeElement).toBe(fileInputOf(wrapper))
  })

  it('after Check again: the file input', async () => {
    const { wrapper, set, actions } = render({
      phase: 'processing',
      stalled: true,
      message: STILL_PROCESSING_MESSAGE,
    })
    actions.checkAgain.mockImplementation(() => {
      set.stalled(false)
      set.message('')
      return Promise.resolve()
    })

    await focused(wrapper, 'Check again').trigger('click')
    await ticks()

    expect(document.activeElement).toBe(fileInputOf(wrapper))
  })

  it('after Check again, does not wait for the request: focus is placed before it answers', async () => {
    const { wrapper, set, actions } = render({ phase: 'processing', stalled: true })
    actions.checkAgain.mockImplementation(() => {
      set.stalled(false)
      return new Promise<void>(() => {})
    })

    await focused(wrapper, 'Check again').trigger('click')
    await ticks()

    expect(document.activeElement).toBe(fileInputOf(wrapper))
  })

  it('after Try again (the first read failed): the panel heading, as there is no input while it loads', async () => {
    const { wrapper, set, actions } = render({ phase: 'none', loadFailed: true })
    actions.start.mockImplementation(() => {
      set.phase('loading')
      set.loadFailed(false)
      return new Promise<void>(() => {})
    })

    await focused(wrapper, 'Try again').trigger('click')
    await ticks()

    expect(wrapper.find('input[type="file"]').exists()).toBe(false)
    expect(document.activeElement).toBe(wrapper.get('h2').element)
  })
})
