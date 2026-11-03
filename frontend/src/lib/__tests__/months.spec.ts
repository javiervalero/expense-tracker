import { afterEach, describe, expect, it } from 'vitest'

import { UNKNOWN_VALUE } from '../../config'
import { currentMonthLocal, formatMonth, isIsoMonth, monthRange, shiftMonth } from '../months'

const originalTz = process.env.TZ

afterEach(() => {
  if (originalTz === undefined) {
    delete process.env.TZ
  } else {
    process.env.TZ = originalTz
  }
})

describe('isIsoMonth', () => {
  it.each(['2000-01', '2026-10', '2099-12', '2026-01', '2026-12'])('accepts %j', (month) => {
    expect(isIsoMonth(month)).toBe(true)
  })

  it.each([
    '2026-00',
    '2026-13',
    '1999-12',
    '2100-01',
    '2026-1',
    '26-10',
    '2026-10-01',
    ' 2026-10',
    '2026/10',
    '',
    '２０２６-１０',
    '2026-１0',
  ])('rejects %j', (month) => {
    expect(isIsoMonth(month)).toBe(false)
  })
})

describe('currentMonthLocal', () => {
  // These two zones are chosen so that a UTC-based answer (toISOString) would be a different month.
  it('is already the next month just after local midnight east of UTC (UTC is still the old month)', () => {
    process.env.TZ = 'Pacific/Kiritimati'
    expect(currentMonthLocal(new Date(2026, 10, 1, 0, 1))).toBe('2026-11')
  })

  it('is still the old month late on the last day west of UTC (UTC is already the next month)', () => {
    process.env.TZ = 'Pacific/Pago_Pago'
    expect(currentMonthLocal(new Date(2026, 9, 31, 23, 59))).toBe('2026-10')
  })

  it('reads the local year at the end of a year', () => {
    process.env.TZ = 'Pacific/Pago_Pago'
    expect(currentMonthLocal(new Date(2026, 11, 31, 23, 59))).toBe('2026-12')
  })

  it('stays inside the supported months when the device clock is wrong', () => {
    expect(currentMonthLocal(new Date(1999, 5, 1))).toBe('2000-01')
    expect(currentMonthLocal(new Date(2100, 0, 1))).toBe('2099-12')
  })

  it('defaults to now and returns a valid month', () => {
    expect(isIsoMonth(currentMonthLocal())).toBe(true)
  })
})

describe('shiftMonth', () => {
  it.each([
    ['2026-10', 1, '2026-11'],
    ['2026-10', -1, '2026-09'],
    ['2026-12', 1, '2027-01'],
    ['2026-01', -1, '2025-12'],
  ] as const)('%s shifted by %i is %s', (month, step, expected) => {
    expect(shiftMonth(month, step)).toBe(expected)
  })

  it('stays at the first month going back from 2000-01', () => {
    expect(shiftMonth('2000-01', -1)).toBe('2000-01')
  })

  it('stays at the last month going forward from 2099-12', () => {
    expect(shiftMonth('2099-12', 1)).toBe('2099-12')
  })
})

describe('formatMonth', () => {
  it('writes the long month name and year', () => {
    expect(formatMonth('2026-10')).toBe('October 2026')
  })

  it('is the same in every time zone', () => {
    process.env.TZ = 'Pacific/Kiritimati'
    expect(formatMonth('2026-01')).toBe('January 2026')
    process.env.TZ = 'Pacific/Pago_Pago'
    expect(formatMonth('2026-12')).toBe('December 2026')
  })

  it('gives UNKNOWN_VALUE for an invalid month', () => {
    expect(formatMonth('2026-13')).toBe(UNKNOWN_VALUE)
  })
})

describe('monthRange', () => {
  it.each([
    ['2026-10', '2026-10-01', '2026-10-31'],
    ['2026-04', '2026-04-01', '2026-04-30'],
    ['2026-02', '2026-02-01', '2026-02-28'],
    ['2028-02', '2028-02-01', '2028-02-29'],
    ['2000-02', '2000-02-01', '2000-02-29'],
    ['2099-12', '2099-12-01', '2099-12-31'],
  ])('%s runs from %s to %s', (month, from, to) => {
    expect(monthRange(month)).toEqual({ from, to })
  })

  it('gives null for an invalid month', () => {
    expect(monthRange('2026-13')).toBeNull()
  })
})
