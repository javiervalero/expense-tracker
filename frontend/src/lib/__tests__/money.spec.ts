import { describe, expect, it } from 'vitest'

import { MAX_CENTS, UNKNOWN_VALUE } from '../../config'
import { centsToInput, formatCents, parseAmount } from '../money'

describe('parseAmount accepts', () => {
  it.each([
    ['12', 1200],
    ['12.5', 1250],
    ['12,50', 1250],
    ['12.50', 1250],
    ['0.01', 1],
    ['0,1', 10],
    ['1000000.00', 100000000],
    ['1000000', 100000000],
    ['007', 700],
    ['  12.5  ', 1250],
    ['00.50', 50],
    ['0000000000012', 1200],
    ['1,5', 150],
  ])('%j as %i cents', (input, cents) => {
    expect(parseAmount(input)).toEqual({ ok: true, value: cents })
  })
})

describe('parseAmount refuses', () => {
  it.each([
    ['', 'empty'],
    ['   ', 'empty'],
    ['0', 'zero'],
    ['0.00', 'zero'],
    ['000', 'zero'],
    ['-1', 'negative'],
    ['-0.50', 'negative'],
    ['1.234', 'too-many-decimals'],
    ['1,234', 'too-many-decimals'],
    ['1,234.56', 'invalid'],
    ['1e2', 'invalid'],
    ['12.', 'invalid'],
    ['.5', 'invalid'],
    ['1 2', 'invalid'],
    ['\u00A012', 'invalid'],
    ['１２', 'invalid'],
    ['٣', 'invalid'],
    ['abc', 'invalid'],
    ['1000000.01', 'too-large'],
    ['99999999999999999999', 'too-large'],
    ['999999999', 'too-large'],
  ])('%j with reason %s', (input, reason) => {
    expect(parseAmount(input)).toEqual({ ok: false, reason })
  })
})

describe('formatCents', () => {
  it('formats the smallest amount', () => {
    expect(formatCents(1)).toBe('€0.01')
  })

  it('formats the largest amount with a thousands separator', () => {
    expect(formatCents(100000000)).toBe('€1,000,000.00')
  })

  it('formats 1005 as €10.05', () => {
    expect(formatCents(1005)).toBe('€10.05')
  })

  it('falls back to the unknown-value placeholder for invalid cents, without throwing', () => {
    for (const bad of [0, -5, 1.5, MAX_CENTS + 1, Number.NaN, Number.POSITIVE_INFINITY]) {
      expect(formatCents(bad)).toBe(UNKNOWN_VALUE)
    }
  })
})

describe('centsToInput', () => {
  it('pads to two decimals', () => {
    expect(centsToInput(1250)).toBe('12.50')
    expect(centsToInput(1)).toBe('0.01')
    expect(centsToInput(MAX_CENTS)).toBe('1000000.00')
  })

  it('round-trips through parseAmount', () => {
    expect(parseAmount(centsToInput(1005))).toEqual({ ok: true, value: 1005 })
  })

  it('gives an empty field for invalid cents', () => {
    expect(centsToInput(0.5)).toBe('')
    expect(centsToInput(MAX_CENTS + 1)).toBe('')
  })
})
