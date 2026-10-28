import { afterEach, describe, expect, it, vi } from 'vitest'

import { UNKNOWN_VALUE } from '../../config'

import { formatDate, isIsoDate, todayLocal } from '../dates'

const originalTz = process.env.TZ

afterEach(() => {
  if (originalTz === undefined) {
    delete process.env.TZ
  } else {
    process.env.TZ = originalTz
  }
})

describe('todayLocal', () => {
  it('uses the local calendar at 00:30 in UTC+14, when UTC is still the day before', () => {
    process.env.TZ = 'Pacific/Kiritimati'
    const instant = new Date(Date.UTC(2026, 9, 25, 10, 30))
    expect(instant.toISOString().slice(0, 10)).toBe('2026-10-25')
    expect(todayLocal(instant)).toBe('2026-10-26')
  })

  it('uses the local calendar at 23:30 in UTC-11, when UTC is already the day after', () => {
    process.env.TZ = 'Pacific/Pago_Pago'
    const instant = new Date(Date.UTC(2026, 9, 27, 10, 30))
    expect(instant.toISOString().slice(0, 10)).toBe('2026-10-27')
    expect(todayLocal(instant)).toBe('2026-10-26')
  })

  it('pads month and day to two digits', () => {
    process.env.TZ = 'UTC'
    expect(todayLocal(new Date(Date.UTC(2026, 0, 5, 12)))).toBe('2026-01-05')
  })

  it('defaults to the current time', () => {
    expect(todayLocal()).toMatch(/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/)
  })
})

describe('isIsoDate', () => {
  it.each(['2024-02-29', '2000-01-01', '2099-12-31', '2000-02-29', '2026-04-30'])(
    'accepts %s',
    (s) => {
      expect(isIsoDate(s)).toBe(true)
    },
  )

  it.each([
    '2023-02-29',
    '2100-02-29',
    '2026-04-31',
    '2026-1-05',
    '1999-12-31',
    '2100-01-01',
    '2026-13-01',
    '2026-00-10',
    '2026-10-00',
    '2026-10-32',
    '2026-10-26T00:00',
    '',
    ' 2026-10-26',
    '２０２６-10-26',
  ])('refuses %j', (s) => {
    expect(isIsoDate(s)).toBe(false)
  })
})

describe('formatDate', () => {
  // The module (and its cached formatter) is created under each zone, so a formatter without timeZone: 'UTC' fails.
  it.each(['Pacific/Kiritimati', 'Pacific/Pago_Pago', 'America/Los_Angeles', 'UTC'])(
    'gives 26 Oct 2026 under %s',
    async (tz) => {
      process.env.TZ = tz
      vi.resetModules()
      const { formatDate: formatDateUnderZone } = await import('../dates')
      expect(formatDateUnderZone('2026-10-26')).toBe('26 Oct 2026')
    },
  )

  it('falls back to the unknown-value placeholder for an invalid date', () => {
    expect(formatDate('2026-02-30')).toBe(UNKNOWN_VALUE)
  })
})
