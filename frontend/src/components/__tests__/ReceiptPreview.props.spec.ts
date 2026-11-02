import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { readyReceipt } from '@/composables/__tests__/receiptHarness'
import ReceiptPreview from '../ReceiptPreview.vue'

// A refreshed address belongs to the receipt value it was fetched for.

const OLD_VIEW = 'https://bucket.example/view?X-Amz-Signature=old'
const REFRESHED_VIEW = 'https://bucket.example/view?X-Amz-Signature=refreshed'
const OTHER_VIEW = 'https://bucket.example/other?X-Amz-Signature=other'
const SOON = new Date(Date.now() + 1_000).toISOString()

// The programmatic click is real (it reaches the document); this listener only stops jsdom from navigating.
const followed: Array<{ href: string | null; prevented: boolean }> = []
function recordAndCancel(event: Event): void {
  if (event.target instanceof HTMLAnchorElement) {
    followed.push({ href: event.target.getAttribute('href'), prevented: event.defaultPrevented })
  }
  event.preventDefault()
}

beforeEach(() => {
  followed.length = 0
  vi.stubGlobal('location', { origin: 'https://app.example' })
  document.addEventListener('click', recordAndCancel)
})
afterEach(() => {
  document.removeEventListener('click', recordAndCancel)
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
  document.body.replaceChildren()
})

describe('ReceiptPreview: a new receipt value', () => {
  it('drops a refreshed address and follows the address of the new value', async () => {
    const clickSpy = vi.spyOn(HTMLAnchorElement.prototype, 'click')
    const current = readyReceipt({ viewUrl: OLD_VIEW, urlsExpireAt: SOON })
    const wrapper = mount(ReceiptPreview, {
      props: {
        receipt: current,
        refresh: () => Promise.resolve(readyReceipt({ viewUrl: REFRESHED_VIEW })),
      },
      attachTo: document.body,
    })
    await wrapper.get('a').trigger('click')
    await vi.waitFor(() => expect(wrapper.get('a').attributes('href')).toBe(REFRESHED_VIEW))
    await vi.waitFor(() => expect(clickSpy).toHaveBeenCalledOnce())
    expect(followed).toEqual([
      { href: OLD_VIEW, prevented: true },
      { href: REFRESHED_VIEW, prevented: false },
    ])

    await wrapper.setProps({ receipt: readyReceipt({ viewUrl: OTHER_VIEW, urlsExpireAt: null }) })

    expect(wrapper.get('a').attributes('href')).toBe(OTHER_VIEW)
    wrapper.unmount()
  })

  it('keeps the refreshed address when the page then shows the fresh receipt itself', async () => {
    vi.spyOn(HTMLAnchorElement.prototype, 'click')
    const fresh = readyReceipt({ viewUrl: REFRESHED_VIEW, urlsExpireAt: null })
    const wrapper = mount(ReceiptPreview, {
      props: {
        receipt: readyReceipt({ viewUrl: OLD_VIEW, urlsExpireAt: SOON }),
        refresh: () => Promise.resolve(fresh),
      },
      attachTo: document.body,
    })
    await wrapper.get('a').trigger('click')
    await vi.waitFor(() => expect(followed).toHaveLength(2))

    await wrapper.setProps({ receipt: fresh })

    expect(wrapper.get('a').attributes('href')).toBe(REFRESHED_VIEW)
    expect(followed).toHaveLength(2)
    wrapper.unmount()
  })
})
