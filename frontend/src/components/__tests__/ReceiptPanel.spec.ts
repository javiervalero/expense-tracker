import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { failedReceipt, pngFile, readyReceipt } from '@/composables/__tests__/receiptHarness'
import {
  LOAD_FIRST_TEXT,
  STILL_PROCESSING_MESSAGE,
  UPLOAD_RUNNING_TEXT,
} from '@/lib/receiptMessages'
import ConfirmDeleteButton from '../ConfirmDeleteButton.vue'
import ReceiptPanel from '../ReceiptPanel.vue'
import ReceiptPreview from '../ReceiptPreview.vue'
import { fakeReceiptState, pickFiles } from './receiptPanelHarness'
import type { FakeInit } from './receiptPanelHarness'

// The states of the receipt panel (plan phase-5 WP12, C16 and C21) against a hand-built ReceiptState.

const composable = vi.hoisted(() => ({ useReceipt: vi.fn() }))
vi.mock('@/composables/useReceipt', () => composable)

const EXPENSE_ID = '5a1c1f0e-5d3a-4c8e-9a41-2f6d8e7b1a91'
const ACCEPT = 'image/jpeg,image/png,image/webp,application/pdf'

const mounts: ReturnType<typeof mount>[] = []
afterEach(() => {
  mounts.splice(0).forEach((wrapper) => wrapper.unmount())
  document.body.replaceChildren()
})
beforeEach(() => {
  composable.useReceipt.mockReset()
})

function render(init: FakeInit = {}, expenseId = EXPENSE_ID) {
  const fake = fakeReceiptState(init)
  composable.useReceipt.mockReturnValue(fake.state)
  const wrapper = mount(ReceiptPanel, { props: { expenseId }, attachTo: document.body })
  mounts.push(wrapper)
  return { ...fake, wrapper }
}
const fileInput = (wrapper: ReturnType<typeof mount>) => wrapper.find('input[type="file"]')
function buttonNamed(wrapper: ReturnType<typeof mount>, text: string) {
  return wrapper.findAll('button').find((b) => b.text() === text)
}
function confirmButtonWithLabel(wrapper: ReturnType<typeof mount>, label: string) {
  const found = wrapper
    .findAllComponents(ConfirmDeleteButton)
    .find((component) => component.props('label') === label)
  if (!found) throw new Error(`no ConfirmDeleteButton "${label}"`)
  return found
}

describe('ReceiptPanel: wiring', () => {
  it('reads the receipt of the given expense, through a getter that follows the prop', async () => {
    const { wrapper } = render()

    const argument = composable.useReceipt.mock.calls[0]?.[0] as () => string
    expect(argument()).toBe(EXPENSE_ID)
    await wrapper.setProps({ expenseId: 'other' })
    expect(argument()).toBe('other')
  })

  it('calls start() once on mount', () => {
    const { actions } = render()

    expect(actions.start).toHaveBeenCalledOnce()
  })
})

describe('ReceiptPanel: loading', () => {
  it('shows "Loading receipt…" and no file input', () => {
    const { wrapper } = render({ phase: 'loading' })

    expect(wrapper.text()).toContain('Loading receipt…')
    expect(fileInput(wrapper).exists()).toBe(false)
  })
})

describe('ReceiptPanel: the file input', () => {
  it('is labelled "Receipt file" and accepts exactly the four types', () => {
    const { wrapper } = render({ phase: 'none' })

    const input = fileInput(wrapper)
    expect(input.exists()).toBe(true)
    expect(input.attributes('accept')).toBe(ACCEPT)
    const label = wrapper.find(`label[for="${input.attributes('id')}"]`)
    expect(label.text()).toBe('Receipt file')
  })

  it('calls choose with the first file only', async () => {
    const { wrapper, actions } = render({ phase: 'none' })
    const first = pngFile()
    const second = new File(['x'], 'other.png', { type: 'image/png' })
    pickFiles(fileInput(wrapper).element as HTMLInputElement, [first, second])

    await fileInput(wrapper).trigger('change')
    expect(actions.choose).toHaveBeenCalledExactlyOnceWith(first)
    expect(actions.choose).toHaveBeenCalledWith(first)
  })

  it('does not call choose when the change carries no file', async () => {
    const { wrapper, actions } = render({ phase: 'none' })
    pickFiles(fileInput(wrapper).element as HTMLInputElement, [])

    await fileInput(wrapper).trigger('change')

    expect(actions.choose).not.toHaveBeenCalled()
  })

  it('clears its value after a pick, so the same file can be picked again', async () => {
    const { wrapper } = render({ phase: 'none' })
    const { valueWrites } = pickFiles(fileInput(wrapper).element as HTMLInputElement, [pngFile()])

    await fileInput(wrapper).trigger('change')

    expect(valueWrites).toEqual([''])
  })

  it('shows the file message under the input', () => {
    const { wrapper } = render({ phase: 'none', fileMessage: 'Choose a smaller file.' })

    expect(wrapper.text()).toContain('Choose a smaller file.')
    // Announced by itself (role=alert on the file message), and not the panel's FormAlert (which takes focus).
    const alerts = wrapper.findAll('[role="alert"]')
    expect(alerts).toHaveLength(1)
    expect(alerts[0]?.text()).toBe('Choose a smaller file.')
    expect(alerts[0]?.attributes('tabindex')).toBeUndefined()
    expect(fileInput(wrapper).attributes('aria-invalid')).toBe('true')
  })

  it('is not invalid without a file message', () => {
    const { wrapper } = render({ phase: 'none' })

    expect(fileInput(wrapper).attributes('aria-invalid')).toBeUndefined()
  })

  it('stays available in the ready, failed and processing phases', () => {
    for (const [phase, receipt] of [
      ['ready', readyReceipt()],
      ['failed', failedReceipt()],
      ['processing', null],
    ] as const) {
      const { wrapper } = render({ phase, receipt })
      expect(fileInput(wrapper).exists()).toBe(true)
    }
  })
})

describe('ReceiptPanel: busy and load failure', () => {
  it.each(['requesting', 'uploading'] as const)(
    'phase %s: the input is aria-disabled (not natively), and a change does not call choose',
    async (phase) => {
      const { wrapper, actions } = render({ phase })
      const input = fileInput(wrapper)
      pickFiles(input.element as HTMLInputElement, [pngFile()])

      await input.trigger('change')

      expect(input.attributes('aria-disabled')).toBe('true')
      expect(input.attributes('disabled')).toBeUndefined()
      expect(actions.choose).not.toHaveBeenCalled()
    },
  )

  it.each(['requesting', 'uploading'] as const)(
    'phase %s: a file picked on the busy input is answered with a fixed alert, and progress and live region stay untouched',
    async (phase) => {
      const { wrapper, set, actions } = render({
        phase,
        progress: 45,
        announcement: 'Uploading receipt',
      })
      const input = fileInput(wrapper)
      const region = wrapper.get('[aria-live="polite"]').element
      const observer = new MutationObserver(() => {})
      observer.observe(region, {
        subtree: true,
        childList: true,
        characterData: true,
        attributes: true,
      })

      pickFiles(input.element as HTMLInputElement, [pngFile()])
      await input.trigger('change')
      await flushPromises()
      const first = wrapper.get('[role="alert"]').element
      pickFiles(input.element as HTMLInputElement, [pngFile()])
      await input.trigger('change')
      await flushPromises()
      const second = wrapper.get('[role="alert"]').element

      expect(actions.choose).not.toHaveBeenCalled()
      expect(second.textContent?.trim()).toBe(UPLOAD_RUNNING_TEXT)
      expect(second).not.toBe(first)
      expect(wrapper.findAll('[role="alert"]')).toHaveLength(1)
      expect(input.attributes('aria-describedby')).toContain(second.id)
      expect(input.attributes('aria-disabled')).toBe('true')
      expect(wrapper.text()).toContain('Uploading… 45%')
      expect(observer.takeRecords()).toHaveLength(0)
      observer.disconnect()
      expect(region.textContent).toBe('Uploading receipt')

      set.phase('none')
      await flushPromises()
      expect(wrapper.find('[role="alert"]').exists()).toBe(false)
      expect(input.attributes('aria-describedby')).not.toContain(second.id)
    },
  )

  it('is not aria-disabled when idle', () => {
    const { wrapper } = render({ phase: 'none' })

    expect(fileInput(wrapper).attributes('aria-disabled')).toBeUndefined()
  })

  // jsdom cannot open a picker; the picker opens from the default action of the click (or of Enter and Space).
  function press(target: Element, event: Event): boolean {
    target.dispatchEvent(event)
    return event.defaultPrevented
  }
  const click = () => new MouseEvent('click', { bubbles: true, cancelable: true })
  const key = (value: string) =>
    new KeyboardEvent('keydown', { key: value, bubbles: true, cancelable: true })

  it.each(['requesting', 'uploading'] as const)(
    'phase %s: a click and Enter or Space cannot open the picker, and aria-disabled stays',
    (phase) => {
      const { wrapper } = render({ phase })
      const input = fileInput(wrapper)

      expect(press(input.element, click())).toBe(true)
      expect(press(input.element, key('Enter'))).toBe(true)
      expect(press(input.element, key(' '))).toBe(true)
      expect(input.attributes('aria-disabled')).toBe('true')
    },
  )

  it.each(['requesting', 'uploading'] as const)(
    'phase %s: other keys (Tab, arrows) are left alone',
    (phase) => {
      const { wrapper } = render({ phase })

      for (const other of ['Tab', 'ArrowDown', 'a']) {
        expect(press(fileInput(wrapper).element, key(other))).toBe(false)
      }
    },
  )

  it.each(['none', 'ready', 'processing'] as const)(
    'phase %s: a click and Enter or Space open the picker as usual',
    (phase) => {
      const { wrapper } = render({ phase })
      const input = fileInput(wrapper)

      expect(press(input.element, click())).toBe(false)
      expect(press(input.element, key('Enter'))).toBe(false)
      expect(press(input.element, key(' '))).toBe(false)
    },
  )

  it('a failed first read shows the load text and "Try again", and no file input', async () => {
    const { wrapper, actions } = render({ phase: 'none', loadFailed: true })
    actions.start.mockClear()

    expect(wrapper.text()).toContain(LOAD_FIRST_TEXT)
    expect(fileInput(wrapper).exists()).toBe(false)
    await buttonNamed(wrapper, 'Try again')?.trigger('click')
    expect(actions.start).toHaveBeenCalledOnce()
  })

  it('a failed first read with its error text shows that alert and "Try again", not the load text too', () => {
    const { wrapper } = render({
      phase: 'none',
      loadFailed: true,
      message: 'Something went wrong on our side. Try again.',
    })

    expect(wrapper.get('[role="alert"]').text()).toBe(
      'Something went wrong on our side. Try again.',
    )
    expect(wrapper.text()).not.toContain(LOAD_FIRST_TEXT)
    expect(buttonNamed(wrapper, 'Try again')).toBeDefined()
  })

  it('offers no "Try again" when the first read worked', () => {
    const { wrapper } = render({ phase: 'none' })

    expect(buttonNamed(wrapper, 'Try again')).toBeUndefined()
  })
})

describe('ReceiptPanel: progress', () => {
  it.each(['requesting', 'uploading'] as const)(
    'phase %s: a progress element with max 100, and the percent as text',
    (phase) => {
      const { wrapper } = render({ phase, progress: 45 })

      const bar = wrapper.get('progress')
      expect(bar.attributes('max')).toBe('100')
      expect(bar.attributes('value')).toBe('45')
      expect(wrapper.text()).toContain('Uploading… 45%')
    },
  )

  it('follows the progress', async () => {
    const { wrapper, set } = render({ phase: 'uploading', progress: 10 })

    set.progress(80)
    await wrapper.vm.$nextTick()

    expect(wrapper.get('progress').attributes('value')).toBe('80')
    expect(wrapper.text()).toContain('Uploading… 80%')
  })

  it.each(['loading', 'none', 'processing', 'ready', 'failed'] as const)(
    'phase %s: no progress element',
    (phase) => {
      const { wrapper } = render({ phase, receipt: phase === 'ready' ? readyReceipt() : null })

      expect(wrapper.find('progress').exists()).toBe(false)
      expect(wrapper.text()).not.toContain('Uploading…')
    },
  )
})

describe('ReceiptPanel: processing', () => {
  it('shows "Processing…" and no Check again while polling is going', () => {
    const { wrapper } = render({ phase: 'processing' })

    expect(wrapper.text()).toContain('Processing…')
    expect(buttonNamed(wrapper, 'Check again')).toBeUndefined()
  })

  it('once stalled shows the message as a neutral status (not an alert) and "Check again", which calls checkAgain', async () => {
    const { wrapper, actions } = render({
      phase: 'processing',
      stalled: true,
      message: STILL_PROCESSING_MESSAGE,
    })

    const status = wrapper.get('[role="status"]')
    expect(status.text()).toBe(STILL_PROCESSING_MESSAGE)
    expect(status.classes()).not.toContain('form-alert')
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Processing…')
    await buttonNamed(wrapper, 'Check again')?.trigger('click')
    expect(actions.checkAgain).toHaveBeenCalledOnce()
  })

  it('a stalled poll with an error text keeps that text as an alert, next to "Check again"', () => {
    const { wrapper } = render({
      phase: 'processing',
      stalled: true,
      message: 'Something went wrong on our side. Try again.',
    })

    expect(wrapper.get('[role="alert"]').text()).toBe(
      'Something went wrong on our side. Try again.',
    )
    expect(wrapper.find('[role="status"]').exists()).toBe(false)
    expect(buttonNamed(wrapper, 'Check again')).toBeDefined()
  })

  it('shows no status while polling goes on, nor outside the processing phase', () => {
    expect(render({ phase: 'processing' }).wrapper.find('[role="status"]').exists()).toBe(false)
    expect(
      render({ phase: 'none', message: STILL_PROCESSING_MESSAGE })
        .wrapper.find('[role="status"]')
        .exists(),
    ).toBe(false)
  })
})

describe('ReceiptPanel: ready and failed', () => {
  it('ready: shows the preview with the receipt, whose refresh is freshView', async () => {
    const ready = readyReceipt()
    const { wrapper, actions } = render({ phase: 'ready', receipt: ready })

    const preview = wrapper.getComponent(ReceiptPreview)
    expect(preview.props('receipt')).toEqual(ready)
    await (preview.props('refresh') as () => Promise<unknown>)()
    expect(actions.freshView).toHaveBeenCalledOnce()
  })

  it('shows no preview in any other phase', () => {
    for (const phase of ['none', 'processing', 'failed'] as const) {
      const { wrapper } = render({ phase, receipt: phase === 'failed' ? failedReceipt() : null })
      expect(wrapper.findComponent(ReceiptPreview).exists()).toBe(false)
    }
  })

  it('failed: shows the failure text of the reason, the input and "Delete receipt"', () => {
    const { wrapper } = render({ phase: 'failed', receipt: failedReceipt() })

    expect(wrapper.text()).toContain('The file could not be read.')
    expect(fileInput(wrapper).exists()).toBe(true)
    expect(confirmButtonWithLabel(wrapper, 'Delete receipt').exists()).toBe(true)
  })

  it('failed: the failure text appears once, also when the composable put it in message', () => {
    const text = 'The file could not be read.'
    const { wrapper } = render({ phase: 'failed', receipt: failedReceipt(), message: text })

    expect(wrapper.text().split(text)).toHaveLength(2)
  })
})

describe('ReceiptPanel: alert', () => {
  it('shows the message in an alert', () => {
    const { wrapper } = render({ phase: 'none', message: 'Upload interrupted. Try again.' })

    expect(wrapper.get('[role="alert"]').text()).toBe('Upload interrupted. Try again.')
  })

  it('shows no alert without a message', () => {
    const { wrapper } = render({ phase: 'none' })

    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
  })

  it('renders a message that looks like markup as text', () => {
    const { wrapper } = render({ phase: 'none', message: '<b>no</b>' })

    expect(wrapper.find('b').exists()).toBe(false)
  })
})

describe('ReceiptPanel: replace', () => {
  it('shows nothing about replacing without a pending file', () => {
    const { wrapper } = render({ phase: 'ready', receipt: readyReceipt() })

    expect(
      wrapper
        .findAllComponents(ConfirmDeleteButton)
        .some((c) => c.props('label') === 'Replace receipt'),
    ).toBe(false)
    expect(buttonNamed(wrapper, 'Cancel')).toBeUndefined()
  })

  it('asks in place, with the pending file name as the subject', () => {
    const { wrapper } = render({
      phase: 'ready',
      receipt: readyReceipt(),
      pendingReplacement: pngFile(),
    })

    const replace = confirmButtonWithLabel(wrapper, 'Replace receipt')
    expect(replace.props('confirmLabel')).toBe('Confirm replace')
    expect(replace.props('question')).toBe('Replace the current receipt? It is deleted at once.')
    expect(replace.props('subject')).toBe('lunch.png')
  })

  it('confirming calls confirmReplace once', () => {
    const { wrapper, actions } = render({
      phase: 'ready',
      receipt: readyReceipt(),
      pendingReplacement: pngFile(),
    })

    confirmButtonWithLabel(wrapper, 'Replace receipt').vm.$emit('confirm')

    expect(actions.confirmReplace).toHaveBeenCalledOnce()
    expect(actions.cancelReplace).not.toHaveBeenCalled()
  })

  it('a separate Cancel button calls cancelReplace', async () => {
    const { wrapper, actions } = render({
      phase: 'ready',
      receipt: readyReceipt(),
      pendingReplacement: pngFile(),
    })

    await buttonNamed(wrapper, 'Cancel')?.trigger('click')

    expect(actions.cancelReplace).toHaveBeenCalledOnce()
    expect(actions.confirmReplace).not.toHaveBeenCalled()
  })
})

describe('ReceiptPanel: delete', () => {
  it.each([
    ['ready', readyReceipt()],
    ['failed', failedReceipt()],
    ['processing', null],
  ] as const)('phase %s: offers "Delete receipt" with its question', (phase, receipt) => {
    const { wrapper } = render({ phase, receipt })

    const remove = confirmButtonWithLabel(wrapper, 'Delete receipt')
    expect(remove.props('question')).toBe('Delete this receipt?')
  })

  it.each(['loading', 'none', 'requesting', 'uploading'] as const)(
    'phase %s: offers no delete',
    (phase) => {
      const { wrapper } = render({ phase })

      expect(
        wrapper
          .findAllComponents(ConfirmDeleteButton)
          .some((c) => c.props('label') === 'Delete receipt'),
      ).toBe(false)
    },
  )

  it('confirming calls remove', () => {
    const { wrapper, actions } = render({ phase: 'ready', receipt: readyReceipt() })

    confirmButtonWithLabel(wrapper, 'Delete receipt').vm.$emit('confirm')

    expect(actions.remove).toHaveBeenCalledOnce()
  })

  it('shows the error text that arrives through message', () => {
    const { wrapper } = render({ phase: 'none', message: 'Already removed.' })

    expect(wrapper.get('[role="alert"]').text()).toBe('Already removed.')
  })
})

describe('ReceiptPanel: retry', () => {
  it('shows "Retry" only when canRetry, and it calls retry', async () => {
    const { wrapper, actions, set } = render({ phase: 'none' })
    expect(buttonNamed(wrapper, 'Retry')).toBeUndefined()

    set.canRetry(true)
    await wrapper.vm.$nextTick()
    await buttonNamed(wrapper, 'Retry')?.trigger('click')

    expect(actions.retry).toHaveBeenCalledOnce()
  })
})
