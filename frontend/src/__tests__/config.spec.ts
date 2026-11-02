import { describe, expect, it } from 'vitest'

import * as config from '../config'

describe('receipt config constants', () => {
  it('declares the receipt and upload limits of plan C17 and C18', () => {
    expect(config.RECEIPT_MAX_BYTES).toBe(10_485_760)
    expect(config.RECEIPT_TYPES).toEqual([
      'image/jpeg',
      'image/png',
      'image/webp',
      'application/pdf',
    ])
    expect(config.POLL_INITIAL_MS).toBe(1_000)
    expect(config.POLL_FACTOR).toBe(1.5)
    expect(config.POLL_MAX_MS).toBe(5_000)
    expect(config.POLL_TOTAL_CAP_MS).toBe(60_000)
    expect(config.UPLOAD_TIMEOUT_MS).toBe(120_000)
    expect(config.DEV_UPLOAD_ORIGIN).toBe('http://localhost:9000')
  })
})

describe('receipt view address refresh', () => {
  it('refreshes a signed address when it expires within 60 seconds', () => {
    expect(config.URL_REFRESH_MARGIN_MS).toBe(60_000)
  })
})
