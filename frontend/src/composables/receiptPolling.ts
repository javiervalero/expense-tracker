import { nextPollDelayMs, pollCapReached } from '@/lib/backoff'

/** What the poller needs from its owner. It knows nothing about receipts, phases or messages. */
export interface PollerOptions<Answer> {
  /** One request. It must honour the signal. */
  fetchOnce: (signal: AbortSignal) => Promise<Answer>
  /** Called with each current answer. Return true to keep polling, false when polling is over. */
  onAnswer: (answer: Answer) => boolean
  /** Called once when the visible-time budget ran out while the answers still said "keep polling". */
  onBudgetSpent: () => void
  /** Called with whatever `fetchOnce` or `onAnswer` threw, once the poller has halted. A superseded request is silent. */
  onError: (error: unknown) => void
}

export interface Poller {
  /** Starts a session: a fresh budget, the first request after the first delay. */
  begin: () => void
  /** Starts a session with a fresh budget and sends the first request at once. */
  restart: () => Promise<void>
  /** Ends the session: timer cleared, request aborted, visibility listener removed. Its late answer changes nothing. */
  halt: () => void
}

/**
 * The polling schedule of plan phase-5 C18: backoff delays (lib/backoff), a total budget counted in waiting time that
 * passed while the tab was visible, and a pause while the tab is hidden. A hidden tab sends nothing: when a timer fires
 * and the tab is hidden, polling parks until the next `visibilitychange`, which polls at once. Each request carries its
 * own AbortSignal, and an answer from an older request (or an older session) is dropped.
 */
export function createPoller<Answer>(options: PollerOptions<Answer>): Poller {
  // Bumped by every new request and by `halt`: an answer that finds another value is stale.
  let token = 0
  let timer: ReturnType<typeof setTimeout> | undefined
  let controller: AbortController | null = null
  let attempt = 0
  let visibleWaitMs = 0

  function cancel(): number {
    token += 1
    clearTimeout(timer)
    timer = undefined
    controller?.abort()
    controller = null
    return token
  }

  function halt(): void {
    cancel()
    document.removeEventListener('visibilitychange', wake)
  }

  // The owner's callbacks should not throw (getReceipt never does). If one does, the poller halts so that no timer or
  // listener outlives the failure and tells the owner through `onError`; it never rethrows, so a timer-driven request
  // cannot become an unhandled rejection. A request that was superseded meanwhile is silent and leaves the newer session alone.
  async function request(): Promise<void> {
    const mine = cancel()
    const own = new AbortController()
    controller = own
    try {
      const answer = await options.fetchOnce(own.signal)
      if (mine !== token) {
        return
      }
      proceed(answer)
    } catch (error: unknown) {
      if (mine !== token) {
        return
      }
      halt()
      options.onError(error)
    }
  }

  function proceed(answer: Answer): void {
    if (!options.onAnswer(answer)) {
      halt()
      return
    }
    if (pollCapReached(visibleWaitMs)) {
      halt()
      options.onBudgetSpent()
      return
    }
    schedule()
  }

  // A timer or the tab showing again: a hidden tab sends nothing and stays parked until it is shown.
  function tick(waitedMs: number): void {
    if (document.visibilityState === 'hidden') {
      return
    }
    visibleWaitMs += waitedMs
    void request()
  }

  function wake(): void {
    tick(0)
  }

  function schedule(): void {
    const delay = nextPollDelayMs(attempt)
    attempt += 1
    timer = setTimeout(() => tick(delay), delay)
  }

  function reset(): void {
    attempt = 0
    visibleWaitMs = 0
    document.addEventListener('visibilitychange', wake)
  }

  return {
    begin: () => {
      cancel()
      reset()
      schedule()
    },
    restart: () => {
      reset()
      return request()
    },
    halt,
  }
}
