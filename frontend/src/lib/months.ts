import { APP_LOCALE, MONTH_MAX, MONTH_MIN, UNKNOWN_VALUE } from '../config'

// Explicit [0-9]: full-width digits never match.
const ISO_MONTH_PATTERN = /^[0-9]{4}-[0-9]{2}$/
const MONTHS_PER_YEAR = 12

function pad(value: number, width: number): string {
  return String(value).padStart(width, '0')
}

/** A month written YYYY-MM, from MONTH_MIN to MONTH_MAX (so the month part is 01 to 12). */
export function isIsoMonth(s: string): boolean {
  if (!ISO_MONTH_PATTERN.test(s) || s < MONTH_MIN || s > MONTH_MAX) {
    return false
  }
  const month = Number(s.slice(5, 7))
  return month >= 1 && month <= MONTHS_PER_YEAR
}

/**
 * The user's local current month, as YYYY-MM, kept within MONTH_MIN..MONTH_MAX so a wrong device clock still gives a
 * month the report accepts. Never `toISOString()`, which is UTC.
 */
export function currentMonthLocal(now: Date = new Date()): string {
  const local = `${pad(now.getFullYear(), 4)}-${pad(now.getMonth() + 1, 2)}`
  if (local < MONTH_MIN) {
    return MONTH_MIN
  }
  return local > MONTH_MAX ? MONTH_MAX : local
}

/** The month before (-1) or after (+1), kept within MONTH_MIN..MONTH_MAX. The input must be a valid month. */
export function shiftMonth(month: string, step: -1 | 1): string {
  const index = Number(month.slice(0, 4)) * MONTHS_PER_YEAR + (Number(month.slice(5, 7)) - 1) + step
  const year = pad(Math.floor(index / MONTHS_PER_YEAR), 4)
  const shifted = `${year}-${pad((index % MONTHS_PER_YEAR) + 1, 2)}`
  if (shifted < MONTH_MIN) {
    return MONTH_MIN
  }
  return shifted > MONTH_MAX ? MONTH_MAX : shifted
}

// Created once at load. timeZone 'UTC' pins it: the instant below is built in UTC, so the month never shifts.
const monthFormatter = new Intl.DateTimeFormat(APP_LOCALE, {
  month: 'long',
  year: 'numeric',
  timeZone: 'UTC',
})

/** "October 2026" for "2026-10", the same in every time zone. An invalid month gives UNKNOWN_VALUE. */
export function formatMonth(month: string): string {
  if (!isIsoMonth(month)) {
    return UNKNOWN_VALUE
  }
  const instant = Date.UTC(Number(month.slice(0, 4)), Number(month.slice(5, 7)) - 1, 1)
  return monthFormatter.format(new Date(instant))
}

/** The first and last day of a month as YYYY-MM-DD (leap years included), or null for an invalid month. */
export function monthRange(month: string): { from: string; to: string } | null {
  if (!isIsoMonth(month)) {
    return null
  }
  // Day 0 of the next month is the last day of this one: the calendar (leap years included) does the counting.
  const end = new Date(Date.UTC(Number(month.slice(0, 4)), Number(month.slice(5, 7)), 0))
  const lastDay = end.getUTCDate()
  return { from: `${month}-01`, to: `${month}-${pad(lastDay, 2)}` }
}
