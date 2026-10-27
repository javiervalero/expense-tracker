import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory } from 'vue-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { ApiResult } from '@/api/http'
import type { User } from '@/api/types'
import { createAppRouter } from '@/router'
import { useSessionStore } from '@/stores/session'
import ServiceUnavailableView from '../ServiceUnavailableView.vue'

const api = vi.hoisted(() => ({ me: vi.fn() }))
vi.mock('@/api/auth', () => api)
vi.mock('@/lib/navigation', () => ({ navigateTo: vi.fn() }))

const user: User = { id: 'u1', email: 'a@example.com' }
const signedIn: ApiResult<User> = { ok: true, status: 200, data: user }
const signedOut: ApiResult<User> = { ok: false, error: { kind: 'unauthorized', status: 401 } }
const network: ApiResult<User> = { ok: false, error: { kind: 'network' } }

beforeEach(() => {
  setActivePinia(createPinia())
  document.title = 'Before'
})
afterEach(() => {
  api.me.mockReset()
  document.body.replaceChildren()
})

async function mountAt(path: string) {
  api.me.mockResolvedValueOnce(network)
  const router = createAppRouter(createMemoryHistory())
  await router.push(path)
  const host = document.createElement('div')
  document.body.appendChild(host)
  const wrapper = mount(ServiceUnavailableView, { attachTo: host, global: { plugins: [router] } })
  return { router, wrapper }
}

describe('ServiceUnavailableView', () => {
  it('explains the problem and offers Retry', async () => {
    const { wrapper } = await mountAt('/expenses')

    expect(wrapper.get('h1').text()).toBe("Can't reach the server")
    expect(wrapper.get('button').text()).toBe('Retry')
  })

  it('moves focus to the heading on mount', async () => {
    const { wrapper } = await mountAt('/expenses')

    expect(wrapper.get('h1').attributes('tabindex')).toBe('-1')
    expect(document.activeElement).toBe(wrapper.get('h1').element)
  })

  it('sets the document title, and puts the old one back when it goes away', async () => {
    const { wrapper } = await mountAt('/expenses')

    expect(document.title).toBe("Can't reach the server · Expense tracker")

    wrapper.unmount()
    expect(document.title).toBe('Before')
  })

  it('has an empty status line until a retry fails', async () => {
    const { wrapper } = await mountAt('/expenses')

    expect(wrapper.get('[role="status"]').text()).toBe('')
  })

  it('Retry that finds the user signed in stays on the same path', async () => {
    const { router, wrapper } = await mountAt('/expenses?from=2026-01-01')
    api.me.mockResolvedValueOnce(signedIn)

    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(useSessionStore().status).toBe('signed-in')
    expect(router.currentRoute.value.fullPath).toBe('/expenses?from=2026-01-01')
  })

  it('Retry that finds the user signed out goes to /login with the redirect', async () => {
    const { router, wrapper } = await mountAt('/categories')
    api.me.mockResolvedValueOnce(signedOut)

    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.redirect).toBe('/categories')
  })

  it('Retry on a guest route with a signed-in user goes to /expenses', async () => {
    const { router, wrapper } = await mountAt('/login')
    api.me.mockResolvedValueOnce(signedIn)

    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.fullPath).toBe('/expenses')
  })

  it('Retry while still down says so in the status line and makes the button usable again', async () => {
    const { router, wrapper } = await mountAt('/expenses')
    api.me.mockResolvedValueOnce(network)

    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(useSessionStore().status).toBe('unreachable')
    expect(router.currentRoute.value.fullPath).toBe('/expenses')
    expect(wrapper.get('[role="status"]').text()).toBe(
      "Still can't reach the server. Try again in a moment.",
    )
    expect(wrapper.get('button').attributes('aria-disabled')).not.toBe('true')
    expect(api.me).toHaveBeenCalledTimes(2)
  })

  it('clears the status line when the next retry starts', async () => {
    const { wrapper } = await mountAt('/expenses')
    api.me.mockResolvedValueOnce(network)
    await wrapper.get('button').trigger('click')
    await flushPromises()
    api.me.mockReturnValueOnce(new Promise(() => {}))

    await wrapper.get('button').trigger('click')

    expect(wrapper.get('[role="status"]').text()).toBe('')
  })

  it('while a retry runs the button is aria-disabled (not disabled, so focus stays) and ignores clicks', async () => {
    const { wrapper } = await mountAt('/expenses')
    api.me.mockReturnValueOnce(new Promise(() => {}))
    const button = wrapper.get('button')
    button.element.focus()

    await button.trigger('click')
    await button.trigger('click')

    expect(button.attributes('aria-disabled')).toBe('true')
    expect(button.attributes('disabled')).toBeUndefined()
    expect(document.activeElement).toBe(button.element)
    expect(api.me).toHaveBeenCalledTimes(2)
  })
})
