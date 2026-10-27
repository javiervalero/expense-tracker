import { describe, expect, it } from 'vitest'

import { MAX_RETRY_AFTER_SECONDS, parseRetryAfter } from '../retryAfter'

describe('parseRetryAfter', () => {
  it.each([
    ['120', 120],
    ['1', 1],
    ['0007', 7],
    [String(MAX_RETRY_AFTER_SECONDS), MAX_RETRY_AFTER_SECONDS],
  ])('reads %j as %d seconds', (raw, expected) => {
    expect(parseRetryAfter(raw)).toBe(expected)
  })

  it.each([
    ['zero', '0'],
    ['negative', '-5'],
    ['text', 'abc'],
    ['decimal', '1.5'],
    ['http-date', 'Wed, 21 Oct 2026 07:28:00 GMT'],
    ['empty', ''],
    ['padded with spaces', ' 5 '],
    ['plus sign', '+5'],
    ['exponent', '1e3'],
    ['hex', '0x10'],
    ['non-ASCII digits', '١٢'],
    ['one over the cap', String(MAX_RETRY_AFTER_SECONDS + 1)],
    ['a huge number', '9'.repeat(400)],
  ])('gives null for %s', (_name, raw) => {
    expect(parseRetryAfter(raw)).toBeNull()
  })

  it('gives null when the header is missing', () => {
    expect(parseRetryAfter(null)).toBeNull()
  })
})
