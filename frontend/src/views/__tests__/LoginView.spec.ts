import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import * as authApi from '@/api/auth'
import { useSessionStore } from '@/stores/session'
import LoginView from '../LoginView.vue'
import { deferred, fail, fillAndSubmit, okUser, renderBare, renderSignedOut } from './authHarness'

vi.mock('@/api/auth', () => ({ me: vi.fn(), login: vi.fn(), register: vi.fn(), logout: vi.fn() }))
vi.mock('@/lib/navigation', async (original) => ({
  ...(await original<typeof import('@/lib/navigation')>()),
  navigateTo: vi.fn(),
}))
vi.mock('@/api/health', () => ({ fetchHealth: () => new Promise(() => {}) }))

const login = vi.mocked(authApi.login)
const GOOD_PASSWORD = 'correct horse battery'

beforeEach(() => {
  login.mockReset()
})
afterEach(() => {
  document.body.replaceChildren()
})

describe('LoginView: page', () => {
  it('has one h1, labelled fields with the right autocomplete, and a link to register', async () => {
    const { wrapper } = await renderSignedOut('/login')

    expect(wrapper.findAll('h1')).toHaveLength(1)
    expect(wrapper.get('h1').text()).toBe('Sign in')
    expect(wrapper.get('form').attributes('novalidate')).toBeDefined()
    expect(wrapper.get('#login-email').attributes('autocomplete')).toBe('username')
    expect(wrapper.get('#login-email').attributes('type')).toBe('email')
    expect(wrapper.get('#login-password').attributes('autocomplete')).toBe('current-password')
    expect(wrapper.get('#login-password').attributes('type')).toBe('password')
    expect(wrapper.find('a[href="/register"]').exists()).toBe(true)
  })

  it('names its section by the heading, and turns browser help off on the inputs', async () => {
    const { wrapper } = await renderSignedOut('/login')
    const section = wrapper.get('main section')

    expect(section.attributes('aria-labelledby')).toBe(wrapper.get('h1').attributes('id'))
    expect(wrapper.get('#login-email').attributes('autocapitalize')).toBe('off')
    expect(wrapper.get('#login-email').attributes('spellcheck')).toBe('false')
    expect(wrapper.get('#login-password').attributes('spellcheck')).toBe('false')
    expect(wrapper.get('#login-password').attributes('autocapitalize')).toBe('none')
    expect(wrapper.get('#login-password').attributes('autocorrect')).toBe('off')
  })

  it('gives the inputs no name attribute, so nothing is a form field for the browser to submit or store', async () => {
    const { wrapper } = await renderSignedOut('/login')

    expect(wrapper.get('#login-email').attributes('name')).toBeUndefined()
    expect(wrapper.get('#login-password').attributes('name')).toBeUndefined()
  })

  it('hides a shown password again on every submit', async () => {
    login.mockResolvedValue(fail({ kind: 'invalid-credentials', status: 401 }))
    const { wrapper } = await renderSignedOut('/login')
    await wrapper.get('button[aria-pressed]').trigger('click')
    expect(wrapper.get('#login-password').attributes('type')).toBe('text')

    await fillAndSubmit(wrapper, 'login', 'a@example.com', GOOD_PASSWORD)

    expect(wrapper.get('#login-password').attributes('type')).toBe('password')
    expect(wrapper.get('button[aria-pressed]').attributes('aria-pressed')).toBe('false')
    expect((wrapper.get('#login-password').element as HTMLInputElement).value).toBe(GOOD_PASSWORD)
  })

  it('the show/hide toggle switches the password type', async () => {
    const { wrapper } = await renderSignedOut('/login')

    await wrapper.get('button[aria-pressed]').trigger('click')

    expect(wrapper.get('#login-password').attributes('type')).toBe('text')
  })

  it('does not prevent paste into the password field', async () => {
    const { wrapper } = await renderSignedOut('/login')
    const paste = new Event('paste', { bubbles: true, cancelable: true })

    wrapper.get('#login-password').element.dispatchEvent(paste)

    expect(paste.defaultPrevented).toBe(false)
  })
})

describe('LoginView: success and redirect', () => {
  it('signs in, updates the session store and replaces the route with the safe redirect', async () => {
    login.mockResolvedValue(okUser)
    const { router, wrapper } = await renderSignedOut('/login?redirect=/categories')

    await fillAndSubmit(wrapper, 'login', 'a@example.com', GOOD_PASSWORD)

    expect(login).toHaveBeenCalledWith({ email: 'a@example.com', password: GOOD_PASSWORD })
    const session = useSessionStore()
    expect(session.status).toBe('signed-in')
    expect(session.user).toEqual(okUser.ok ? okUser.data : null)
    expect(router.currentRoute.value.fullPath).toBe('/categories')
    expect(wrapper.get('h1').text()).toBe('Categories')
  })

  // These run WITHOUT App around the view: App's watcher also redirects a signed-in user, which would hide what the view
  // itself asked for. Here `replace` is spied on and the exact path is asserted.
  it('asks for the redirect, with its query, and nothing else', async () => {
    login.mockResolvedValue(okUser)
    const { replace, router, wrapper } = await renderBare(
      LoginView,
      '/login?redirect=/expenses?from=2026-01-01',
    )

    await fillAndSubmit(wrapper, 'login', 'a@example.com', GOOD_PASSWORD)

    expect(replace.mock.calls).toEqual([['/expenses?from=2026-01-01']])
    expect(router.currentRoute.value.fullPath).toBe('/expenses?from=2026-01-01')
  })

  it('without a redirect asks for /expenses', async () => {
    login.mockResolvedValue(okUser)
    const { replace, wrapper } = await renderBare(LoginView, '/login')

    await fillAndSubmit(wrapper, 'login', 'a@example.com', GOOD_PASSWORD)

    expect(replace.mock.calls).toEqual([['/expenses']])
  })

  it.each([
    ['//evil.example'],
    ['/\\evil.example'],
    ['https://evil.example/expenses'],
    ['java' + 'script:alert(1)'],
    ['/login'],
    ['/register'],
    ['/no-such-page'],
    ['/EXPENSES'],
    ['/expenses/not-a-uuid'],
    [''],
  ])('a hostile redirect %j asks for /expenses', async (hostile) => {
    login.mockResolvedValue(okUser)
    const { replace, router, wrapper } = await renderBare(
      LoginView,
      `/login?redirect=${encodeURIComponent(hostile)}`,
    )

    await fillAndSubmit(wrapper, 'login', 'a@example.com', GOOD_PASSWORD)

    expect(replace.mock.calls).toEqual([['/expenses']])
    expect(router.currentRoute.value.fullPath).toBe('/expenses')
  })

  it('a repeated redirect parameter asks for /expenses', async () => {
    login.mockResolvedValue(okUser)
    const { replace, wrapper } = await renderBare(
      LoginView,
      '/login?redirect=/categories&redirect=/categories',
    )

    await fillAndSubmit(wrapper, 'login', 'a@example.com', GOOD_PASSWORD)

    expect(replace.mock.calls).toEqual([['/expenses']])
  })

  it('uses the redirect as it was when the form was submitted, not as it is when the answer comes', async () => {
    const answer = deferred<typeof okUser>()
    login.mockReturnValue(answer.promise)
    const { replace, router, wrapper } = await renderBare(LoginView, '/login?redirect=/categories')
    await wrapper.get('#login-email').setValue('a@example.com')
    await wrapper.get('#login-password').setValue(GOOD_PASSWORD)
    await wrapper.get('form').trigger('submit')

    await router.push('/login?redirect=//evil.example')
    replace.mockClear()
    answer.resolve(okUser)
    await flushPromises()

    expect(replace.mock.calls).toEqual([['/categories']])
  })

  it('a navigation that does not happen (replace resolves with a failure) leaves the form usable', async () => {
    login.mockResolvedValue(okUser)
    const { replace, wrapper } = await renderBare(LoginView, '/login')
    replace.mockResolvedValue(undefined)

    await fillAndSubmit(wrapper, 'login', 'a@example.com', GOOD_PASSWORD)

    expect(wrapper.get('button[type="submit"]').attributes('aria-disabled')).toBeUndefined()
    expect(wrapper.get('button[type="submit"]').text()).toBe('Sign in')
  })

  it('never shows the redirect value', async () => {
    const { wrapper } = await renderSignedOut('/login?redirect=/expenses?q=zz-marker-77')

    expect(wrapper.html()).not.toContain('zz-marker-77')
    expect(wrapper.text()).not.toContain('expenses?q')
  })

  it('sends the email trimmed and the password exactly as typed', async () => {
    login.mockResolvedValue(okUser)
    const { wrapper } = await renderSignedOut('/login')

    await fillAndSubmit(wrapper, 'login', '  a@example.com  ', ` ${GOOD_PASSWORD} `)

    expect(login).toHaveBeenCalledWith({ email: 'a@example.com', password: ` ${GOOD_PASSWORD} ` })
  })

  it('never puts the password in the address or in the session store', async () => {
    login.mockResolvedValue(fail({ kind: 'invalid-credentials', status: 401 }))
    const { router, wrapper } = await renderSignedOut('/login')

    await fillAndSubmit(wrapper, 'login', 'a@example.com', 'uniq-secret-pw-91')

    expect(router.currentRoute.value.fullPath).not.toContain('uniq-secret-pw-91')
    expect(JSON.stringify(useSessionStore().$state)).not.toContain('uniq-secret-pw-91')
    expect(wrapper.text()).not.toContain('uniq-secret-pw-91')
  })
})

describe('LoginView: session ended notice', () => {
  it('shows it for reason=expired', async () => {
    const { wrapper } = await renderSignedOut('/login?reason=expired')

    expect(wrapper.text()).toContain('Your session ended. Sign in again.')
  })

  it.each([['Expired'], ['expired '], ['EXPIRED'], ['expire'], ['x'], ['']])(
    'shows nothing for reason=%j',
    async (reason) => {
      const { wrapper } = await renderSignedOut(`/login?reason=${encodeURIComponent(reason)}`)

      expect(wrapper.text()).not.toContain('session ended')
    },
  )

  it('shows nothing for a repeated reason or none', async () => {
    const repeated = await renderSignedOut('/login?reason=expired&reason=expired')
    expect(repeated.wrapper.text()).not.toContain('session ended')

    const none = await renderSignedOut('/login')
    expect(none.wrapper.text()).not.toContain('session ended')
  })

  it('never renders reason text itself', async () => {
    const { wrapper } = await renderSignedOut('/login?reason=<b>zz-reason-55</b>')

    expect(wrapper.html()).not.toContain('zz-reason-55')
  })
})

describe('LoginView: errors', () => {
  it('401 shows the one generic message and focuses the alert', async () => {
    login.mockResolvedValue(fail({ kind: 'invalid-credentials', status: 401 }))
    const { wrapper } = await renderSignedOut('/login')

    await fillAndSubmit(wrapper, 'login', 'a@example.com', GOOD_PASSWORD)

    const alert = wrapper.get('[role="alert"]')
    expect(alert.text()).toBe('Email or password is incorrect.')
    expect(document.activeElement).toBe(alert.element)
  })

  it('an unauthorized error is the fixed session message, never the server text', async () => {
    login.mockResolvedValue(
      fail({ kind: 'unauthorized', status: 401, detail: 'Leaked server text' }),
    )
    const { wrapper } = await renderSignedOut('/login')

    await fillAndSubmit(wrapper, 'login', 'a@example.com', GOOD_PASSWORD)

    expect(wrapper.get('[role="alert"]').text()).toBe('Your session ended. Sign in again.')
    expect(wrapper.html()).not.toContain('Leaked')
  })

  it('429 with Retry-After 897 says about 15 minutes', async () => {
    login.mockResolvedValue(fail({ kind: 'rate-limited', status: 429, retryAfterSeconds: 897 }))
    const { wrapper } = await renderSignedOut('/login')

    await fillAndSubmit(wrapper, 'login', 'a@example.com', GOOD_PASSWORD)

    expect(wrapper.get('[role="alert"]').text()).toContain('about 15 minutes')
  })

  it('422 at /password sits on the password field, links it and focuses it', async () => {
    login.mockResolvedValue(
      fail({
        kind: 'validation',
        status: 422,
        fieldErrors: { password: 'Too long.' },
        formErrors: [],
      }),
    )
    const { wrapper } = await renderSignedOut('/login')

    await fillAndSubmit(wrapper, 'login', 'a@example.com', GOOD_PASSWORD)

    const input = wrapper.get('#login-password')
    expect(input.attributes('aria-invalid')).toBe('true')
    expect(wrapper.get(`#${input.attributes('aria-describedby')}`).text()).toBe('Too long.')
    expect(wrapper.get('#login-email').attributes('aria-invalid')).toBeUndefined()
    expect(document.activeElement).toBe(input.element)
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
  })

  it('422 at /email focuses the email field; a form-level 422 goes to the alert', async () => {
    login.mockResolvedValueOnce(
      fail({ kind: 'validation', status: 422, fieldErrors: { email: 'Bad.' }, formErrors: [] }),
    )
    const { wrapper } = await renderSignedOut('/login')
    await fillAndSubmit(wrapper, 'login', 'a@example.com', GOOD_PASSWORD)
    expect(document.activeElement).toBe(wrapper.get('#login-email').element)

    login.mockResolvedValueOnce(
      fail({
        kind: 'validation',
        status: 422,
        fieldErrors: {},
        formErrors: ['Whole form is wrong.'],
      }),
    )
    await fillAndSubmit(wrapper, 'login', 'a@example.com', GOOD_PASSWORD)

    expect(wrapper.get('[role="alert"]').text()).toBe('Whole form is wrong.')
    expect(document.activeElement).toBe(wrapper.get('[role="alert"]').element)
    expect(wrapper.get('#login-email').attributes('aria-invalid')).toBeUndefined()
  })

  it('a network failure keeps what was typed and says so', async () => {
    login.mockResolvedValue(fail({ kind: 'network' }))
    const { wrapper } = await renderSignedOut('/login')

    await fillAndSubmit(wrapper, 'login', 'a@example.com', GOOD_PASSWORD)

    expect(wrapper.get('[role="alert"]').text()).toContain("Can't reach the server")
    expect((wrapper.get('#login-email').element as HTMLInputElement).value).toBe('a@example.com')
    expect((wrapper.get('#login-password').element as HTMLInputElement).value).toBe(GOOD_PASSWORD)
  })

  it('a new attempt clears the previous errors', async () => {
    login.mockResolvedValueOnce(fail({ kind: 'invalid-credentials', status: 401 }))
    const { router, wrapper } = await renderSignedOut('/login')
    await fillAndSubmit(wrapper, 'login', 'a@example.com', GOOD_PASSWORD)
    const answer = deferred<typeof okUser>()
    login.mockReturnValueOnce(answer.promise)

    await wrapper.get('form').trigger('submit')

    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(router.currentRoute.value.path).toBe('/login')
    answer.resolve(okUser)
    await flushPromises()
  })
})

describe('LoginView: client checks', () => {
  it('refuses a password over 128 code points without a request, on the password field', async () => {
    const { wrapper } = await renderSignedOut('/login')

    await fillAndSubmit(wrapper, 'login', 'a@example.com', 'x'.repeat(129))

    expect(login).not.toHaveBeenCalled()
    expect(wrapper.get('#login-password').attributes('aria-invalid')).toBe('true')
    expect(document.activeElement).toBe(wrapper.get('#login-password').element)
  })

  it('counts code points, not UTF-16 units: 128 emoji pass, 129 do not', async () => {
    login.mockResolvedValue(fail({ kind: 'invalid-credentials', status: 401 }))
    const { wrapper } = await renderSignedOut('/login')

    await fillAndSubmit(wrapper, 'login', 'a@example.com', '😀'.repeat(129))
    expect(login).not.toHaveBeenCalled()

    await fillAndSubmit(wrapper, 'login', 'a@example.com', '😀'.repeat(128))
    expect(login).toHaveBeenCalledTimes(1)
  })

  it('accepts 12 emoji (sign-in has no minimum, and short ones are the server decision)', async () => {
    login.mockResolvedValue(fail({ kind: 'invalid-credentials', status: 401 }))
    const { wrapper } = await renderSignedOut('/login')

    await fillAndSubmit(wrapper, 'login', 'a@example.com', '😀'.repeat(12))

    expect(login).toHaveBeenCalledWith({ email: 'a@example.com', password: '😀'.repeat(12) })
  })

  it('refuses an empty email and an empty password, focusing the first', async () => {
    const { wrapper } = await renderSignedOut('/login')

    await fillAndSubmit(wrapper, 'login', '   ', '')

    expect(login).not.toHaveBeenCalled()
    expect(wrapper.get('#login-email').attributes('aria-invalid')).toBe('true')
    expect(wrapper.get('#login-password').attributes('aria-invalid')).toBe('true')
    expect(document.activeElement).toBe(wrapper.get('#login-email').element)
  })
})

describe('LoginView: pending', () => {
  it('keeps focus on the submit button while a request runs', async () => {
    const answer = deferred<typeof okUser>()
    login.mockReturnValue(answer.promise)
    const { wrapper } = await renderSignedOut('/login')
    await wrapper.get('#login-email').setValue('a@example.com')
    await wrapper.get('#login-password').setValue(GOOD_PASSWORD)
    const button = wrapper.get('button[type="submit"]')
    ;(button.element as HTMLElement).focus()

    await wrapper.get('form').trigger('submit')

    expect(document.activeElement).toBe(button.element)
    answer.resolve(okUser)
    await flushPromises()
  })

  it('sends one request on a double submit, and the button shows it is busy', async () => {
    const pending = deferred<typeof okUser>()
    login.mockReturnValue(pending.promise)
    const { wrapper } = await renderSignedOut('/login')
    await wrapper.get('#login-email').setValue('a@example.com')
    await wrapper.get('#login-password').setValue(GOOD_PASSWORD)

    await wrapper.get('form').trigger('submit')
    await wrapper.get('form').trigger('submit')
    await wrapper.get('button[type="submit"]').trigger('click')

    expect(login).toHaveBeenCalledTimes(1)
    expect(wrapper.get('button[type="submit"]').attributes('aria-disabled')).toBe('true')
    expect(wrapper.get('button[type="submit"]').text()).toBe('Signing in…')
    expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeUndefined()

    pending.resolve(okUser)
    await flushPromises()
  })

  it('allows another attempt after a failure', async () => {
    login.mockResolvedValue(fail({ kind: 'invalid-credentials', status: 401 }))
    const { wrapper } = await renderSignedOut('/login')

    await fillAndSubmit(wrapper, 'login', 'a@example.com', GOOD_PASSWORD)
    expect(wrapper.get('button[type="submit"]').attributes('aria-disabled')).toBeUndefined()
    await fillAndSubmit(wrapper, 'login', 'a@example.com', GOOD_PASSWORD)

    expect(login).toHaveBeenCalledTimes(2)
  })

  it('an unexpected throw leaves the button usable again and reaches the error handler', async () => {
    login.mockRejectedValue(new Error('boom'))
    const onError = vi.fn()
    const { wrapper } = await renderSignedOut('/login', onError)

    await fillAndSubmit(wrapper, 'login', 'a@example.com', GOOD_PASSWORD)

    expect(onError).toHaveBeenCalledTimes(1)
    expect(wrapper.get('button[type="submit"]').attributes('aria-disabled')).toBeUndefined()
  })
})
