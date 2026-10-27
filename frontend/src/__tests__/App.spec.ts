import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory } from 'vue-router'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { ApiResult } from '../api/http'
import type { User } from '../api/types'
import App from '../App.vue'
import { createAppRouter } from '../router'
import { useSessionStore } from '../stores/session'

const api = vi.hoisted(() => ({ me: vi.fn() }))
vi.mock('../api/auth', () => api)
vi.mock('../lib/navigation', () => ({ navigateTo: vi.fn() }))

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
