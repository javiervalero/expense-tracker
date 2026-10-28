/** The longest wait we believe: one day. A larger value is more likely a mistake than a real wait. */
export const MAX_RETRY_AFTER_SECONDS = 86_400

const ASCII_DIGITS = /^[0-9]+$/

/**
 * Reads a `Retry-After` header as a whole number of seconds from 1 to MAX_RETRY_AFTER_SECONDS.
 * Anything else (missing, empty, "0", a sign, a decimal, an HTTP-date, spaces, too large) gives null, which the UI
 * shows as "try again later". The API only sends delta-seconds.
 */
export function parseRetryAfter(raw: string | null): number | null {
  if (raw === null || !ASCII_DIGITS.test(raw)) {
    return null
  }
  const seconds = Number(raw)
  return seconds >= 1 && seconds <= MAX_RETRY_AFTER_SECONDS ? seconds : null
}
