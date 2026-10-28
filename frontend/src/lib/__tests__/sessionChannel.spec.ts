import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { announceSignedIn, announceSignedOut, installSessionChannel } from '../sessionChannel'

const nav = vi.hoisted(() => ({
  navigateTo: vi.fn<(url: string) => void>(),
  reload: vi.fn<() => void>(),
}))
vi.mock('@/lib/navigation', () => nav)

const MINE = '3f2b8c1e-5d4a-4b7c-9e1f-0a1b2c3d4e5f'
const OTHER = '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d'

class FakeChannel {
  static instances: FakeChannel[] = []
  readonly posted: unknown[] = []
  closed = false
  private listeners: ((event: { data: unknown }) => void)[] = []
  constructor(readonly name: string) {
    FakeChannel.instances.push(this)
  }
  addEventListener(_type: string, listener: (event: { data: unknown }) => void): void {
    this.listeners.push(listener)
  }
  removeEventListener(_type: string, listener: (event: { data: unknown }) => void): void {
    this.listeners = this.listeners.filter((l) => l !== listener)
  }
  postMessage(data: unknown): void {
    this.posted.push(data)
  }
  close(): void {
    this.closed = true
  }
  receive(data: unknown): void {
    this.listeners.forEach((l) => l({ data }))
  }
}

let currentId: string | undefined
let dispose: () => void

beforeEach(() => {
  FakeChannel.instances = []
  currentId = MINE
  vi.stubGlobal('BroadcastChannel', FakeChannel)
  dispose = installSessionChannel(() => currentId)
})
afterEach(() => {
  dispose()
  vi.unstubAllGlobals()
  nav.navigateTo.mockReset()
  nav.reload.mockReset()
})

const channel = () => FakeChannel.instances[0]!

describe('session channel: receiving', () => {
  it('opens one channel with the fixed name', () => {
    expect(FakeChannel.instances).toHaveLength(1)
    expect(channel().name).toBe('expense-tracker-session')
  })

  it('goes to /login on signed-out', () => {
    channel().receive({ type: 'signed-out' })

    expect(nav.navigateTo).toHaveBeenCalledExactlyOnceWith('/login')
    expect(nav.reload).not.toHaveBeenCalled()
  })

  it('reloads on signed-in with another id', () => {
    channel().receive({ type: 'signed-in', id: OTHER })

    expect(nav.reload).toHaveBeenCalledOnce()
    expect(nav.navigateTo).not.toHaveBeenCalled()
  })

  it('does not navigate on signed-out when this tab has no user (a half-typed form survives)', () => {
    currentId = undefined

    channel().receive({ type: 'signed-out' })

    expect(nav.navigateTo).not.toHaveBeenCalled()
    expect(nav.reload).not.toHaveBeenCalled()
  })

  it('reloads on signed-in when this tab has no user', () => {
    currentId = undefined

    channel().receive({ type: 'signed-in', id: OTHER })

    expect(nav.reload).toHaveBeenCalledOnce()
  })

  it('does nothing on signed-in with the same id', () => {
    channel().receive({ type: 'signed-in', id: MINE })

    expect(nav.reload).not.toHaveBeenCalled()
    expect(nav.navigateTo).not.toHaveBeenCalled()
  })

  it.each([
    ['null', null],
    ['a string', 'signed-out'],
    ['a number', 7],
    ['an array', ['signed-out']],
    ['an empty object', {}],
    ['an unknown type', { type: 'hello' }],
    ['signed-in without id', { type: 'signed-in' }],
    ['signed-in with a numeric id', { type: 'signed-in', id: 5 }],
    ['signed-in with an upper-case id', { type: 'signed-in', id: OTHER.toUpperCase() }],
    ['signed-in with a v1 uuid', { type: 'signed-in', id: '9b1deb4d-3b7d-1bad-9bdd-2b0d7b3dcb6d' }],
    ['signed-in with free text', { type: 'signed-in', id: '/login?x=1' }],
    ['signed-out with an extra field', { type: 'signed-out', id: OTHER }],
    ['signed-in with an extra field', { type: 'signed-in', id: OTHER, next: '/x' }],
  ])('ignores %s', (_name, data) => {
    channel().receive(data)

    expect(nav.reload).not.toHaveBeenCalled()
    expect(nav.navigateTo).not.toHaveBeenCalled()
  })

  it('stops listening and closes the channel when disposed', () => {
    dispose()

    channel().receive({ type: 'signed-out' })

    expect(channel().closed).toBe(true)
    expect(nav.navigateTo).not.toHaveBeenCalled()
  })
})

describe('session channel: posting', () => {
  it('posts signed-out', () => {
    announceSignedOut()

    expect(channel().posted).toEqual([{ type: 'signed-out' }])
  })

  it('posts signed-in with the id', () => {
    announceSignedIn(MINE)

    expect(channel().posted).toEqual([{ type: 'signed-in', id: MINE }])
  })

  it('posts nothing after dispose', () => {
    dispose()

    announceSignedOut()

    expect(channel().posted).toEqual([])
  })
})

describe('session channel: no BroadcastChannel', () => {
  it('does nothing and does not throw', () => {
    dispose()
    vi.stubGlobal('BroadcastChannel', undefined)

    const off = installSessionChannel(() => MINE)

    expect(() => {
      announceSignedOut()
      announceSignedIn(MINE)
      off()
    }).not.toThrow()
  })

  it('survives a constructor that throws', () => {
    dispose()
    vi.stubGlobal(
      'BroadcastChannel',
      class {
        constructor() {
          throw new Error('denied')
        }
      },
    )

    expect(() => installSessionChannel(() => MINE)()).not.toThrow()
  })
})
