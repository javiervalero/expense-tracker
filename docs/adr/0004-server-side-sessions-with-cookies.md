# 0004. Server-side sessions with secure cookies

- Status: Proposed
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
