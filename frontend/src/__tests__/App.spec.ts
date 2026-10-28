import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory } from 'vue-router'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { ApiResult } from '../api/http'
import type { User } from '../api/types'
import App from '../App.vue'
import { createAppRouter } from '../router'
import { flashMessage, setFlash } from '../lib/flash'
import { useSessionStore } from '../stores/session'

const api = vi.hoisted(() => ({ me: vi.fn() }))
vi.mock('../api/auth', () => api)
vi.mock('../lib/navigation', () => ({ navigateTo: vi.fn() }))
vi.mock('@/api/categories', () =>
  import('@/api/__tests__/emptyCategoriesApi').then((m) => m.emptyCategoriesApi()),
)
vi.mock('@/api/expenses', () =>
  import('@/api/__tests__/emptyExpensesApi').then((m) => m.emptyExpensesApi()),
)

const signedIn: ApiResult<User> = { ok: true, status: 200, data: { id: 'u', email: 'a@b.c' } }

afterEach(() => {
  vi.unstubAllGlobals()
  api.me.mockReset()
})

async function mountApp(path: string, meResult: ApiResult<User>) {
  vi.stubGlobal(
    'fetch',
    vi.fn(() => Promise.resolve(new Response(JSON.stringify({ status: 'ok' })))),
  )
  api.me.mockResolvedValue(meResult)
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = createAppRouter(createMemoryHistory())
  await router.push(path)
  await router.isReady()
  const wrapper = mount(App, { global: { plugins: [pinia, router] } })
  await flushPromises()
  return { wrapper, router }
}

async function mountAt(path: string, meResult: ApiResult<User>) {
  return (await mountApp(path, meResult)).wrapper
}

describe('App', () => {
  it('shows the API status and the page of the current route', async () => {
    const wrapper = await mountAt('/expenses', signedIn)

    expect(wrapper.get('[role="status"]').text()).toBe('API: ok')
    expect(wrapper.get('h1').text()).toBe('Expenses')
  })

  it('shows the sign-in page to a signed-out visitor', async () => {
    const wrapper = await mountAt('/expenses', {
      ok: false,
      error: { kind: 'unauthorized', status: 401 },
    })

    expect(wrapper.get('h1').text()).toBe('Sign in')
  })

  it('shows "Can\'t reach the server" instead of the page when the session cannot be checked', async () => {
    const wrapper = await mountAt('/expenses', { ok: false, error: { kind: 'network' } })

    expect(wrapper.get('h1').text()).toBe("Can't reach the server")
    expect(wrapper.text()).not.toContain('Sign in')
    expect(wrapper.get('button').text()).toBe('Retry')
    expect(wrapper.findAll('[role="status"]').map((node) => node.text())).toContain('API: ok')
  })
})

describe('App: shell', () => {
  it('has a skip link as the first focusable thing, pointing at the main landmark', async () => {
    const wrapper = await mountAt('/expenses', signedIn)

    const skip = wrapper.get('a')
    expect(skip.text()).toBe('Skip to main content')
    expect(skip.attributes('href')).toBe('#main')
    expect(wrapper.get('main').attributes('id')).toBe('main')
  })

  it('the skip link moves focus to main without changing the address', async () => {
    const { wrapper, router } = await mountApp('/expenses', signedIn)
    document.body.append(wrapper.element)

    await wrapper.get('a').trigger('click')

    expect(document.activeElement).toBe(wrapper.get('main').element)
    expect(router.currentRoute.value.fullPath).toBe('/expenses')
    expect(window.location.hash).toBe('')
    wrapper.element.remove()
  })

  it('draws the page inside main, the header above it and the API status in the footer', async () => {
    const wrapper = await mountAt('/expenses', signedIn)

    expect(wrapper.get('main h1').text()).toBe('Expenses')
    expect(wrapper.get('header').text()).toContain('a@b.c')
    expect(wrapper.get('footer [role="status"]').text()).toBe('API: ok')
    expect(wrapper.find('main [role="status"]').exists()).toBe(false)
  })

  it('shows no banner by default', async () => {
    const wrapper = await mountAt('/expenses', signedIn)

    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
  })

  it('keeps the header and the footer around the "Can\'t reach the server" page', async () => {
    const wrapper = await mountAt('/expenses', { ok: false, error: { kind: 'network' } })

    expect(wrapper.get('main h1').text()).toBe("Can't reach the server")
    expect(wrapper.find('header nav').exists()).toBe(false)
    expect(wrapper.find('footer').exists()).toBe(true)
  })
})

describe('App: the session changes without a navigation', () => {
  const signedOut: ApiResult<User> = { ok: false, error: { kind: 'unauthorized', status: 401 } }

  it('signed in while on a guest page: the redirect to /expenses is issued', async () => {
    const { wrapper, router } = await mountApp('/login', signedOut)

    useSessionStore().status = 'signed-in'
    await flushPromises()

    expect(router.currentRoute.value.fullPath).toBe('/expenses')
    expect(wrapper.get('h1').text()).toBe('Expenses')
  })

  it('signed out while on a private page: the redirect to login keeps the path', async () => {
    const { wrapper, router } = await mountApp('/categories', signedIn)

    useSessionStore().status = 'signed-out'
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.redirect).toBe('/categories')
    expect(wrapper.get('h1').text()).toBe('Sign in')
  })

  it('does nothing in the steady state, and does not loop after a redirect', async () => {
    const { router } = await mountApp('/categories', signedIn)
    const replace = vi.spyOn(router, 'replace')
    await flushPromises()
    expect(replace).not.toHaveBeenCalled()

    useSessionStore().status = 'signed-out'
    await flushPromises()

    expect(replace).toHaveBeenCalledTimes(1)
  })

  it('does not redirect on the first answer: the guard owns the first navigation', async () => {
    const { router } = await mountApp('/categories', signedOut)

    expect(router.currentRoute.value.query.redirect).toBe('/categories')
  })

  it('leaves the first navigation to the guard: a status change before any route is resolved does nothing', async () => {
    api.me.mockResolvedValue(signedIn)
    const pinia = createPinia()
    setActivePinia(pinia)
    const router = createAppRouter(createMemoryHistory())
    const replace = vi.spyOn(router, 'replace')
    mount(App, { global: { plugins: [pinia, router] } })

    useSessionStore().status = 'signed-out'
    await flushPromises()

    expect(replace).not.toHaveBeenCalled()
  })
})

describe('App: flash message', () => {
  it('shows a flash message in a polite live region that is always in the page', async () => {
    const { wrapper } = await mountApp('/expenses', signedIn)
    const region = wrapper.get('.flash')
    expect(region.attributes('aria-live')).toBe('polite')
    expect(region.text()).toBe('')

    await setFlash('Expense saved')
    await flushPromises()

    expect(wrapper.get('.flash').text()).toBe('Expense saved')
  })

  it('clears the message on the next navigation', async () => {
    const { wrapper, router } = await mountApp('/expenses', signedIn)
    await setFlash('Expense saved')

    await router.push('/categories')
    await flushPromises()

    expect(wrapper.get('.flash').text()).toBe('')
    expect(flashMessage.value).toBe('')
  })
})
