import { describe, expect, it, vi } from 'vitest'

import { installBfcacheGuard } from '../bfcache'

type Listener = (event: { persisted: boolean }) => void

function fakeWindow() {
  const listeners = new Map<string, Listener>()
  const reload = vi.fn()
  const target = {
    addEventListener: vi.fn((type: string, listener: Listener) => {
      listeners.set(type, listener)
    }),
    location: { reload },
  }
  return {
    target,
    reload,
    fire: (event: { persisted: boolean }) => listeners.get('pageshow')?.(event),
  }
}

describe('installBfcacheGuard', () => {
  it('reloads when the page comes back from the back-forward cache', () => {
    const { target, reload, fire } = fakeWindow()
    installBfcacheGuard(target)

    fire({ persisted: true })

    expect(reload).toHaveBeenCalledTimes(1)
  })

  it('does not reload on an ordinary page show', () => {
    const { target, reload, fire } = fakeWindow()
    installBfcacheGuard(target)

    fire({ persisted: false })

    expect(reload).not.toHaveBeenCalled()
  })

  it('listens for pageshow only', () => {
    const { target } = fakeWindow()

    installBfcacheGuard(target)

    expect(target.addEventListener).toHaveBeenCalledExactlyOnceWith(
      'pageshow',
      expect.any(Function),
    )
  })
})
