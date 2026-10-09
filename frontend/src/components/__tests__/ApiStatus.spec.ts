import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { HealthResult } from '@/api/health'
import ApiStatus from '../ApiStatus.vue'

const fetchHealthMock = vi.hoisted(() => vi.fn<(signal?: AbortSignal) => Promise<HealthResult>>())

vi.mock('@/api/health', () => ({ fetchHealth: fetchHealthMock }))

afterEach(() => {
  fetchHealthMock.mockReset()
})

describe('ApiStatus', () => {
  it('shows a loading message while the health check is pending', () => {
    fetchHealthMock.mockReturnValue(new Promise(() => {}))

    const wrapper = mount(ApiStatus)

    expect(wrapper.text()).toBe('API: checking...')
  })

  it('shows "API: ok" when the API is healthy', async () => {
    fetchHealthMock.mockResolvedValue({ kind: 'ok' })

    const wrapper = mount(ApiStatus)
    await flushPromises()

    expect(wrapper.text()).toBe('API: ok')
  })

  it('shows an error message when the health check fails', async () => {
    fetchHealthMock.mockResolvedValue({ kind: 'error', reason: 'network' })

    const wrapper = mount(ApiStatus)
    await flushPromises()

    expect(wrapper.text()).toContain('API: unavailable')
    expect(wrapper.text()).not.toContain('API: ok')
  })

  it('announces the loading and ok states politely, without a redundant aria-live', async () => {
    fetchHealthMock.mockResolvedValue({ kind: 'ok' })

    const wrapper = mount(ApiStatus)
    expect(wrapper.attributes('role')).toBe('status')
    await flushPromises()

    expect(wrapper.attributes('role')).toBe('status')
    expect(wrapper.attributes('aria-live')).toBeUndefined()
  })

  it('announces the error state assertively as an alert', async () => {
    fetchHealthMock.mockResolvedValue({ kind: 'error', reason: 'timeout' })

    const wrapper = mount(ApiStatus)
    await flushPromises()

    expect(wrapper.attributes('role')).toBe('alert')
    expect(wrapper.attributes('aria-live')).toBeUndefined()
  })

  it('marks the element with the state so that styles and tests can tell them apart', async () => {
    fetchHealthMock.mockResolvedValue({ kind: 'error', reason: 'http-status', status: 500 })

    const wrapper = mount(ApiStatus)
    expect(wrapper.attributes('data-state')).toBe('loading')
    await flushPromises()

    expect(wrapper.attributes('data-state')).toBe('error')
  })

  it('gives the health check a signal that is not aborted while the component is mounted', () => {
    fetchHealthMock.mockReturnValue(new Promise(() => {}))

    mount(ApiStatus)

    const signal = fetchHealthMock.mock.calls[0]?.[0]
    expect(signal).toBeInstanceOf(AbortSignal)
    expect(signal?.aborted).toBe(false)
  })

  it('aborts the health check when the component is unmounted', () => {
    fetchHealthMock.mockReturnValue(new Promise(() => {}))
    const wrapper = mount(ApiStatus)
    const signal = fetchHealthMock.mock.calls[0]?.[0]

    wrapper.unmount()

    expect(signal?.aborted).toBe(true)
  })

  it('ignores an aborted result instead of showing it as an error', async () => {
    fetchHealthMock.mockResolvedValue({ kind: 'error', reason: 'aborted' })

    const wrapper = mount(ApiStatus)
    await flushPromises()

    expect(wrapper.attributes('data-state')).toBe('loading')
  })

  it('does not update after being unmounted when the result arrives late', async () => {
    let resolveHealth: (result: HealthResult) => void = () => {}
    fetchHealthMock.mockReturnValue(
      new Promise<HealthResult>((resolve) => {
        resolveHealth = resolve
      }),
    )
    const wrapper = mount(ApiStatus)
    wrapper.unmount()

    resolveHealth({ kind: 'ok' })
    await flushPromises()

    expect(wrapper.attributes('data-state')).toBe('loading')
  })
})
