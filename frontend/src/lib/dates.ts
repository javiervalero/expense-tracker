import { APP_LOCALE, DATE_MAX, DATE_MIN, UNKNOWN_VALUE } from '../config'

const ISO_DATE_PATTERN = /^[0-9]{4}-[0-9]{2}-[0-9]{2}$/

function pad(value: number, width: number): string {
  return String(value).padStart(width, '0')
}

/** Today in the user's local calendar, as YYYY-MM-DD. Never `toISOString()`, which is UTC. */
export function todayLocal(now: Date = new Date()): string {
  return `${pad(now.getFullYear(), 4)}-${pad(now.getMonth() + 1, 2)}-${pad(now.getDate(), 2)}`
}

/** A real calendar date written YYYY-MM-DD, within DATE_MIN..DATE_MAX. The string itself is never given to `Date` to parse. */
export function isIsoDate(s: string): boolean {
  if (!ISO_DATE_PATTERN.test(s) || s < DATE_MIN || s > DATE_MAX) {
    return false
  }
  const year = Number(s.slice(0, 4))
  const month = Number(s.slice(5, 7))
  const day = Number(s.slice(8, 10))
  if (month < 1 || month > 12) {
    return false
  }
  // Day 0 of the next month is the last day of this one: the calendar (leap years included) does the counting.
  const monthLength = new Date(Date.UTC(year, month, 0)).getUTCDate()
  return day >= 1 && day <= monthLength
}

// Created once at load. timeZone 'UTC' pins it: the instant below is built in UTC, so the date never shifts.
const dateFormatter = new Intl.DateTimeFormat(APP_LOCALE, { dateStyle: 'medium', timeZone: 'UTC' })

/** "26 Oct 2026" for "2026-10-26", the same in every time zone. An invalid date gives UNKNOWN_VALUE. */
export function formatDate(s: string): string {
  if (!isIsoDate(s)) {
    return UNKNOWN_VALUE
  }
  const instant = Date.UTC(Number(s.slice(0, 4)), Number(s.slice(5, 7)) - 1, Number(s.slice(8, 10)))
  return dateFormatter.format(new Date(instant))
}
