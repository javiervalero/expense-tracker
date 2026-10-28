import { DESCRIPTION_MAX, NAME_MAX, PASSWORD_MAX, PASSWORD_MIN, Q_MAX } from '../config'

export type TextResult<Reason extends string> =
  { ok: true; value: string } | { ok: false; reason: Reason }

/**
 * Trims exactly what the server trims (backend/src/Http/Input/PlainText.php, TRIM_CHARACTERS, line 36): space, tab,
 * LF, CR, VT, FF and every Unicode space separator (\p{Zs}: NBSP, U+1680, U+2000-U+200A, U+202F, U+205F, U+3000)
 * plus U+2028 and U+2029. NUL and U+0085 are NOT trimmed (the server refuses them as controls).
 *
 * Why not `String.prototype.trim()`: JS trims the same set PLUS U+FEFF (the byte order mark, category Cf). Here that
 * can only make the client looser, never stricter: the server does not trim U+FEFF and refuses it as an invisible
 * character (PlainText.php INVISIBLE, line 40), so a value that JS `trim()` alone would empty is refused by the server
 * too, and a value padded with U+FEFF is refused by the server whatever the client says. The Unicode spaces (NBSP,
 * U+3000) are in both lists, so the counts agree. Using the server's own list makes the equality explicit and keeps
 * it independent of the engine's definition of white space.
 */
const SERVER_TRIM_SET = '\t\n\v\f\r \u00A0\u1680\u2000-\u200A\u2028\u2029\u202F\u205F\u3000'
const SERVER_TRIM_EDGES = new RegExp(`^[${SERVER_TRIM_SET}]+|[${SERVER_TRIM_SET}]+$`, 'g')

export function trimServerWhitespace(s: string): string {
  return s.replace(SERVER_TRIM_EDGES, '')
}

/** Length in Unicode code points, as `mb_strlen(..., 'UTF-8')` on the server (not UTF-16 units, not graphemes). */
export function codePointLength(s: string): number {
  return [...s].length
}

/** Code points left after the server-style trim. */
export function trimmedLength(s: string): number {
  return codePointLength(trimServerWhitespace(s))
}

function checkTrimmed(s: string, min: number, max: number): TextResult<'empty' | 'too-long'> {
  const value = trimServerWhitespace(s)
  const length = codePointLength(value)
  if (length < min) {
    return { ok: false, reason: 'empty' }
  }
  return length > max ? { ok: false, reason: 'too-long' } : { ok: true, value }
}

/** Category name: 1 to NAME_MAX code points after trimming. */
export function checkName(s: string): TextResult<'empty' | 'too-long'> {
  return checkTrimmed(s, 1, NAME_MAX)
}

/** Expense description: 1 to DESCRIPTION_MAX code points after trimming. */
export function checkDescription(s: string): TextResult<'empty' | 'too-long'> {
  return checkTrimmed(s, 1, DESCRIPTION_MAX)
}

/** Search text `q`: 0 to Q_MAX code points after trimming (empty means no filter). */
export function checkSearch(s: string): TextResult<'too-long'> {
  const value = trimServerWhitespace(s)
  return codePointLength(value) > Q_MAX ? { ok: false, reason: 'too-long' } : { ok: true, value }
}

/** Register password: PASSWORD_MIN to PASSWORD_MAX code points, never trimmed (PasswordPolicy.php counts it as is). */
export function checkRegisterPassword(s: string): TextResult<'too-short' | 'too-long'> {
  const length = codePointLength(s)
  if (length < PASSWORD_MIN) {
    return { ok: false, reason: 'too-short' }
  }
  return length > PASSWORD_MAX ? { ok: false, reason: 'too-long' } : { ok: true, value: s }
}

/**
 * Sign-in password: at most PASSWORD_MAX (the minimum never applies here, so a later policy change locks nobody out).
 * An empty one is refused: it cannot match any account, so this is not stricter than the server in effect.
 */
export function checkSignInPassword(s: string): TextResult<'empty' | 'too-long'> {
  if (s === '') {
    return { ok: false, reason: 'empty' }
  }
  return codePointLength(s) > PASSWORD_MAX
    ? { ok: false, reason: 'too-long' }
    : { ok: true, value: s }
}
