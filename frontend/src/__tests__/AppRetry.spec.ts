import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory } from 'vue-router'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { ApiResult } from '../api/http'
import type { User } from '../api/types'
import App from '../App.vue'
import { createAppRouter } from '../router'

// Counters: how many times each view was created. A view that mounts for one tick would be enough to fire its API calls.
const mounted = vi.hoisted(() => ({ categories: vi.fn(), login: vi.fn(), expenses: vi.fn() }))
const api = vi.hoisted(() => ({ me: vi.fn() }))
vi.mock('../api/auth', () => api)
vi.mock('../lib/navigation', () => ({ navigateTo: vi.fn() }))
vi.mock('../api/health', () => ({ fetchHealth: () => new Promise(() => {}) }))

async function stubView(name: 'categories' | 'login' | 'expenses') {
  const { defineComponent, h } = await import('vue')
  return {
    default: defineComponent({
      setup() {
        mounted[name]()
        return () => h('h1', name)
      },
    }),
  }
}
vi.mock('../views/CategoriesView.vue', () => stubView('categories'))
vi.mock('../views/LoginView.vue', () => stubView('login'))
vi.mock('../views/ExpensesView.vue', () => stubView('expenses'))

const user: User = { id: 'u', email: 'a@b.c' }
const signedIn: ApiResult<User> = { ok: true, status: 200, data: user }
const signedOut: ApiResult<User> = { ok: false, error: { kind: 'unauthorized', status: 401 } }
const network: ApiResult<User> = { ok: false, error: { kind: 'network' } }

afterEach(() => {
  Object.values(mounted).forEach((spy) => spy.mockReset())
  api.me.mockReset()
})

async function startUnreachable(path: string) {
  api.me.mockResolvedValueOnce(network)
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = createAppRouter(createMemoryHistory())
  await router.push(path)
  const wrapper = mount(App, { global: { plugins: [pinia, router] } })
  await flushPromises()
  return { router, wrapper }
}

describe('App: Retry', () => {
  it('signed out after Retry: the private view never mounts, and the route ends at login', async () => {
    const { router, wrapper } = await startUnreachable('/categories')
    expect(wrapper.get('h1').text()).toBe("Can't reach the server")
    api.me.mockResolvedValueOnce(signedOut)

    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(mounted.categories).not.toHaveBeenCalled()
    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.redirect).toBe('/categories')
    expect(wrapper.get('h1').text()).toBe('login')
  })

  it('signed in on /login after Retry: the login view never mounts, and the route ends at /expenses', async () => {
    const { router, wrapper } = await startUnreachable('/login')
    api.me.mockResolvedValueOnce(signedIn)

    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(mounted.login).not.toHaveBeenCalled()
    expect(router.currentRoute.value.fullPath).toBe('/expenses')
    expect(wrapper.get('h1').text()).toBe('expenses')
  })

  it('signed in on a private route after Retry: the page appears where it was', async () => {
    const { router, wrapper } = await startUnreachable('/categories')
    api.me.mockResolvedValueOnce(signedIn)

    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(mounted.categories).toHaveBeenCalledTimes(1)
    expect(router.currentRoute.value.fullPath).toBe('/categories')
  })

  it('while unreachable, a navigation keeps its URL and the service view stays', async () => {
    const { router, wrapper } = await startUnreachable('/expenses')

    await router.push('/categories?x=1')
    await flushPromises()

    expect(router.currentRoute.value.fullPath).toBe('/categories?x=1')
    expect(wrapper.get('h1').text()).toBe("Can't reach the server")
    expect(mounted.categories).not.toHaveBeenCalled()
  })

  it('while unreachable, / becomes /expenses and the service view stays', async () => {
    const { router, wrapper } = await startUnreachable('/categories')

    await router.push('/')
    await flushPromises()

    expect(router.currentRoute.value.fullPath).toBe('/expenses')
    expect(wrapper.get('h1').text()).toBe("Can't reach the server")
    expect(mounted.expenses).not.toHaveBeenCalled()
  })
})
