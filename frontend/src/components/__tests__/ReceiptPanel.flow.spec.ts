import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { ApiResult } from '@/api/http'
import type { Receipt } from '@/api/types'

import {
  EXPENSE_ID,
  NOT_FOUND,
  fail,
  ok,
  pdfFile,
  pendingReceipt,
  pngFile,
  policy,
  readyReceipt,
  settle,
} from '@/composables/__tests__/receiptHarness'
import { ANNOUNCE_READY } from '@/lib/receiptState'
import ReceiptPanel from '../ReceiptPanel.vue'
import { pickFiles } from './receiptPanelHarness'

// End to end through the REAL useReceipt: only the two network modules are replaced, and time is faked.

const receiptsApi = vi.hoisted(() => ({
  getReceipt: vi.fn(),
  createReceiptUpload: vi.fn(),
  deleteReceipt: vi.fn(),
}))
const uploadApi = vi.hoisted(() => ({ uploadReceipt: vi.fn() }))
vi.mock('@/api/receipts', () => receiptsApi)
vi.mock('@/api/upload', () => uploadApi)

const NOW = new Date('2026-11-01T12:00:00Z')
const FRESH = new Date(NOW.getTime() + 300_000).toISOString()
const THUMB = 'https://thumbs.example/t.jpg?X-Amz-Signature=abc'

const mounts: ReturnType<typeof mount>[] = []
beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(NOW)
  Object.values(receiptsApi).forEach((mock) => mock.mockReset())
  uploadApi.uploadReceipt.mockReset()
  receiptsApi.createReceiptUpload.mockResolvedValue(policy())
})
afterEach(() => {
  mounts.splice(0).forEach((wrapper) => wrapper.unmount())
  document.body.replaceChildren()
  vi.useRealTimers()
})

async function tick(ms = 0): Promise<void> {
  await vi.advanceTimersByTimeAsync(ms)
  await settle()
  await nextTick()
}
async function open(first: ApiResult<Receipt>) {
  receiptsApi.getReceipt.mockResolvedValueOnce(first)
  const wrapper = mount(ReceiptPanel, { props: { expenseId: EXPENSE_ID }, attachTo: document.body })
  mounts.push(wrapper)
  await tick()
  return wrapper
}
async function pick(wrapper: ReturnType<typeof mount>, file: File): Promise<void> {
  const input = wrapper.get('input[type="file"]')
  pickFiles(input.element as HTMLInputElement, [file])
  await input.trigger('change')
  await tick()
}
function button(wrapper: ReturnType<typeof mount>, text: string) {
  const found = wrapper.findAll('button').find((b) => b.text() === text)
  if (!found) throw new Error(`no button "${text}"`)
  return found
}
const liveText = (wrapper: ReturnType<typeof mount>) => wrapper.get('[aria-live="polite"]').text()
const fileInput = (wrapper: ReturnType<typeof mount>) => wrapper.get('input[type="file"]').element
// What a click does in a browser, which a synthetic click does not: the pressed button takes focus.
function focusOn(target: { element: Element }): void {
  ;(target.element as HTMLElement).focus()
  expect(document.activeElement).toBe(target.element)
}

describe('ReceiptPanel flow: first upload', () => {
  it('pick a file, watch the progress, then the ready thumbnail', async () => {
    const wrapper = await open(fail(NOT_FOUND))
    let report: (percent: number) => void = () => {}
    let finish: (result: { ok: true }) => void = () => {}
    uploadApi.uploadReceipt.mockImplementation(
      (_target, _file, options: { onProgress: (percent: number) => void }) => {
        report = options.onProgress
        return new Promise((resolve) => (finish = resolve))
      },
    )

    await pick(wrapper, pngFile())
    report(45)
    await tick()

    expect(wrapper.get('progress').attributes('value')).toBe('45')
    expect(wrapper.text()).toContain('Uploading… 45%')
    expect(liveText(wrapper)).toBe('Uploading receipt')
    expect(wrapper.get('input[type="file"]').attributes('aria-disabled')).toBe('true')

    receiptsApi.getReceipt
      .mockResolvedValueOnce(ok(pendingReceipt()))
      .mockResolvedValueOnce(
        ok(readyReceipt({ thumbnailUrl: THUMB, urlsExpireAt: FRESH, size: 4 })),
      )
    finish({ ok: true })
    await tick()
    expect(wrapper.text()).toContain('Processing…')
    expect(liveText(wrapper)).toBe('Receipt uploaded, processing')

    await tick(5_000)
    await tick(5_000)

    expect(wrapper.get('img').attributes('src')).toBe(THUMB)
    expect(wrapper.get('img').attributes('alt')).toBe('Receipt thumbnail')
    expect(liveText(wrapper)).toBe(ANNOUNCE_READY)
    expect(wrapper.find('progress').exists()).toBe(false)
    expect(wrapper.get('a').text()).toBe('Open receipt')
  })
})

describe('ReceiptPanel flow: replace', () => {
  async function readyPanel() {
    return open(ok(readyReceipt({ thumbnailUrl: THUMB, urlsExpireAt: FRESH })))
  }

  it('asks first, sends nothing, then replaces after the confirmation and shows the new PDF', async () => {
    const wrapper = await readyPanel()
    uploadApi.uploadReceipt.mockResolvedValue({ ok: true })

    await pick(wrapper, pdfFile())

    expect(receiptsApi.createReceiptUpload).not.toHaveBeenCalled()
    receiptsApi.getReceipt.mockResolvedValue(
      ok(
        readyReceipt({
          contentType: 'application/pdf',
          size: 8,
          thumbnailUrl: null,
          urlsExpireAt: FRESH,
        }),
      ),
    )
    await button(wrapper, 'Replace receipt').trigger('click')
    await tick()
    await button(wrapper, 'Confirm replace').trigger('click')
    await tick()
    await tick(5_000)

    expect(receiptsApi.createReceiptUpload).toHaveBeenCalledOnce()
    expect(receiptsApi.createReceiptUpload.mock.calls[0]?.[1]).toEqual({
      contentType: 'application/pdf',
      size: 8,
    })
    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.text()).toContain('PDF, 8 B')
  })

  it('Cancel keeps the current receipt and sends nothing', async () => {
    const wrapper = await readyPanel()

    await pick(wrapper, pdfFile())
    focusOn(button(wrapper, 'Cancel'))
    await button(wrapper, 'Cancel').trigger('click')
    await tick()

    expect(receiptsApi.createReceiptUpload).not.toHaveBeenCalled()
    expect(uploadApi.uploadReceipt).not.toHaveBeenCalled()
    expect(wrapper.get('img').attributes('src')).toBe(THUMB)
    expect(wrapper.find('button[aria-label^="Replace receipt"]').exists()).toBe(false)
    expect(document.activeElement).toBe(fileInput(wrapper))
  })

  it('Confirm replace puts focus on the file input, which stays while the upload runs', async () => {
    const wrapper = await readyPanel()
    uploadApi.uploadReceipt.mockReturnValue(new Promise(() => {}))

    await pick(wrapper, pdfFile())
    await button(wrapper, 'Replace receipt').trigger('click')
    await tick()
    focusOn(button(wrapper, 'Confirm replace'))
    await button(wrapper, 'Confirm replace').trigger('click')
    await tick()

    expect(wrapper.find('progress').exists()).toBe(true)
    expect(document.activeElement).toBe(fileInput(wrapper))
  })
})

describe('ReceiptPanel flow: delete', () => {
  it('after a successful Delete focus lands on the file input', async () => {
    const wrapper = await open(ok(readyReceipt({ thumbnailUrl: THUMB, urlsExpireAt: FRESH })))
    receiptsApi.deleteReceipt.mockResolvedValue(ok(undefined, 204))

    await button(wrapper, 'Delete receipt').trigger('click')
    await tick()
    focusOn(button(wrapper, 'Confirm delete'))
    await button(wrapper, 'Confirm delete').trigger('click')
    await tick()

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.findAll('button')).toHaveLength(0)
    expect(document.activeElement).toBe(fileInput(wrapper))
  })

  it('a refused Delete shows the error in the focused alert and keeps the Delete button', async () => {
    const wrapper = await open(ok(readyReceipt({ thumbnailUrl: THUMB, urlsExpireAt: FRESH })))
    receiptsApi.deleteReceipt.mockResolvedValue(fail({ kind: 'server', status: 500 }))

    await button(wrapper, 'Delete receipt').trigger('click')
    await tick()
    await button(wrapper, 'Confirm delete').trigger('click')
    await tick()

    const alert = wrapper.get('[role="alert"]')
    expect(alert.text()).not.toBe('')
    expect(document.activeElement).toBe(alert.element)
    expect(button(wrapper, 'Delete receipt').exists()).toBe(true)
  })
})

describe('ReceiptPanel flow: polling that gives up', () => {
  async function stalledPanel() {
    receiptsApi.getReceipt.mockResolvedValue(ok(pendingReceipt()))
    const wrapper = await open(ok(pendingReceipt()))
    for (let turn = 0; turn < 30; turn += 1) {
      await tick(5_000)
    }
    return wrapper
  }

  it('shows "Still processing" as a neutral status and does not take focus from the form', async () => {
    const field = document.createElement('input')
    document.body.append(field)
    field.focus()

    const wrapper = await stalledPanel()

    expect(wrapper.get('[role="status"]').text()).toBe('Still processing. Check again in a moment.')
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(button(wrapper, 'Check again').exists()).toBe(true)
    expect(document.activeElement).toBe(field)
  })

  it('Check again puts focus on the file input', async () => {
    const wrapper = await stalledPanel()
    receiptsApi.getReceipt.mockResolvedValue(ok(pendingReceipt()))

    focusOn(button(wrapper, 'Check again'))
    await button(wrapper, 'Check again').trigger('click')
    await tick()

    expect(wrapper.findAll('button').some((b) => b.text() === 'Check again')).toBe(false)
    expect(document.activeElement).toBe(fileInput(wrapper))
  })

  it('a poll error stays an alert but does not take focus', async () => {
    const field = document.createElement('input')
    document.body.append(field)
    const wrapper = await open(ok(pendingReceipt()))
    field.focus()
    receiptsApi.getReceipt.mockResolvedValue(fail({ kind: 'server', status: 500 }))

    await tick(1_000)
    await tick(1_000)

    expect(wrapper.get('[role="alert"]').text()).not.toBe('')
    expect(document.activeElement).toBe(field)
  })
})

describe('ReceiptPanel flow: the first read failed', () => {
  it('shows one explanation with "Try again", which puts focus on the heading while it loads', async () => {
    const wrapper = await open(fail({ kind: 'server', status: 500 }))
    expect(wrapper.findAll('[role="alert"]')).toHaveLength(1)
    expect(wrapper.text()).not.toContain('Could not check the current receipt')
    receiptsApi.getReceipt.mockReturnValueOnce(new Promise(() => {}))

    focusOn(button(wrapper, 'Try again'))
    await button(wrapper, 'Try again').trigger('click')
    await tick()

    expect(wrapper.find('input[type="file"]').exists()).toBe(false)
    expect(document.activeElement).toBe(wrapper.get('h2').element)
  })

  it('a second failure puts focus on the alert', async () => {
    const wrapper = await open(fail({ kind: 'server', status: 500 }))
    receiptsApi.getReceipt.mockResolvedValueOnce(fail({ kind: 'server', status: 500 }))

    focusOn(button(wrapper, 'Try again'))
    await button(wrapper, 'Try again').trigger('click')
    await tick()

    expect(document.activeElement).toBe(wrapper.get('[role="alert"]').element)
  })
})

describe('ReceiptPanel flow: pre-check messages', () => {
  it.each([
    ['a wrong type', () => new File(['abcd'], 'notes.txt', { type: 'text/plain' })],
    [
      'a file that is too big',
      () => {
        const big = new File(['abcd'], 'big.png', { type: 'image/png' })
        Object.defineProperty(big, 'size', { value: 10_485_761 })
        return big
      },
    ],
  ])('%s: announced as an alert, the input is invalid, nothing is sent', async (_name, make) => {
    const wrapper = await open(fail(NOT_FOUND))
    uploadApi.uploadReceipt.mockReturnValue(new Promise(() => {}))

    await pick(wrapper, make())

    const input = wrapper.get('input[type="file"]')
    const alert = wrapper.get('.file-message')
    expect(alert.attributes('role')).toBe('alert')
    expect(alert.text()).toBe('Choose a JPEG, PNG, WebP or PDF file up to 10 MB.')
    expect(input.attributes('aria-invalid')).toBe('true')
    expect(input.attributes('aria-describedby')).toContain(alert.attributes('id'))
    expect(receiptsApi.createReceiptUpload).not.toHaveBeenCalled()

    await pick(wrapper, pngFile())
    expect(wrapper.find('.file-message').exists()).toBe(false)
    expect(input.attributes('aria-invalid')).toBeUndefined()
  })
})

describe('ReceiptPanel flow: the same refused file twice', () => {
  it('announces the alert again: the second pick puts a new alert element in the page', async () => {
    const wrapper = await open(fail(NOT_FOUND))
    const wrong = () => new File(['abcd'], 'notes.txt', { type: 'text/plain' })

    await pick(wrapper, wrong())
    const first = wrapper.get('.file-message').element
    await pick(wrapper, wrong())
    const second = wrapper.get('.file-message').element

    expect(second).not.toBe(first)
    expect(first.isConnected).toBe(false)
    expect(second.getAttribute('role')).toBe('alert')
    expect(second.textContent?.trim()).toBe('Choose a JPEG, PNG, WebP or PDF file up to 10 MB.')
    expect(wrapper.findAll('.file-message')).toHaveLength(1)
  })
})

describe('ReceiptPanel flow: upload rejected by the bucket (network failure)', () => {
  it('goes back to idle with the interrupted text, and the alert holds the focus', async () => {
    const wrapper = await open(fail(NOT_FOUND))
    let reject: (result: { ok: false; error: { kind: 'interrupted' } }) => void = () => {}
    uploadApi.uploadReceipt.mockReturnValue(new Promise((resolve) => (reject = resolve)))

    await pick(wrapper, pngFile())
    expect(wrapper.find('progress').exists()).toBe(true)
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)

    reject({ ok: false, error: { kind: 'interrupted' } })
    await tick()

    // upload() sets the phase back to none and the text in one step: no render with the progress and no text.
    const alert = wrapper.get('[role="alert"]')
    expect(alert.text()).toBe('Upload interrupted. Try again.')
    expect(wrapper.find('progress').exists()).toBe(false)
    expect(wrapper.get('input[type="file"]').attributes('aria-disabled')).toBeUndefined()
    expect(document.activeElement).toBe(alert.element)
    expect(button(wrapper, 'Retry').exists()).toBe(true)
  })
})

describe('ReceiptPanel flow: upload refused by the bucket', () => {
  it('shows the expired text in the focused alert, and Retry asks for a new policy', async () => {
    const wrapper = await open(fail(NOT_FOUND))
    uploadApi.uploadReceipt.mockResolvedValueOnce({ ok: false, error: { kind: 'expired' } })

    await pick(wrapper, pngFile())

    const alert = wrapper.get('[role="alert"]')
    expect(alert.text()).toBe('The upload link expired. Try again.')
    expect(document.activeElement).toBe(alert.element)
    expect(wrapper.find('progress').exists()).toBe(false)

    uploadApi.uploadReceipt.mockResolvedValueOnce({ ok: true })
    receiptsApi.getReceipt.mockResolvedValue(ok(pendingReceipt()))
    focusOn(button(wrapper, 'Retry'))
    await button(wrapper, 'Retry').trigger('click')
    await tick()

    // The Retry button and the alert are gone; focus is on the file input, not lost at the top of the page.
    expect(document.activeElement).toBe(fileInput(wrapper))
    expect(receiptsApi.createReceiptUpload).toHaveBeenCalledTimes(2)
    expect(uploadApi.uploadReceipt).toHaveBeenCalledTimes(2)
    expect(uploadApi.uploadReceipt.mock.calls[1]?.[1]).toBe(
      uploadApi.uploadReceipt.mock.calls[0]?.[1],
    )
    expect(wrapper.text()).toContain('Processing…')
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(wrapper.findAll('button').some((b) => b.text() === 'Retry')).toBe(false)
  })
})
