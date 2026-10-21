# 0004. Server-side sessions with secure cookies

- Status: Accepted (2026-10-09)
- Amended by: [0012](0012-runtime-platform.md) (client IP source for per-IP login throttling; IPv6 per /64)
- Date: 2026-10-07

## Context

Users sign up, sign in and sign out. The app is exposed on the public internet. Email flows (verification,
password reset) are out of scope. Lambda keeps no state between requests, so PHP's default file-based sessions
won't work.

## Decision

- **Passwords** are hashed with PHP's `password_hash()` using Argon2id, and checked with `password_verify()`.
- **On sign-in**, the server generates a random 256-bit token and sends it in a cookie:
  - name `__Host-session`;
  - `HttpOnly`, `Secure`, `SameSite=Lax`, `Path=/`.
- **Storage:** only a SHA-256 hash of the token is stored, as a DynamoDB session item. Its TTL handles expiry, with
  sliding renewal.
- **Sign-out** deletes the session item, so the token stops working immediately.
- **CSRF protection** for requests that change data (POST/PATCH/DELETE):
  - the `SameSite` cookie;
  - a required custom header (`X-Requested-With`);
  - the `Origin` header must match the app's own origin.
- **Login throttling:** failed attempts are counted per email and per IP, in TTL-expiring items. Responses are
  generic, so they don't reveal whether an account exists.

## Alternatives considered

- **JWTs held in the browser.** Stateless, but they can't easily be revoked at sign-out. Kept in `localStorage`,
  they're exposed to cross-site scripting; kept in a cookie, they bring no benefit over a session token.
- **Amazon Cognito.** A managed, serverless sign-in service. It's a strong production choice, but it moves the core
  auth logic out of our PHP code and makes the local no-AWS setup harder. It could be revisited later.

## Consequences

- Real sign-out and server-side control over sessions.
- JavaScript can't read the token, which limits the damage from any cross-site scripting bug.
- Each authenticated request makes one DynamoDB read to look up the session. That is cheap and fast.
- Account recovery is out of scope; a forgotten password can't be reset.

## Implemented in Phase 2 (2026-10-21, PR #5)

Decisions A1–A22 and the review notes are in [the Phase 2 plan](../plans/phase-2-authentication.md). None changes this ADR; the points below make it precise.

- **Cookie and token (A1, A2):** `__Host-session`, `Path=/; Secure; HttpOnly; SameSite=Lax`, no `Domain`, `Max-Age`
  = the absolute lifetime. The same attributes everywhere, also on `http://localhost` (no "insecure cookie" switch;
  use Chrome or Firefox on `http://localhost:5173` locally, see `docs/local-dev.md`). The token is 32 random bytes,
  base64url (43 characters); only its SHA-256 hex is stored. Any other cookie shape, or two `__Host-session` values,
  means "no session" with no database read.
- **Lifetime (A3, clarifies "sliding renewal"):** idle 24 h, sliding, renewed at most every 15 minutes; absolute cap
  7 days. Expiry is checked on every read, because DynamoDB TTL deletes late; TTL only cleans up.
- **Rotation and sign-out (A4, A5):** register and login always issue a fresh token and end the session the request
  carried (best effort, after the new one exists). Logout always answers `204` with a clearing cookie, whatever cookie
  was sent, deletes only the presented session, and is strict: a storage failure gives 500/503, never a false
  "signed out". There is no "sign out everywhere".
- **`/auth/me` (A6):** `{id, email}` from the session, otherwise `401` with `WWW-Authenticate: Session` and a clearing
  cookie when one was sent.
- **CSRF (A8, clarifies the header rule):** every method except `GET`, `HEAD` and `OPTIONS` needs
  `X-Requested-With: XMLHttpRequest` (exact value) and an `Origin` that is byte for byte one of `APP_ORIGINS`; a
  missing, `null` or comma-joined `Origin` is refused. The check runs before authentication. **A GET, HEAD or OPTIONS
  handler never makes a state change the sender can choose; the only exception is housekeeping of the session the
  request itself carries** (renewal, and ending a session whose user no longer exists).
- **Generic responses apply to login (A9):** login gives one identical `401` for a wrong password and an unknown email,
  and an unknown email costs the same hash and the same reads (A16, a dummy hash with pinned parameters). Register
  answers `409` for a taken email: without email verification it cannot honestly hide it, so it is throttled instead
  (owner decision 2).
- **Passwords (A11, A12):** 12 to 128 characters, spaces and Unicode allowed, control characters refused, compared
  byte for byte (no Unicode normalisation, `intl` is not in the images). Argon2id `memory_cost` 64 MiB, `time_cost`
  4, `threads` 1, pinned as constants; login rehashes an outdated hash with a write conditional on the old hash.
- **Throttling (A13, A15):** fixed windows in DynamoDB. Login: 10 per 15 min per account (SHA-256 of the email), 30 per
  address (IPv4 or IPv6 `/64`), 100 per IPv6 `/48`. Register: 5 per hour per address, 20 per `/48`. Above a limit:
  `429` with `Retry-After` and one generic body. Each attempt is one atomic `ADD` that returns the count (increment
  first), so parallel attempts cannot pass the limit; a storage-level test with 25 concurrent processes proves it. A
  correct login resets the account bucket and refunds the address buckets.

### Accepted risks and known gaps (threat model)

- **Fixed windows** allow a burst of up to twice a limit around a window boundary. IPv4 has no coarse tier.
- **A successful login resets the account bucket**, so every sign-in by the owner gives an attacker a fresh 10
  attempts on that account. An attacker can also push a victim's account bucket over its limit from addresses that
  are already blocked: a lockout of at most 15 minutes.
- **Successful logins and blocked attempts are not free:** each blocked login still costs two or three DynamoDB
  writes. Floods are left to the API Gateway / WAF rate limit planned for Phase 7.
- **The account key is an unsalted SHA-256 of the email.** Someone who can read the table can confirm that a guessed
  email was tried in the last 15 minutes (registered emails are stored in plain text anyway). An HMAC with a key in
  SSM is the upgrade if it is ever wanted.
- **No password blocklist** (owner decision, 2026-10-19). NIST SP 800-63B-4 asks for one; it is a known gap, accepted
  because a local list of common passwords tripped company endpoint security and an online lookup would be a
  third-party call (ADR 0012). The length minimum and the throttle are the mitigations.
- **Architecture tests scan tokens, not meaning.** The tests that keep `LoginAttempt`, throttle refunds and
  `PasswordVerifier` implementations inside their owners would miss a call made through reflection or a dynamic
  name, and an interface that extends `PasswordVerifier`. Code review covers those.
- **Later features must keep the guarantees:** account deletion must end the user's sessions (or the guard must check
  the user exists); a password change or reset must write conditionally on the old hash and end the other sessions.
