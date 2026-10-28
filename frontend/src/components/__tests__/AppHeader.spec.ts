import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory } from 'vue-router'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { ApiResult } from '../../api/http'
import type { User } from '../../api/types'
import { navigateTo } from '../../lib/navigation'
import { createAppRouter } from '../../router'
import { useSessionStore } from '../../stores/session'
import AppHeader from '../AppHeader.vue'

const api = vi.hoisted(() => ({ me: vi.fn(), logout: vi.fn() }))
vi.mock('../../api/auth', () => api)
vi.mock('../../lib/navigation', () => ({ navigateTo: vi.fn() }))

const server500: ApiResult<null> = { ok: false, error: { kind: 'server', status: 500 } }

afterEach(() => {
  api.me.mockReset()
  api.logout.mockReset()
  vi.mocked(navigateTo).mockReset()
})

async function mountHeader(me: ApiResult<User>) {
  api.me.mockResolvedValue(me)
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = createAppRouter(createMemoryHistory())
  await router.push('/login')
  await useSessionStore().ensureLoaded()
  const wrapper = mount(AppHeader, { global: { plugins: [pinia, router] } })
  await flushPromises()
  return wrapper
}

const signedIn = (email: string): ApiResult<User> => ({
  ok: true,
  status: 200,
  data: { id: 'u', email },
})
const signedOut: ApiResult<User> = { ok: false, error: { kind: 'unauthorized', status: 401 } }

describe('AppHeader: signed in', () => {
  it('shows the email and links to Expenses and Categories', async () => {
    const wrapper = await mountHeader(signedIn('ana@example.com'))

    expect(wrapper.text()).toContain('ana@example.com')
    const links = wrapper.findAll('nav a').map((a) => [a.text(), a.attributes('href')])
    expect(links).toEqual([
      ['Expenses', '/expenses'],
      ['Categories', '/categories'],
    ])
  })

  it('renders an email-like string with markup as text, never as elements', async () => {
    const wrapper = await mountHeader(signedIn('<b>x</b>@example.com'))

    expect(wrapper.find('b').exists()).toBe(false)
    expect(wrapper.text()).toContain('<b>x</b>@example.com')
  })

  it('puts the full address in a title, since a long one is clipped', async () => {
    const wrapper = await mountHeader(signedIn('ana@example.com'))

    expect(wrapper.get('.email').attributes('title')).toBe('ana@example.com')
  })

  it('wraps the email in bdi so a right-to-left address cannot reorder the page', async () => {
    const wrapper = await mountHeader(signedIn('ana@example.com'))

    expect(wrapper.get('bdi').text()).toBe('ana@example.com')
  })
})

describe('AppHeader: not signed in', () => {
  it('shows neither the navigation, the email nor the Sign out button', async () => {
    const wrapper = await mountHeader(signedOut)

    expect(wrapper.find('nav').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Sign out')
    expect(wrapper.find('bdi').exists()).toBe(false)
  })
})

describe('AppHeader: Sign out', () => {
  it('calls logout and leaves through a full page load of /login', async () => {
    api.logout.mockResolvedValue({ ok: true, status: 204, data: null })
    const wrapper = await mountHeader(signedIn('a@b.c'))

    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(api.logout).toHaveBeenCalledTimes(1)
    expect(navigateTo).toHaveBeenCalledWith('/login')
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
  })

  it('marks the button aria-disabled while pending, and a click then sends nothing', async () => {
    let finish: (value: ApiResult<null>) => void = () => undefined
    api.logout.mockReturnValue(new Promise((resolve) => (finish = resolve)))
    const wrapper = await mountHeader(signedIn('a@b.c'))
    const button = wrapper.get('button')

    await button.trigger('click')
    expect(button.attributes('aria-disabled')).toBe('true')
    expect(button.attributes('disabled')).toBeUndefined()
    await button.trigger('click')
    expect(api.logout).toHaveBeenCalledTimes(1)

    finish(server500)
    await flushPromises()
    expect(button.attributes('aria-disabled')).toBeUndefined()
  })

  it('on failure shows "Sign-out failed", stays signed in and allows another try', async () => {
    api.logout.mockResolvedValue(server500)
    const wrapper = await mountHeader(signedIn('a@b.c'))

    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('Sign-out failed')
    expect(useSessionStore().status).toBe('signed-in')
    expect(navigateTo).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('a@b.c')
    expect(wrapper.get('button').attributes('aria-disabled')).toBeUndefined()
  })

  it('clears the failure message when the next attempt starts', async () => {
    api.logout.mockResolvedValueOnce(server500).mockReturnValueOnce(new Promise(() => undefined))
    const wrapper = await mountHeader(signedIn('a@b.c'))

    await wrapper.get('button').trigger('click')
    await flushPromises()
    await wrapper.get('button').trigger('click')

    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
  })

  it('shows the failure message when the logout call itself throws', async () => {
    api.logout.mockRejectedValue(new Error('boom'))
    const wrapper = await mountHeader(signedIn('a@b.c'))

    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('Sign-out failed')
    expect(wrapper.get('button').attributes('aria-disabled')).toBeUndefined()
  })
})
