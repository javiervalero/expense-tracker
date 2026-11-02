import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { Receipt } from '@/api/types'
import { receipt, readyReceipt } from '@/composables/__tests__/receiptHarness'
import { URL_REFRESH_MARGIN_MS } from '@/config'
import PdfIcon from '../PdfIcon.vue'
import ReceiptPreview from '../ReceiptPreview.vue'

// The preview of a ready receipt (plan C19) and its security rules: only addresses that pass the shape check reach
// `src` or `href`, never `target`, and the click refreshes an address that is about to expire.

const SCRIPT_SCHEME = 'java' + 'script:'
const APP_ORIGIN = 'https://app.example'
const THUMB = 'https://thumbs.example/t.jpg?X-Amz-Signature=abc'
const VIEW = 'https://bucket.example/view?X-Amz-Signature=old'
const NEW_VIEW = 'https://bucket.example/view?X-Amz-Signature=new'
const NOW = new Date('2026-11-01T12:00:00Z')
const expiresIn = (ms: number) => new Date(NOW.getTime() + ms).toISOString()

const HOSTILE: readonly string[] = [
  `${SCRIPT_SCHEME}alert(1)`,
  'data:text/html,x',
  'blob:https://x/y',
  'http://evil.example/x.jpg',
  'https://user:pw@x.example/a',
  'https://x.example/a#frag',
  'http://localhost:9001/x',
  `${APP_ORIGIN}/api/v1/x`,
  '/api/v1/x',
  '',
  ' https://x.example/a.jpg',
  'https://x.example/a.jpg ',
  '\thttps://x.example/a.jpg\n',
]

const image = (overrides: Partial<Receipt> = {}) =>
  readyReceipt({ thumbnailUrl: THUMB, viewUrl: VIEW, urlsExpireAt: null, ...overrides })
const pdf = (overrides: Partial<Receipt> = {}) =>
  readyReceipt({
    contentType: 'application/pdf',
    size: 1_258_291,
    thumbnailUrl: null,
    viewUrl: VIEW,
    urlsExpireAt: null,
    ...overrides,
  })

const mounts: ReturnType<typeof mount>[] = []
afterEach(() => {
  mounts.splice(0).forEach((wrapper) => wrapper.unmount())
  document.body.replaceChildren()
  vi.useRealTimers()
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})
beforeEach(() => {
  vi.stubGlobal('location', { origin: APP_ORIGIN })
})

function render(
  value: Receipt,
  refresh: () => Promise<Receipt | null> = () => Promise.resolve(value),
) {
  const wrapper = mount(ReceiptPreview, {
    props: { receipt: value, refresh },
    attachTo: document.body,
  })
  mounts.push(wrapper)
  return wrapper
}
const html = (wrapper: ReturnType<typeof mount>) => wrapper.element.outerHTML
// Nothing of a hostile value may be in the markup. An empty value has nothing to look for (NUL never occurs).
const expectNoTrace = (wrapper: ReturnType<typeof mount>, hostile: string) =>
  expect(html(wrapper)).not.toContain(hostile.trim() || '\u0000')

describe('ReceiptPreview: an image receipt', () => {
  it('shows the thumbnail with fixed size, lazy loading and no referrer', () => {
    const wrapper = render(image())

    const img = wrapper.get('img')
    expect(img.attributes('src')).toBe(THUMB)
    expect(img.attributes('alt')).toBe('Receipt thumbnail')
    expect(img.attributes('width')).toBe('480')
    expect(img.attributes('height')).toBe('480')
    expect(img.attributes('loading')).toBe('lazy')
    expect(img.attributes('referrerpolicy')).toBe('no-referrer')
    expect(img.attributes('crossorigin')).toBeUndefined()
    expect(wrapper.findAll('img')).toHaveLength(1)
    expect(wrapper.findComponent(PdfIcon).exists()).toBe(false)
  })

  it('shows no image when the thumbnail address is null', () => {
    const wrapper = render(image({ thumbnailUrl: null }))

    expect(wrapper.find('img').exists()).toBe(false)
  })
})

describe('ReceiptPreview: a PDF receipt', () => {
  it('shows the icon and "PDF, 1.2 MB", and no image', () => {
    const wrapper = render(pdf())

    expect(wrapper.findComponent(PdfIcon).exists()).toBe(true)
    expect(wrapper.text()).toContain('PDF, 1.2 MB')
    expect(wrapper.find('img').exists()).toBe(false)
  })
})

describe('PdfIcon', () => {
  it('is an inline svg hidden from assistive technology, and not focusable', () => {
    const svg = mount(PdfIcon).element as SVGElement

    expect(svg.tagName.toLowerCase()).toBe('svg')
    expect(svg.getAttribute('aria-hidden')).toBe('true')
    expect(svg.getAttribute('focusable')).toBe('false')
  })

  it('loads nothing: no image, external use, script or foreignObject', () => {
    const wrapper = mount(PdfIcon)
    const source = wrapper.html().toLowerCase()

    expect(source).not.toContain('<image')
    expect(source).not.toContain('<script')
    expect(source).not.toContain('<foreignobject')
    expect(source).not.toContain('xlink:href')
    const root = wrapper.element as Element
    for (const use of Array.from(root.querySelectorAll<SVGUseElement>('use'))) {
      expect(use.getAttribute('href') ?? '#').toMatch(/^#/)
    }
    expect(wrapper.element.querySelector('[href], [src], [style*="url("]')).toBeNull()
  })
})

describe('ReceiptPreview: hostile thumbnail addresses', () => {
  it.each(HOSTILE)('renders no image for %j', (hostile) => {
    const wrapper = render(image({ thumbnailUrl: hostile }))

    expect(wrapper.find('img').exists()).toBe(false)
    expectNoTrace(wrapper, hostile)
  })

  it('still shows the PDF text for a PDF whose thumbnail address is hostile', () => {
    const wrapper = render(pdf({ thumbnailUrl: 'http://evil.example/x.jpg' }))

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.text()).toContain('PDF, 1.2 MB')
  })
})

describe('ReceiptPreview: hostile view addresses', () => {
  it.each(HOSTILE)('renders no link for %j and says the receipt cannot be opened', (hostile) => {
    const wrapper = render(image({ viewUrl: hostile }))

    expect(wrapper.find('a').exists()).toBe(false)
    expect(wrapper.text()).toContain('Receipt cannot be opened.')
    expectNoTrace(wrapper, hostile)
  })

  it('a null view address renders no link either', () => {
    const wrapper = render(image({ viewUrl: null }))

    expect(wrapper.find('a').exists()).toBe(false)
    expect(wrapper.text()).toContain('Receipt cannot be opened.')
  })

  it('keeps the thumbnail when only the view address is hostile', () => {
    const wrapper = render(image({ viewUrl: `${SCRIPT_SCHEME}alert(1)` }))

    expect(wrapper.get('img').attributes('src')).toBe(THUMB)
  })

  it('shows no "cannot be opened" text when the link works', () => {
    const wrapper = render(image())

    expect(wrapper.text()).not.toContain('cannot be opened')
  })
})

describe('ReceiptPreview: the Open receipt link', () => {
  it('is a plain same-tab link with noreferrer and no target', () => {
    const wrapper = render(pdf())

    const link = wrapper.get('a')
    expect(link.text()).toBe('Open receipt')
    expect(link.attributes('href')).toBe(VIEW)
    expect((link.attributes('rel') ?? '').split(/\s+/)).toContain('noreferrer')
    expect(link.element.hasAttribute('target')).toBe(false)
    expect(wrapper.findAll('a')).toHaveLength(1)
  })
})

describe('ReceiptPreview: clicking when the address is fresh', () => {
  type Seen = { href: string | null; prevented: boolean }
  let seen: Seen[] = []
  // Records every click that reaches the document, then cancels it so that jsdom never tries to navigate.
  function onClick(event: Event): void {
    if (event.target instanceof HTMLAnchorElement) {
      seen.push({ href: event.target.getAttribute('href'), prevented: event.defaultPrevented })
    }
    event.preventDefault()
  }
  beforeEach(() => {
    seen = []
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(NOW)
    document.addEventListener('click', onClick)
  })
  afterEach(() => {
    document.removeEventListener('click', onClick)
  })
  function click(wrapper: ReturnType<typeof mount>): void {
    wrapper
      .get('a')
      .element.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))
  }
  const later = () => new Promise<void>((resolve) => setTimeout(resolve, 0))

  it.each([
    ['null', null],
    ['an hour away', expiresIn(3_600_000)],
    ['just over the margin', expiresIn(URL_REFRESH_MARGIN_MS + 1)],
  ])('does not intercept, nor refresh, when the expiry is %s', async (_name, expiresAt) => {
    const refresh = vi.fn(() => Promise.resolve(null))
    const wrapper = render(image({ urlsExpireAt: expiresAt }), refresh)

    click(wrapper)
    await later()

    expect(seen).toEqual([{ href: VIEW, prevented: false }])
    expect(refresh).not.toHaveBeenCalled()
  })
})

describe('ReceiptPreview: clicking when the address is about to expire', () => {
  type Seen = { href: string | null; prevented: boolean }
  let seen: Seen[] = []
  function onClick(event: Event): void {
    if (event.target instanceof HTMLAnchorElement) {
      seen.push({ href: event.target.getAttribute('href'), prevented: event.defaultPrevented })
    }
    event.preventDefault()
  }
  beforeEach(() => {
    seen = []
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(NOW)
    document.addEventListener('click', onClick)
  })
  afterEach(() => {
    document.removeEventListener('click', onClick)
  })
  function click(wrapper: ReturnType<typeof mount>): void {
    wrapper
      .get('a')
      .element.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))
  }
  const settle = async () => {
    for (let turn = 0; turn < 20; turn += 1) await Promise.resolve()
  }

  const NEAR: ReadonlyArray<readonly [string, string]> = [
    ['exactly the margin', expiresIn(URL_REFRESH_MARGIN_MS)],
    ['inside the margin', expiresIn(1_000)],
    ['already past', expiresIn(-5_000)],
    ['unparsable', 'not-a-date'],
  ]

  it.each(NEAR)(
    'when the expiry is %s: prevents the click, refreshes once, then navigates once to the new address',
    async (_name, expiresAt) => {
      const clickSpy = vi.spyOn(HTMLAnchorElement.prototype, 'click')
      const refresh = vi.fn(() => Promise.resolve(image({ viewUrl: NEW_VIEW, urlsExpireAt: null })))
      const wrapper = render(image({ urlsExpireAt: expiresAt }), refresh)

      click(wrapper)
      await settle()

      expect(refresh).toHaveBeenCalledOnce()
      expect(clickSpy).toHaveBeenCalledOnce()
      expect(seen).toEqual([
        { href: VIEW, prevented: true },
        { href: NEW_VIEW, prevented: false },
      ])
    },
  )

  it('navigates exactly once even when the refreshed address is itself near expiry (no loop)', async () => {
    const clickSpy = vi.spyOn(HTMLAnchorElement.prototype, 'click')
    const refresh = vi.fn(() =>
      Promise.resolve(image({ viewUrl: NEW_VIEW, urlsExpireAt: expiresIn(1_000) })),
    )
    const wrapper = render(image({ urlsExpireAt: expiresIn(1_000) }), refresh)

    click(wrapper)
    await settle()

    expect(refresh).toHaveBeenCalledOnce()
    expect(clickSpy).toHaveBeenCalledOnce()
    expect(seen.filter((entry) => !entry.prevented)).toEqual([{ href: NEW_VIEW, prevented: false }])
  })

  it('a second click while the refresh is pending does not refresh or navigate twice', async () => {
    const clickSpy = vi.spyOn(HTMLAnchorElement.prototype, 'click')
    let release: (value: Receipt | null) => void = () => {}
    const refresh = vi.fn(() => new Promise<Receipt | null>((resolve) => (release = resolve)))
    const wrapper = render(image({ urlsExpireAt: expiresIn(1_000) }), refresh)

    click(wrapper)
    click(wrapper)
    release(image({ viewUrl: NEW_VIEW }))
    await settle()

    expect(refresh).toHaveBeenCalledOnce()
    expect(clickSpy).toHaveBeenCalledOnce()
    expect(seen.filter((entry) => !entry.prevented)).toHaveLength(1)
  })

  it('does not navigate, and keeps the old link, when the refresh gives null', async () => {
    const clickSpy = vi.spyOn(HTMLAnchorElement.prototype, 'click')
    const refresh = vi.fn(() => Promise.resolve(null))
    const wrapper = render(image({ urlsExpireAt: expiresIn(1_000) }), refresh)

    click(wrapper)
    await settle()

    expect(seen).toEqual([{ href: VIEW, prevented: true }])
    expect(clickSpy).not.toHaveBeenCalled()
    expect(wrapper.get('a').attributes('href')).toBe(VIEW)
  })

  it.each(HOSTILE)(
    'does not navigate, and keeps the old link, when the refresh gives %j',
    async (hostile) => {
      const clickSpy = vi.spyOn(HTMLAnchorElement.prototype, 'click')
      const refresh = vi.fn(() => Promise.resolve(image({ viewUrl: hostile })))
      const wrapper = render(image({ urlsExpireAt: expiresIn(1_000) }), refresh)

      click(wrapper)
      await settle()

      expect(clickSpy).not.toHaveBeenCalled()
      expect(seen).toEqual([{ href: VIEW, prevented: true }])
      expect(wrapper.get('a').attributes('href')).toBe(VIEW)
      expectNoTrace(wrapper, hostile)
    },
  )

  it('does not navigate when the refresh gives a receipt with no view address', async () => {
    const clickSpy = vi.spyOn(HTMLAnchorElement.prototype, 'click')
    const refresh = vi.fn(() => Promise.resolve(receipt({ status: 'ready', viewUrl: null })))
    const wrapper = render(image({ urlsExpireAt: expiresIn(1_000) }), refresh)

    click(wrapper)
    await settle()

    expect(clickSpy).not.toHaveBeenCalled()
    expect(wrapper.get('a').attributes('href')).toBe(VIEW)
  })

  it('does not navigate, and leaks no rejection, when the refresh throws', async () => {
    const clickSpy = vi.spyOn(HTMLAnchorElement.prototype, 'click')
    const refresh = vi.fn(() => Promise.reject(new Error('boom')))
    const wrapper = render(image({ urlsExpireAt: expiresIn(1_000) }), refresh)

    click(wrapper)
    await settle()

    expect(clickSpy).not.toHaveBeenCalled()
    expect(wrapper.get('a').attributes('href')).toBe(VIEW)
  })

  it('intercepts a second near-expiry click again after a refresh that navigated (the pass-through flag is reset)', async () => {
    const clickSpy = vi.spyOn(HTMLAnchorElement.prototype, 'click')
    const refresh = vi.fn(() => Promise.resolve(image({ viewUrl: NEW_VIEW, urlsExpireAt: null })))
    const wrapper = render(image({ urlsExpireAt: expiresIn(1_000) }), refresh)

    click(wrapper)
    await settle()
    click(wrapper)
    await settle()

    expect(refresh).toHaveBeenCalledTimes(2)
    expect(clickSpy).toHaveBeenCalledTimes(2)
    expect(seen).toEqual([
      { href: VIEW, prevented: true },
      { href: NEW_VIEW, prevented: false },
      { href: NEW_VIEW, prevented: true },
      { href: NEW_VIEW, prevented: false },
    ])
  })

  it('does not leave the pass-through flag set when the anchor is gone after the refresh', async () => {
    const clickSpy = vi.spyOn(HTMLAnchorElement.prototype, 'click')
    const fresh = image({ viewUrl: NEW_VIEW, urlsExpireAt: null })
    const page: { current?: ReturnType<typeof mount> } = {}
    const refresh = vi
      .fn<() => Promise<Receipt | null>>()
      .mockImplementationOnce(async () => {
        // The page now shows a receipt whose address failed the check: there is no anchor any more.
        await page.current?.setProps({ receipt: image({ viewUrl: 'http://evil.example/x' }) })
        return fresh
      })
      .mockResolvedValue(null)
    const wrapper = render(image({ urlsExpireAt: expiresIn(1_000) }), refresh)
    page.current = wrapper

    click(wrapper)
    await settle()
    expect(clickSpy).not.toHaveBeenCalled()
    expect(wrapper.find('a').exists()).toBe(false)

    await wrapper.setProps({ receipt: image({ urlsExpireAt: expiresIn(1_000) }) })
    click(wrapper)
    await settle()

    expect(refresh).toHaveBeenCalledTimes(2)
    expect(seen).toEqual([
      { href: VIEW, prevented: true },
      { href: VIEW, prevented: true },
    ])
  })

  it('follows the refreshed address when the page shows the fresh receipt before the answer is handled', async () => {
    const clickSpy = vi.spyOn(HTMLAnchorElement.prototype, 'click')
    const fresh = image({ viewUrl: NEW_VIEW, urlsExpireAt: null })
    const refresh = vi.fn(() => Promise.resolve(fresh))
    const wrapper = render(image({ urlsExpireAt: expiresIn(1_000) }), refresh)

    click(wrapper)
    void wrapper.setProps({ receipt: fresh })
    await settle()

    expect(refresh).toHaveBeenCalledOnce()
    expect(clickSpy).toHaveBeenCalledOnce()
    expect(wrapper.get('a').attributes('href')).toBe(NEW_VIEW)
    expect(seen).toEqual([
      { href: VIEW, prevented: true },
      { href: NEW_VIEW, prevented: false },
    ])
  })

  it('follows the refreshed address when the fresh receipt was put on the page during the refresh', async () => {
    const clickSpy = vi.spyOn(HTMLAnchorElement.prototype, 'click')
    const fresh = image({ viewUrl: NEW_VIEW, urlsExpireAt: null })
    const page: { current?: ReturnType<typeof mount> } = {}
    const refresh = vi.fn(async () => {
      await page.current?.setProps({ receipt: fresh })
      return fresh
    })
    const wrapper = render(image({ urlsExpireAt: expiresIn(1_000) }), refresh)
    page.current = wrapper

    click(wrapper)
    await settle()

    expect(clickSpy).toHaveBeenCalledOnce()
    expect(seen).toEqual([
      { href: VIEW, prevented: true },
      { href: NEW_VIEW, prevented: false },
    ])
  })

  it('never opens another receipt when the page shows one after the click (replaced during the refresh)', async () => {
    const clickSpy = vi.spyOn(HTMLAnchorElement.prototype, 'click')
    const other = image({ viewUrl: 'https://bucket.example/other?X-Amz-Signature=other' })
    const refresh = vi.fn(() => Promise.resolve(image({ viewUrl: NEW_VIEW, urlsExpireAt: null })))
    const wrapper = render(image({ urlsExpireAt: expiresIn(1_000) }), refresh)

    click(wrapper)
    void wrapper.setProps({ receipt: other })
    await settle()

    expect(clickSpy).not.toHaveBeenCalled()
    expect(wrapper.get('a').attributes('href')).toBe(other.viewUrl)
    expect(seen).toEqual([{ href: VIEW, prevented: true }])
  })

  it('does not follow the refreshed address of a receipt the page replaced before the answer came', async () => {
    const clickSpy = vi.spyOn(HTMLAnchorElement.prototype, 'click')
    const other = image({ viewUrl: 'https://bucket.example/other?X-Amz-Signature=other' })
    const page: { current?: ReturnType<typeof mount> } = {}
    const refresh = vi.fn(async () => {
      await page.current?.setProps({ receipt: other })
      return image({ viewUrl: NEW_VIEW, urlsExpireAt: null })
    })
    const wrapper = render(image({ urlsExpireAt: expiresIn(1_000) }), refresh)
    page.current = wrapper

    click(wrapper)
    await settle()

    expect(clickSpy).not.toHaveBeenCalled()
    expect(wrapper.get('a').attributes('href')).toBe(other.viewUrl)
    expect(seen).toEqual([{ href: VIEW, prevented: true }])
  })

  it('does not click an anchor that is no longer in the document when the refresh answers', async () => {
    const clickSpy = vi.spyOn(HTMLAnchorElement.prototype, 'click')
    const page: { current?: ReturnType<typeof mount> } = {}
    const refresh = vi.fn(async () => {
      // The anchor is taken out of the page behind Vue's back; the template ref still points at it.
      page.current?.get('a').element.remove()
      return image({ viewUrl: NEW_VIEW, urlsExpireAt: null })
    })
    const wrapper = render(image({ urlsExpireAt: expiresIn(1_000) }), refresh)
    page.current = wrapper
    const anchor = wrapper.get('a').element

    click(wrapper)
    await settle()

    expect(refresh).toHaveBeenCalledOnce()
    expect(anchor.isConnected).toBe(false)
    expect(anchor.getAttribute('href')).toBe(NEW_VIEW)
    expect(clickSpy).not.toHaveBeenCalled()
    expect(seen).toEqual([{ href: VIEW, prevented: true }])
  })

  it('can refresh again on a later click after a failed refresh', async () => {
    const clickSpy = vi.spyOn(HTMLAnchorElement.prototype, 'click')
    const refresh = vi
      .fn<() => Promise<Receipt | null>>()
      .mockResolvedValueOnce(null)
      .mockResolvedValueOnce(image({ viewUrl: NEW_VIEW }))
    const wrapper = render(image({ urlsExpireAt: expiresIn(1_000) }), refresh)

    click(wrapper)
    await settle()
    click(wrapper)
    await settle()

    expect(refresh).toHaveBeenCalledTimes(2)
    expect(clickSpy).toHaveBeenCalledOnce()
    expect(wrapper.get('a').attributes('href')).toBe(NEW_VIEW)
  })
})
