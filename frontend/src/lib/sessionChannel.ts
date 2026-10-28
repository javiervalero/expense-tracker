import { navigateTo, reload } from '@/lib/navigation'

const CHANNEL_NAME = 'expense-tracker-session'
// A lower-case v4 uuid, the only shape the API gives user ids.
const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

type SessionMessage = { type: 'signed-out' } | { type: 'signed-in'; id: string }

// The one channel of this app, set by `installSessionChannel`. Nothing is stored: a message is only a signal (plan
// phase-4 C3), and the receiving tab acts on it by asking the server again after a page load.
let channel: BroadcastChannel | null = null

/** Only the two known shapes, with no extra field; anything else is `null` and gets ignored. */
function parse(data: unknown): SessionMessage | null {
  if (typeof data !== 'object' || data === null || Array.isArray(data)) {
    return null
  }
  const keys = Object.keys(data)
  const { type, id } = data as { type?: unknown; id?: unknown }
  if (type === 'signed-out' && keys.length === 1) {
    return { type }
  }
  if (type === 'signed-in' && keys.length === 2 && typeof id === 'string' && UUID_V4.test(id)) {
    return { type, id }
  }
  return null
}

/**
 * Makes this tab follow a sign-out or a different sign-in made in another tab. Call it once, from main.ts. Returns a
 * function that stops it (for tests). Without BroadcastChannel it does nothing.
 */
export function installSessionChannel(currentUserId: () => string | undefined): () => void {
  if (typeof BroadcastChannel === 'undefined') {
    return () => {}
  }
  let opened: BroadcastChannel
  try {
    opened = new BroadcastChannel(CHANNEL_NAME)
  } catch {
    return () => {}
  }
  const onMessage = (event: MessageEvent<unknown>): void => {
    const message = parse(event.data)
    if (message === null) {
      return
    }
    if (message.type === 'signed-out') {
      // A tab with no user (a login or register page) has nothing to protect, and a reload would drop a half-typed form.
      if (currentUserId() !== undefined) {
        navigateTo('/login')
      }
    } else if (message.id !== currentUserId()) {
      reload()
    }
  }
  opened.addEventListener('message', onMessage)
  channel = opened
  return () => {
    opened.removeEventListener('message', onMessage)
    opened.close()
    if (channel === opened) {
      channel = null
    }
  }
}

/** Tells the other tabs that this one signed out. A no-op when the channel is not installed. */
export function announceSignedOut(): void {
  channel?.postMessage({ type: 'signed-out' } satisfies SessionMessage)
}

/** Tells the other tabs that this one signed in as `id`. A no-op when the channel is not installed. */
export function announceSignedIn(id: string): void {
  channel?.postMessage({ type: 'signed-in', id } satisfies SessionMessage)
}
