const KILO = 1024
const MEGA = KILO * KILO

// One decimal at most, never a trailing ".0" (a number prints without it).
function oneDecimal(value: number): number {
  return Math.round(value * 10) / 10
}

/**
 * A receipt size for people: base 1024 with the labels B, KB and MB (the owner-approved wording calls 10 MiB "10 MB").
 * The rounded value is computed first and the unit switches when it reaches 1024, so "1024 B" and "1024 KB" never
 * appear. An empty text for anything that is not a positive finite number.
 */
export function formatReceiptSize(bytes: number): string {
  if (!Number.isFinite(bytes) || bytes <= 0) {
    return ''
  }
  const wholeBytes = Math.round(bytes)
  if (wholeBytes < KILO) {
    return `${wholeBytes} B`
  }
  const kilobytes = oneDecimal(bytes / KILO)
  return kilobytes < KILO ? `${kilobytes} KB` : `${oneDecimal(bytes / MEGA)} MB`
}
