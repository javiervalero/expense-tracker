import { describe, expect, it } from 'vitest'

import { DESCRIPTION_MAX, NAME_MAX, PASSWORD_MAX, PASSWORD_MIN, Q_MAX } from '../../config'
import {
  checkDescription,
  checkName,
  checkRegisterPassword,
  checkSearch,
  checkSignInPassword,
  codePointLength,
  trimServerWhitespace,
  trimmedLength,
} from '../text'

const NBSP = '\u00A0'
const IDEOGRAPHIC_SPACE = '\u3000'
const ZWJ_FAMILY = '\u{1F468}\u200D\u{1F469}\u200D\u{1F467}' // man ZWJ woman ZWJ girl: 5 code points

describe('codePointLength', () => {
  it('counts an astral emoji as one', () => {
    expect(codePointLength('😀')).toBe(1)
  })

  it('counts each code point of a ZWJ family emoji, like mb_strlen on the server', () => {
    expect(codePointLength(ZWJ_FAMILY)).toBe(5)
  })

  it('returns 0 for the empty string', () => {
    expect(codePointLength('')).toBe(0)
  })
})

describe('trimServerWhitespace and trimmedLength', () => {
  it('gives 4 for "  Food "', () => {
    expect(trimmedLength('  Food ')).toBe(4)
  })

  it('trims NBSP and U+3000 at both edges, as the server does', () => {
    expect(trimServerWhitespace(`${NBSP}${IDEOGRAPHIC_SPACE}Food${IDEOGRAPHIC_SPACE}${NBSP}`)).toBe(
      'Food',
    )
  })

  it('trims tabs, line breaks, U+2028 and U+2029 at the edges', () => {
    expect(trimServerWhitespace('\t\n\r\u2028Food\u2029\u000B\u000C')).toBe('Food')
  })

  it('keeps inner whitespace', () => {
    expect(trimServerWhitespace(` a${NBSP}b `)).toBe(`a${NBSP}b`)
  })

  it('does not trim NUL, U+0085 or U+FEFF, which the server does not trim either', () => {
    expect(trimServerWhitespace('\u0000a')).toBe('\u0000a')
    expect(trimServerWhitespace('a\u0085')).toBe('a\u0085')
    expect(trimServerWhitespace('\uFEFFa')).toBe('\uFEFFa')
  })

  it('counts a ZWJ emoji after trimming by code point', () => {
    expect(trimmedLength(` ${ZWJ_FAMILY} `)).toBe(5)
  })

  it('gives 0 for a value made only of NBSP and U+3000', () => {
    expect(trimmedLength(`${NBSP}${IDEOGRAPHIC_SPACE}${NBSP}`)).toBe(0)
  })
})

describe('checkName', () => {
  it('accepts 50 emoji', () => {
    expect(checkName('😀'.repeat(NAME_MAX))).toEqual({ ok: true, value: '😀'.repeat(NAME_MAX) })
  })

  it('refuses 51 emoji as too long', () => {
    expect(checkName('😀'.repeat(NAME_MAX + 1))).toEqual({ ok: false, reason: 'too-long' })
  })

  it('returns the trimmed value', () => {
    expect(checkName('  Food ')).toEqual({ ok: true, value: 'Food' })
  })

  it('refuses an empty and a blank value', () => {
    expect(checkName('')).toEqual({ ok: false, reason: 'empty' })
    expect(checkName('   ')).toEqual({ ok: false, reason: 'empty' })
  })

  it('refuses a value made only of NBSP and U+3000 (the server trims them to empty too)', () => {
    expect(checkName(`${NBSP}${IDEOGRAPHIC_SPACE}`)).toEqual({ ok: false, reason: 'empty' })
  })

  it('accepts 50 emoji padded with NBSP and U+3000 (never stricter than the server)', () => {
    const padded = `${NBSP}${IDEOGRAPHIC_SPACE}${'😀'.repeat(NAME_MAX)}${IDEOGRAPHIC_SPACE}${NBSP}`
    expect(checkName(padded).ok).toBe(true)
  })

  it('counts a ZWJ family emoji as 5, so 10 of them pass and 11 fail', () => {
    expect(checkName(ZWJ_FAMILY.repeat(10)).ok).toBe(true)
    expect(checkName(ZWJ_FAMILY.repeat(11))).toEqual({ ok: false, reason: 'too-long' })
  })
})

describe('checkDescription', () => {
  it('accepts the maximum and refuses one more', () => {
    expect(checkDescription('a'.repeat(DESCRIPTION_MAX)).ok).toBe(true)
    expect(checkDescription('a'.repeat(DESCRIPTION_MAX + 1))).toEqual({
      ok: false,
      reason: 'too-long',
    })
  })

  it('refuses an empty value', () => {
    expect(checkDescription(` ${NBSP}`)).toEqual({ ok: false, reason: 'empty' })
  })

  it('trims the value it returns', () => {
    expect(checkDescription(' lunch ')).toEqual({ ok: true, value: 'lunch' })
  })
})

describe('checkSearch', () => {
  it('accepts an empty value', () => {
    expect(checkSearch('')).toEqual({ ok: true, value: '' })
    expect(checkSearch(`  ${NBSP}`)).toEqual({ ok: true, value: '' })
  })

  it('accepts the maximum and refuses one more', () => {
    expect(checkSearch('😀'.repeat(Q_MAX)).ok).toBe(true)
    expect(checkSearch('😀'.repeat(Q_MAX + 1))).toEqual({ ok: false, reason: 'too-long' })
  })
})

describe('checkRegisterPassword', () => {
  it('accepts 12 emoji and refuses 11', () => {
    expect(checkRegisterPassword('😀'.repeat(PASSWORD_MIN)).ok).toBe(true)
    expect(checkRegisterPassword('😀'.repeat(PASSWORD_MIN - 1))).toEqual({
      ok: false,
      reason: 'too-short',
    })
  })

  it('does not trim: 12 spaces count as 12 characters', () => {
    expect(checkRegisterPassword(' '.repeat(PASSWORD_MIN)).ok).toBe(true)
    expect(checkRegisterPassword(` ${'a'.repeat(PASSWORD_MIN - 2)} `).ok).toBe(true)
  })

  it('accepts the maximum and refuses one more', () => {
    expect(checkRegisterPassword('a'.repeat(PASSWORD_MAX)).ok).toBe(true)
    expect(checkRegisterPassword('a'.repeat(PASSWORD_MAX + 1))).toEqual({
      ok: false,
      reason: 'too-long',
    })
  })

  it('returns the password byte for byte', () => {
    expect(checkRegisterPassword(`  ${'a'.repeat(12)}  `)).toEqual({
      ok: true,
      value: `  ${'a'.repeat(12)}  `,
    })
  })
})

describe('checkSignInPassword', () => {
  it('accepts a short password (the minimum applies on register only)', () => {
    expect(checkSignInPassword('abc')).toEqual({ ok: true, value: 'abc' })
  })

  it('refuses an empty password', () => {
    expect(checkSignInPassword('')).toEqual({ ok: false, reason: 'empty' })
  })

  it('accepts the maximum and refuses one more', () => {
    expect(checkSignInPassword('😀'.repeat(PASSWORD_MAX)).ok).toBe(true)
    expect(checkSignInPassword('😀'.repeat(PASSWORD_MAX + 1))).toEqual({
      ok: false,
      reason: 'too-long',
    })
  })
})
