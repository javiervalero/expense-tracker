import { POLL_FACTOR, POLL_INITIAL_MS, POLL_MAX_MS, POLL_TOTAL_CAP_MS } from '../config'

/**
 * Milliseconds to wait before poll number `attempt` (0 is the first): 1 s, growing 1.5x per try, never above 5 s.
 * A negative or NaN attempt counts as the first; a fraction rounds down.
 */
export function nextPollDelayMs(attempt: number): number {
  const steps = attempt >= 0 ? Math.floor(attempt) : 0
  return Math.min(POLL_MAX_MS, POLL_INITIAL_MS * POLL_FACTOR ** steps)
}

/** True once `elapsedMs` of polling has reached the total cap: the caller stops and shows "still processing". */
export function pollCapReached(elapsedMs: number): boolean {
  return elapsedMs >= POLL_TOTAL_CAP_MS
}
