import { describe, expect, it } from 'vitest'

import { formatReceiptSize } from '../receiptSize'

// Base 1024, labelled B, KB and MB (the owner-approved wording calls 10 MiB "10 MB"); one decimal at most.
describe('formatReceiptSize', () => {
  it.each([
    [1, '1 B'],
    [8, '8 B'],
    [1023, '1023 B'],
    [1024, '1 KB'],
    [1126, '1.1 KB'],
    [1536, '1.5 KB'],
    [1_000_000, '976.6 KB'],
    [1_048_576, '1 MB'],
    [1_258_291, '1.2 MB'],
    [1_572_864, '1.5 MB'],
    [10_485_760, '10 MB'],
    [20_971_520, '20 MB'],
  ])('writes %d bytes as "%s"', (bytes, text) => {
    expect(formatReceiptSize(bytes)).toBe(text)
  })

  // The rounded KB value is computed first; when it reaches 1024 the size is written in MB, never "1024 KB".
  it.each([
    [1_048_063, '1023.5 KB'],
    [1_048_524, '1023.9 KB'],
    [1_048_525, '1 MB'],
    [1_048_575, '1 MB'],
    // 10 MiB minus one byte rounds to the limit, so it reads "10 MB" (the same as the limit itself).
    [10_485_759, '10 MB'],
  ])('writes %d bytes at the KB/MB boundary as "%s"', (bytes, text) => {
    expect(formatReceiptSize(bytes)).toBe(text)
  })

  // The same idea at B/KB (only a fractional size can get here): it never reads "1024 B".
  it.each([
    [1023.4, '1023 B'],
    [1023.5, '1 KB'],
    [1023.96, '1 KB'],
  ])('writes %d bytes at the B/KB boundary as "%s"', (bytes, text) => {
    expect(formatReceiptSize(bytes)).toBe(text)
  })

  it('never ends in ".0"', () => {
    for (const bytes of [1024, 2048, 1_048_576, 5_242_880, 10_485_760]) {
      expect(formatReceiptSize(bytes)).not.toMatch(/\.0\b/)
    }
  })

  it.each([0, -1, -1024, Number.NaN, Number.POSITIVE_INFINITY, Number.NEGATIVE_INFINITY])(
    'gives an empty text for %s',
    (bytes) => {
      expect(formatReceiptSize(bytes)).toBe('')
    },
  )
})
