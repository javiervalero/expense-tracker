// App-wide constants. Each one names the contract (contract/openapi.yaml) or plan decision it comes from; change them
// only together with the contract.

// Plan phase-4: one currency, one locale (amounts are integer cents).
export const APP_CURRENCY = 'EUR'
export const APP_LOCALE = 'en-IE'

// Contract: `limit` defaults to 50 on the expense list (ADR 0002). MAX_AUTO_FOLLOW: plan phase-4 risk R7 (WP10), the most
// pages followed on their own when a filtered page comes back empty.
export const PAGE_SIZE = 50
export const MAX_AUTO_FOLLOW = 3

// Contract: amountCents is 1 to 100000000.
export const MIN_CENTS = 1
export const MAX_CENTS = 100_000_000

// Contract: expense dates run from 2000-01-01 to 2099-12-31.
export const DATE_MIN = '2000-01-01'
export const DATE_MAX = '2099-12-31'

// Contract: names 1-50 characters, descriptions 1-200, `q` up to 100 (counted in code points, plan C10).
export const NAME_MAX = 50
export const DESCRIPTION_MAX = 200
export const Q_MAX = 100

// Contract: passwords 12-128 characters (the minimum applies on register only, plan C10).
export const PASSWORD_MIN = 12
export const PASSWORD_MAX = 128

// Base path of the versioned API. The generated paths do not include it (contract/README.md).
export const API_BASE = '/api/v1'

// Plan phase-4 C2: a request with no answer after 10 seconds is aborted (covers the Argon2id hash and a cold start).
export const REQUEST_TIMEOUT_MS = 10_000

// Shown where a stored amount or date cannot be displayed (never expected: the server validates both).
export const UNKNOWN_VALUE = '—'
