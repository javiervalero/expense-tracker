import { describe, expect, it } from 'vitest'

import { RECEIPT_MAX_BYTES, RECEIPT_TYPES } from '../../config'
import { checkReceiptFile, isReceiptType } from '../receiptFile'

describe('checkReceiptFile', () => {
  it.each(RECEIPT_TYPES)('accepts %s of a normal size', (type) => {
    expect(checkReceiptFile({ type, size: 1234 })).toEqual({ ok: true, contentType: type })
  })

  it('refuses a 0-byte file as empty', () => {
    expect(checkReceiptFile({ type: 'image/png', size: 0 })).toEqual({ ok: false, reason: 'empty' })
  })

  it('accepts a file of exactly RECEIPT_MAX_BYTES', () => {
    expect(checkReceiptFile({ type: 'application/pdf', size: RECEIPT_MAX_BYTES })).toEqual({
      ok: true,
      contentType: 'application/pdf',
    })
  })

  it('refuses a file one byte over RECEIPT_MAX_BYTES as too-large', () => {
    expect(checkReceiptFile({ type: 'application/pdf', size: RECEIPT_MAX_BYTES + 1 })).toEqual({
      ok: false,
      reason: 'too-large',
    })
  })

  it('accepts a 1-byte file', () => {
    expect(checkReceiptFile({ type: 'image/webp', size: 1 })).toEqual({
      ok: true,
      contentType: 'image/webp',
    })
  })

  it.each(['image/svg+xml', 'image/gif', 'image/heic', 'text/html', ''])(
    'refuses the type %j as type',
    (type) => {
      expect(checkReceiptFile({ type, size: 100 })).toEqual({ ok: false, reason: 'type' })
    },
  )

  it('refuses an upper-case IMAGE/JPEG as type: the browser reports lower case', () => {
    expect(checkReceiptFile({ type: 'IMAGE/JPEG', size: 100 })).toEqual({
      ok: false,
      reason: 'type',
    })
  })

  it('reports type before empty when both fail', () => {
    expect(checkReceiptFile({ type: 'image/gif', size: 0 })).toEqual({ ok: false, reason: 'type' })
  })

  it('reports type before too-large when both fail', () => {
    expect(checkReceiptFile({ type: 'image/gif', size: RECEIPT_MAX_BYTES + 1 })).toEqual({
      ok: false,
      reason: 'type',
    })
  })
})

describe('isReceiptType', () => {
  it.each(RECEIPT_TYPES)('accepts %s', (type) => {
    expect(isReceiptType(type)).toBe(true)
  })

  it.each(['image/gif', 'IMAGE/JPEG', '', 'image/jpeg ', 'constructor', '__proto__'])(
    'refuses %j',
    (type) => {
      expect(isReceiptType(type)).toBe(false)
    },
  )
})
