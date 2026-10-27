import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import * as authApi from '@/api/auth'
import { useSessionStore } from '@/stores/session'
import RegisterView from '../RegisterView.vue'
import { deferred, fail, fillAndSubmit, okUser, renderBare, renderSignedOut } from './authHarness'

vi.mock('@/api/auth', () => ({ me: vi.fn(), login: vi.fn(), register: vi.fn(), logout: vi.fn() }))
vi.mock('@/lib/navigation', async (original) => ({
  ...(await original<typeof import('@/lib/navigation')>()),
  navigateTo: vi.fn(),
}))
vi.mock('@/api/health', () => ({ fetchHealth: () => new Promise(() => {}) }))

const register = vi.mocked(authApi.register)
const GOOD_PASSWORD = 'correct horse battery'

beforeEach(() => {
  register.mockReset()
})
afterEach(() => {
  document.body.replaceChildren()
})

describe('RegisterView: page', () => {
  it('has one h1, new-password, a minimum hint and a link to sign-in', async () => {
    const { wrapper } = await renderSignedOut('/register')

    expect(wrapper.findAll('h1')).toHaveLength(1)
    expect(wrapper.get('h1').text()).toBe('Create account')
    expect(wrapper.get('form').attributes('novalidate')).toBeDefined()
    expect(wrapper.get('#register-email').attributes('autocomplete')).toBe('username')
    expect(wrapper.get('#register-password').attributes('autocomplete')).toBe('new-password')
    expect(wrapper.get('#register-password').attributes('aria-describedby')).toBeDefined()
    expect(wrapper.text()).toContain('At least 12 characters')
    expect(wrapper.find('main a[href="/login"]').exists()).toBe(true)
  })

  it('names its section, turns browser help off and gives the inputs no name', async () => {
    const { wrapper } = await renderSignedOut('/register')

    expect(wrapper.get('main section').attributes('aria-labelledby')).toBe(
      wrapper.get('h1').attributes('id'),
    )
    expect(wrapper.get('#register-email').attributes('autocapitalize')).toBe('off')
    expect(wrapper.get('#register-email').attributes('spellcheck')).toBe('false')
    expect(wrapper.get('#register-password').attributes('spellcheck')).toBe('false')
    expect(wrapper.get('#register-email').attributes('name')).toBeUndefined()
    expect(wrapper.get('#register-password').attributes('name')).toBeUndefined()
  })

  it('hides a shown password again on every submit', async () => {
    register.mockResolvedValue(fail({ kind: 'server', status: 500 }))
    const { wrapper } = await renderSignedOut('/register')
    await wrapper.get('button[aria-pressed]').trigger('click')

    await fillAndSubmit(wrapper, 'register', 'a@example.com', GOOD_PASSWORD)

    expect(wrapper.get('#register-password').attributes('type')).toBe('password')
  })

  it('has a show/hide toggle and does not prevent paste', async () => {
    const { wrapper } = await renderSignedOut('/register')
    const paste = new Event('paste', { bubbles: true, cancelable: true })

    wrapper.get('#register-password').element.dispatchEvent(paste)
    await wrapper.get('button[aria-pressed]').trigger('click')

    expect(paste.defaultPrevented).toBe(false)
    expect(wrapper.get('#register-password').attributes('type')).toBe('text')
  })
})

describe('RegisterView: success', () => {
  it('201 updates the session store and asks for /expenses (no App around the view)', async () => {
    register.mockResolvedValue(okUser)
    const { replace, router, wrapper } = await renderBare(RegisterView, '/register')

    await fillAndSubmit(wrapper, 'register', ' a@example.com ', GOOD_PASSWORD)

    expect(register).toHaveBeenCalledWith({ email: 'a@example.com', password: GOOD_PASSWORD })
    expect(useSessionStore().status).toBe('signed-in')
    expect(replace.mock.calls).toEqual([['/expenses']])
    expect(router.currentRoute.value.fullPath).toBe('/expenses')
  })

  it('with App around it, ends on the Expenses page', async () => {
    register.mockResolvedValue(okUser)
    const { wrapper } = await renderSignedOut('/register')

    await fillAndSubmit(wrapper, 'register', 'a@example.com', GOOD_PASSWORD)

    expect(wrapper.get('h1').text()).toBe('Expenses')
  })

  it('asks for /expenses even when the address carries a redirect', async () => {
    register.mockResolvedValue(okUser)
    const { replace, wrapper } = await renderBare(RegisterView, '/register?redirect=//evil.example')

    await fillAndSubmit(wrapper, 'register', 'a@example.com', GOOD_PASSWORD)

    expect(replace.mock.calls).toEqual([['/expenses']])
  })

  it('a navigation that does not happen leaves the form usable', async () => {
    register.mockResolvedValue(okUser)
    const { replace, wrapper } = await renderBare(RegisterView, '/register')
    replace.mockResolvedValue(undefined)

    await fillAndSubmit(wrapper, 'register', 'a@example.com', GOOD_PASSWORD)

    expect(wrapper.get('button[type="submit"]').attributes('aria-disabled')).toBeUndefined()
    expect(wrapper.get('button[type="submit"]').text()).toBe('Create account')
  })
})

describe('RegisterView: password length', () => {
  it('refuses 11 code points without a request, and accepts 12', async () => {
    register.mockResolvedValue(fail({ kind: 'server', status: 500 }))
    const { wrapper } = await renderSignedOut('/register')

    await fillAndSubmit(wrapper, 'register', 'a@example.com', 'x'.repeat(11))
    expect(register).not.toHaveBeenCalled()
    expect(wrapper.get('#register-password').attributes('aria-invalid')).toBe('true')
    expect(document.activeElement).toBe(wrapper.get('#register-password').element)

    await fillAndSubmit(wrapper, 'register', 'a@example.com', 'x'.repeat(12))
    expect(register).toHaveBeenCalledTimes(1)
  })

  it('counts emoji as one code point each: 12 pass, 11 do not', async () => {
    register.mockResolvedValue(fail({ kind: 'server', status: 500 }))
    const { wrapper } = await renderSignedOut('/register')

    await fillAndSubmit(wrapper, 'register', 'a@example.com', '😀'.repeat(11))
    expect(register).not.toHaveBeenCalled()

    await fillAndSubmit(wrapper, 'register', 'a@example.com', '😀'.repeat(12))
    expect(register).toHaveBeenCalledTimes(1)
  })

  it('refuses over 128 code points and accepts exactly 128', async () => {
    register.mockResolvedValue(fail({ kind: 'server', status: 500 }))
    const { wrapper } = await renderSignedOut('/register')

    await fillAndSubmit(wrapper, 'register', 'a@example.com', 'x'.repeat(129))
    expect(register).not.toHaveBeenCalled()

    await fillAndSubmit(wrapper, 'register', 'a@example.com', 'x'.repeat(128))
    expect(register).toHaveBeenCalledTimes(1)
  })

  it('does not trim the password: 12 characters of spaces and letters count as typed', async () => {
    register.mockResolvedValue(fail({ kind: 'server', status: 500 }))
    const { wrapper } = await renderSignedOut('/register')

    await fillAndSubmit(wrapper, 'register', 'a@example.com', '  abcdefgh  ')

    expect(register).toHaveBeenCalledWith({ email: 'a@example.com', password: '  abcdefgh  ' })
  })

  it('refuses an empty email and focuses it first', async () => {
    const { wrapper } = await renderSignedOut('/register')

    await fillAndSubmit(wrapper, 'register', '', 'short')

    expect(register).not.toHaveBeenCalled()
    expect(document.activeElement).toBe(wrapper.get('#register-email').element)
  })
})

describe('RegisterView: errors', () => {
  it('409 says the account exists, links to sign-in, and focuses the alert', async () => {
    register.mockResolvedValue(fail({ kind: 'conflict', status: 409, detail: 'Server wording' }))
    const { wrapper } = await renderSignedOut('/register')

    await fillAndSubmit(wrapper, 'register', 'a@example.com', GOOD_PASSWORD)

    const alert = wrapper.get('[role="alert"]')
    expect(alert.text()).toContain('already exists')
    expect(alert.text()).not.toContain('Server wording')
    expect(document.activeElement).toBe(alert.element)
    expect(wrapper.get('[data-test="account-exists"] a').attributes('href')).toBe('/login')
  })

  it('503 shows the may-exist hint', async () => {
    register.mockResolvedValue(fail({ kind: 'unavailable', status: 503, retryAfterSeconds: 5 }))
    const { wrapper } = await renderSignedOut('/register')

    await fillAndSubmit(wrapper, 'register', 'a@example.com', GOOD_PASSWORD)

    expect(wrapper.get('[role="alert"]').text()).toContain('your account may exist: try signing in')
  })

  it('422 at /password shows the server text on the field and focuses it', async () => {
    register.mockResolvedValue(
      fail({
        kind: 'validation',
        status: 422,
        fieldErrors: { password: 'Too common.' },
        formErrors: [],
      }),
    )
    const { wrapper } = await renderSignedOut('/register')

    await fillAndSubmit(wrapper, 'register', 'a@example.com', GOOD_PASSWORD)

    const input = wrapper.get('#register-password')
    expect(wrapper.get(`#${input.attributes('aria-describedby')?.split(' ').at(-1)}`).text()).toBe(
      'Too common.',
    )
    expect(input.attributes('aria-invalid')).toBe('true')
    expect(document.activeElement).toBe(input.element)
  })

  it('422 at /email focuses the email field', async () => {
    register.mockResolvedValue(
      fail({
        kind: 'validation',
        status: 422,
        fieldErrors: { email: 'Not an address.' },
        formErrors: [],
      }),
    )
    const { wrapper } = await renderSignedOut('/register')

    await fillAndSubmit(wrapper, 'register', 'a@example.com', GOOD_PASSWORD)

    expect(document.activeElement).toBe(wrapper.get('#register-email').element)
  })

  it('429 gives the wait and a network failure keeps the typed values', async () => {
    register.mockResolvedValueOnce(
      fail({ kind: 'rate-limited', status: 429, retryAfterSeconds: 897 }),
    )
    const { wrapper } = await renderSignedOut('/register')
    await fillAndSubmit(wrapper, 'register', 'a@example.com', GOOD_PASSWORD)
    expect(wrapper.get('[role="alert"]').text()).toContain('about 15 minutes')

    register.mockResolvedValueOnce(fail({ kind: 'network' }))
    await fillAndSubmit(wrapper, 'register', 'a@example.com', GOOD_PASSWORD)

    expect(wrapper.get('[role="alert"]').text()).toContain("Can't reach the server")
    expect((wrapper.get('#register-password').element as HTMLInputElement).value).toBe(
      GOOD_PASSWORD,
    )
  })

  it('the sign-in link is absent unless the account exists', async () => {
    register.mockResolvedValue(fail({ kind: 'server', status: 500 }))
    const { wrapper } = await renderSignedOut('/register')

    await fillAndSubmit(wrapper, 'register', 'a@example.com', GOOD_PASSWORD)

    expect(wrapper.find('[data-test="account-exists"]').exists()).toBe(false)
  })
})

describe('RegisterView: pending', () => {
  it('sends one request on a double submit and shows the button busy', async () => {
    const pending = deferred<typeof okUser>()
    register.mockReturnValue(pending.promise)
    const { wrapper } = await renderSignedOut('/register')
    await wrapper.get('#register-email').setValue('a@example.com')
    await wrapper.get('#register-password').setValue(GOOD_PASSWORD)

    await wrapper.get('form').trigger('submit')
    await wrapper.get('form').trigger('submit')

    expect(register).toHaveBeenCalledTimes(1)
    expect(wrapper.get('button[type="submit"]').attributes('aria-disabled')).toBe('true')
    expect(wrapper.get('button[type="submit"]').text()).toBe('Creating account…')
    expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeUndefined()

    pending.resolve(okUser)
    await flushPromises()
  })

  it('an unexpected throw leaves the button usable again and reaches the error handler', async () => {
    register.mockRejectedValue(new Error('boom'))
    const onError = vi.fn()
    const { wrapper } = await renderSignedOut('/register', onError)

    await fillAndSubmit(wrapper, 'register', 'a@example.com', GOOD_PASSWORD)

    expect(onError).toHaveBeenCalledTimes(1)
    expect(wrapper.get('button[type="submit"]').attributes('aria-disabled')).toBeUndefined()
  })
})
