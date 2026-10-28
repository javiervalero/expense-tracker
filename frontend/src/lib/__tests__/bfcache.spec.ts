import { beforeEach, describe, expect, it, vi } from 'vitest'

import { installBfcacheGuard } from '../bfcache'
import { reload } from '../navigation'

vi.mock('../navigation', () => ({ reload: vi.fn() }))

type Listener = (event: { persisted: boolean }) => void

function fakeWindow() {
  const listeners = new Map<string, Listener>()
  const target = {
    addEventListener: vi.fn((type: string, listener: Listener) => {
      listeners.set(type, listener)
    }),
  }
  return {
    target,
    fire: (event: { persisted: boolean }) => listeners.get('pageshow')?.(event),
  }
}

beforeEach(() => {
  vi.mocked(reload).mockReset()
})

describe('installBfcacheGuard', () => {
  it('reloads, through lib/navigation, when the page comes back from the back-forward cache', () => {
    const { target, fire } = fakeWindow()
    installBfcacheGuard(target)

    fire({ persisted: true })

    expect(reload).toHaveBeenCalledTimes(1)
  })

  it('does not reload on an ordinary page show', () => {
    const { target, fire } = fakeWindow()
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
