import { APP_CURRENCY, APP_LOCALE, MAX_CENTS, MIN_CENTS, UNKNOWN_VALUE } from '../config'

export type AmountReason =
  'empty' | 'negative' | 'invalid' | 'too-many-decimals' | 'zero' | 'too-large'
export type AmountResult = { ok: true; value: number } | { ok: false; reason: AmountReason }

const CENTS_PER_UNIT = 100
const MAX_DECIMALS = 2
// Explicit [0-9]: no \d (with the u flag or not), so full-width and Arabic-Indic digits never match.
const AMOUNT_PATTERN = /^([0-9]+)(?:[.,]([0-9]+))?$/
// Longest integer part that cannot lose precision in a Number before the range check (MAX_CENTS has 9 digits).
const MAX_WHOLE_DIGITS = 9

/**
 * Reads what a user typed into an amount field, working on the string only (no float). Leading and trailing ASCII
 * spaces are ignored (a paste convenience); anything else around or inside the number is `invalid`.
 */
export function parseAmount(input: string): AmountResult {
  const text = input.replace(/^ +| +$/g, '')
  if (text === '') {
    return { ok: false, reason: 'empty' }
  }
  if (text.startsWith('-')) {
    return { ok: false, reason: 'negative' }
  }
  const match = AMOUNT_PATTERN.exec(text)
  if (match === null) {
    return { ok: false, reason: 'invalid' }
  }
  const [, wholeText = '', fraction = ''] = match
  const whole = wholeText.replace(/^0+(?=[0-9])/, '')
  if (fraction.length > MAX_DECIMALS) {
    return { ok: false, reason: 'too-many-decimals' }
  }
  if (whole.length > MAX_WHOLE_DIGITS) {
    return { ok: false, reason: 'too-large' }
  }
  const cents = Number(whole) * CENTS_PER_UNIT + Number(fraction.padEnd(MAX_DECIMALS, '0'))
  if (cents < MIN_CENTS) {
    return { ok: false, reason: 'zero' }
  }
  return cents > MAX_CENTS ? { ok: false, reason: 'too-large' } : { ok: true, value: cents }
}

function isValidCents(cents: number): boolean {
  return Number.isInteger(cents) && cents >= MIN_CENTS && cents <= MAX_CENTS
}

/** The exact decimal string of a valid amount, built from the integer: 1005 gives "10.05". */
function decimalString(cents: number): `${number}` {
  const whole = Math.floor(cents / CENTS_PER_UNIT)
  const fraction = String(cents % CENTS_PER_UNIT).padStart(MAX_DECIMALS, '0')
  // Digits, a dot and digits: a numeric string, as NumberFormat.format(string) (ES2023.Intl) wants.
  return `${whole}.${fraction}` as `${number}`
}

// Created once at load. NumberFormat reads no time zone, so there is nothing to go stale.
const currencyFormatter = new Intl.NumberFormat(APP_LOCALE, {
  style: 'currency',
  currency: APP_CURRENCY,
})

/**
 * Display text such as "€10.05". The exact decimal string is handed to NumberFormat (ECMA-402 v3, Step 0 R4), so no
 * float is involved. Cents that are not an integer in MIN_CENTS..MAX_CENTS give UNKNOWN_VALUE (never a throw).
 */
export function formatCents(cents: number): string {
  return isValidCents(cents) ? currencyFormatter.format(decimalString(cents)) : UNKNOWN_VALUE
}

/** The text to pre-fill an edit field with: 1250 gives "12.50". Invalid cents give "", so the field starts blank. */
export function centsToInput(cents: number): string {
  return isValidCents(cents) ? decimalString(cents) : ''
}
