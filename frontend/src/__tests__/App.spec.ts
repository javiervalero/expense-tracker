import { flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { createMemoryHistory } from 'vue-router'
import { afterEach, describe, expect, it, vi } from 'vitest'

import App from '../App.vue'
import { createAppRouter } from '../router'

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('App', () => {
  it('shows the API status and the home page', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(() => Promise.resolve(new Response(JSON.stringify({ status: 'ok' })))),
    )
    const router = createAppRouter(createMemoryHistory())
    await router.push('/')
    await router.isReady()

    const wrapper = mount(App, { global: { plugins: [createPinia(), router] } })
    await flushPromises()

    expect(wrapper.get('[role="status"]').text()).toBe('API: ok')
    expect(wrapper.get('h1').text()).toBe('Expense tracker')
  })
})
