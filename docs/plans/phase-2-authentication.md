# Phase 2: Authentication. Implementation plan

| | |
|---|---|
| Status | **Approved by the owner, 2026-10-16** (gate G1; all nine section 10 decisions answered with the recommended defaults) |
| Phase | 2 (PLAN §12, essential track) |
| Branch | `feat/phase-2-authentication`, from `main` (Phase 1 merged in PR #4, commit 93d2cbb) |
| Inputs | BRIEF; PLAN §5, §6, §9, §10, §12; ADR 0002, 0003, 0004, 0005, 0011, 0012; [Phase 1 plan](phase-1-backend-core.md) section 6 (everything carried to Phase 2); the Phase 1 code (`backend/src/Api`, `backend/src/Http`, `backend/src/Infrastructure`, `ApiTestCase`, `contract/openapi.yaml`, `docker-compose.yml`, `Makefile`, `.env.example`) |
| Drafted by | `ecc:planner` (opus/high); fact-checked against the code by the main session (2026-10-16) |
| AWS | **None.** Phase 2 makes no AWS calls, uses no credentials, deploys nothing and costs $0. Every DynamoDB call goes to DynamoDB Local. Phase 2 has no AWS deploy, so it has no deploy gate. If any step ever seems to need AWS, the work stops and the owner is asked first. |

**Scope (PLAN §12, row 2):** `POST /auth/register`, `POST /auth/login`, `POST /auth/logout`, `GET /auth/me`. It also covers:
- server-side sessions (an opaque token in an HttpOnly cookie, stored only as a hash under `SESSION#<tokenHash>`, with a TTL);
- Argon2id password hashing;
- CSRF protection (the `SameSite` cookie, a required custom header, and an `Origin` check);
- login throttling per account and per client IP (IPv4, IPv6 `/64`, plus a coarser IPv6 tier).

Contract first (ADR 0011): every endpoint, status and problem goes into `openapi.yaml` in the same WP that adds it.

Not in scope: front-end pages (Phase 4), categories and expenses (Phase 3). See section 7.

## 1. Goal and definition of done

**Goal:** a person can create an account, sign in, see who they are and sign out, through the real API, locally. The design must hold up on the public internet:
- passwords are stored only as Argon2id hashes;
- the session token never reaches JavaScript and is never stored in plain form;
- a forged cross-site request is refused;
- password guessing is slowed per account and per network.

**Done means (each item can be demonstrated):**
- **`make up`** from a clean clone is still idempotent and healthy. The API now starts after the bootstrap (`LATE_SERVICES = api frontend`) and talks to DynamoDB Local.
- **The happy path, with curl** (commands in `docs/local-dev.md`, all with `X-Origin-Verify`, `Origin: http://localhost:5173` and `X-Requested-With: XMLHttpRequest`):
  - `POST /api/v1/auth/register` returns `201` with `{id, email}` and `Set-Cookie: __Host-session=…; Path=/; Max-Age=…; Secure; HttpOnly; SameSite=Lax`.
  - `GET /api/v1/auth/me` with that cookie returns `200` with the same `{id, email}`.
  - `POST /api/v1/auth/logout` returns `204` and a clearing cookie. The same cookie then gets `401` on `/auth/me`.
  - `POST /api/v1/auth/login` returns `200` and a **new** cookie. Any cookie the request carried stops working.
- **CSRF:** a `POST` without `X-Requested-With`, without `Origin`, or with a foreign `Origin` gets `403` problem+json. `GET` and `HEAD` are never affected.
- **Throttling:**
  - After the account limit of failed logins for one email, every login for that email gets `429` with `Retry-After`, even with the right password. The same happens for an email that has no account.
  - The same holds per client address (IPv4 or `/64`) and per IPv6 `/48`.
  - A race test sends 25 parallel wrong-password logins and gets **exactly** the limit of `401`s; the rest are `429`.
- **No secrets in logs:** `make test-integration` greps the `api-php` logs for the test's password and session token and finds neither. Request lines on authenticated calls carry `userId`.
- **Contract:**
  - Every new operation is in `openapi.yaml`, and `frontend/src/api/schema.gen.ts` is regenerated.
  - Every integration request is contract-validated through `ApiTestCase::request()`.
  - New contract tests check four things: every operation declares `security`; the routes behind the session guard equal the operations with `security: [sessionCookie]`; every operation with a body documents 400/413/415/422; every non-health operation documents 503.
- **`make test`** passes with line coverage ≥ 80% (target: near 100% on `backend/src`). **`make lint`** passes.
- **CI:** the same 7 required checks (`backend`, `local-aws`, `guards`, `contract`, `frontend`, `integration`, `terraform`) are green. No job is added or renamed.
- **Docs and ADRs:** each of ADR 0004, 0002, 0003 and 0012 gets an "Implemented in Phase 2" note (clarifications only; see section 2). `docs/local-dev.md`, `README.md` and `.env.example` are updated.

## 2. Design decisions (recommended defaults)

These settle the open design questions before any code is written. "ADR impact" says whether the point stays inside an accepted ADR. **None of them is meant to change a decision.** If the owner reads any "clarification" as a change, it becomes ADR 0013 instead (gate G2).

| # | Topic | Recommendation | ADR impact |
|---|---|---|---|
| A1 | Cookie | Name `__Host-session`. Attributes `Path=/; Secure; HttpOnly; SameSite=Lax`, no `Domain`, `Max-Age` = the absolute lifetime (so renewal never needs a new `Set-Cookie`). **The same attributes everywhere, `Secure` included, also on `http://localhost`.** Browsers treat localhost as a secure context; R1 confirms this per browser. There is no "insecure cookie" switch, so local and AWS run one code path. | As ADR 0004 |
| A2 | Token and hash | 32 bytes from `random_bytes`, base64url without padding (exactly 43 characters). Stored as SHA-256 hex: `PK = SESSION#<64 hex>`. No pepper or HMAC is needed, because a 256-bit random value cannot be guessed from its hash. A cookie value that is not exactly 43 base64url characters is treated as "no session", with no database read. | As ADR 0004 |
| A3 | Lifetime | **Idle 24 h (sliding), absolute 7 days.** The renewal write happens at most once every 15 minutes. Expiry is checked on **every** read against `expiresAt` and `absoluteExpiresAt`, because DynamoDB TTL deletes items late (up to about 48 h). TTL is only for cleanup. | Clarifies ADR 0004 ("sliding renewal" gets an absolute cap) |
| A4 | Rotation | Login and register always issue a fresh token, and delete the session that the request carried (if any). The server never adopts a token chosen by the client, so session fixation is impossible. | As ADR 0004; the ECC PHP rule ("regenerate after authentication") |
| A5 | Logout | Deletes the current session item. Always `204` plus a clearing cookie, even with no cookie or an unknown one (idempotent, and no oracle). Protected by CSRF, so a forced logout is refused. There is no "sign out everywhere": it would need a per-user session index, and the BRIEF does not ask for it. | As ADR 0004 |
| A6 | `/auth/me` | `200 {id, email}`. Otherwise `401` problem with `WWW-Authenticate: Session` (RFC 9110 requires that header on a 401), plus a clearing cookie when a stale cookie was sent. | — |
| A7 | Where authentication runs | There is **no global auth middleware**. A `RequireSession` decorator wraps each protected handler in `Routes.php`. Public routes, 404s and `/health` therefore never cost a DynamoDB read. A contract test keeps "guarded routes" equal to "operations with `security: [sessionCookie]`", so a forgotten guard fails CI. | Clarifies ADR 0003 (its list says "session authentication" middleware; this is a route-level guard) and the Phase 1 plan's chain note |
| A8 | CSRF | A global `CsrfMiddleware` after `ClientIp` and before the router. Every method except `GET`, `HEAD` and `OPTIONS` needs both:<br>• **`X-Requested-With: XMLHttpRequest`** (exact value). This is ADR 0004's header, kept on purpose: ADR 0012 §4.1's CloudFront allow-list forwards `X-Requested-With` but not `X-CSRF-Token`.<br>• **`Origin`**, byte-for-byte one of `APP_ORIGINS`. A missing `Origin`, `null`, or a comma-joined value is refused.<br>It runs before authentication, so a forged request costs no database read.<br>**Rule: a `GET`, `HEAD` or `OPTIONS` handler never changes state** (no `GET /auth/logout`, no state change on a read). `SameSite=Lax` still sends the cookie on a top-level cross-site `GET` navigation, and these methods skip the CSRF check, so the safety of the whole scheme rests on this rule (WP4 security review).<br>Locally the Vite proxy forwards the browser's `Origin` unchanged (`changeOrigin: false`) and already refuses foreign origins itself (`frontend/vite/proxyHeaders.ts`), so the check sees the real value. | Clarifies ADR 0004 (exact value, missing Origin refused, order) |
| A9 | Register flow and enumeration | Register **signs the user in** (`201` + cookie). An email that is already taken gets **`409`**, which is honest. Sign-up cannot hide whether an email exists without email verification (out of scope in the BRIEF): a fake "success" would leave the person unable to sign in. Mitigation: registration is throttled per IP (A13). **Login stays fully generic** (ADR 0004). | ADR 0004's "generic responses" sentence is about login, so this is a clarification. **Owner decision 2** |
| A10 | Email normalisation | Trim surrounding ASCII whitespace, lower-case (ASCII), validate with `FILTER_VALIDATE_EMAIL`, at most 254 bytes, no control characters. No provider tricks (dots, `+tags`). ASCII addresses only (documented limit). No quoted local parts and no domain literals: any `"`, `\`, `[` or `]` is refused (WP6 security review: otherwise one mailbox could hold several accounts). The user item stores the normalised form. | — |
| A11 | Password policy | 12 to 128 characters (`mb_strlen`). Spaces and Unicode are allowed. Control characters (`\p{Cc}`, C0, DEL and C1) and invalid UTF-8 are refused (a carried WP3 rule). **Compared byte for byte, no Unicode normalisation:** `intl` (`Normalizer`) is in neither the tools image nor the pinned `bref/php-85` image (checked 2026-10-19), so an accented password must be typed with the same composition; a test pins this and must be inverted if `intl` is ever added. No composition rules. **No password blocklist** (owner decision 2026-10-19, see section 10): neither a local list of common or leaked passwords nor an online breached-password lookup (that would be an outbound third-party call, against ADR 0012's "no third-party calls"). Login only enforces the maximum length, so a later policy change never locks anyone out. | **Owner decision 4** |
| A12 | Argon2id cost | `memory_cost` 65536 KiB (64 MiB), `time_cost` 4, `threads` 1. These are PHP's defaults, **pinned as constants** so a PHP upgrade cannot change them silently. `password_needs_rehash` on login upgrades old hashes. Argon2 memory is allocated outside PHP's `memory_limit`. Phase 7 sizes the API Lambda (at least 512 MB suggested) and Phase 8 measures login latency. | As ADR 0004 |
| A13 | Throttle limits | Fixed windows, counted in DynamoDB:<br>• login per account (hashed email): **10 per 15 min**;<br>• login per address (IPv4, IPv6 `/64`, or the shared `unknown`): **30 per 15 min**;<br>• login per IPv6 `/48`: **100 per 15 min**;<br>• register per address: **5 per hour**; register per `/48`: **20 per hour**.<br>Above a limit: `429`, `Retry-After` = seconds to the end of the window, one generic body that never says which bucket fired. An email without an account is counted exactly like a real one. | ADR 0004/0012 say "per email and per IP"; the extra tiers are additive (clarification). **Owner decision 5** |
| A14 | Coarse IPv6 tier | **`/48`**, next to the `/64` key. A holder of a `/48` (hosting, 6to4) would otherwise spread over 65,536 `/64` keys. Only failures count against the login tier and its limit is high, so a `/48` shared by about 256 residential `/56` customers is unlikely to trip it. IPv4 has no coarse tier. Computed from the canonical `ClientAddress::$ip`; `ClientIp` itself is not changed. | Clarifies ADR 0012 §4.3. **Owner decision 6** |
| A15 | Throttle storage and races | Item `PK = THROTTLE#<scope>#<key>`, `SK = THROTTLE`, with `attempts` and `expiresAt` (TTL). Scopes: `LOGIN-EMAIL` (key = SHA-256 hex of the normalised email, so mistyped emails are not stored), `LOGIN-IP`, `LOGIN-NET`, `REGISTER-IP`, `REGISTER-NET`.<br>**Increment first:** each attempt is one atomic `UpdateItem ADD attempts :1`, guarded by `attribute_not_exists(PK) OR expiresAt > :now`, and the returned count decides. N parallel attempts therefore get N distinct counts, and only `limit` pass.<br>An expired window that TTL has not deleted yet is reset with a conditional put (`… OR expiresAt <= :now`). If two requests reset at once, the loser retries the increment once.<br>On a **successful** login: the account bucket is deleted, and the address buckets are refunded (`ADD -1`, never below 0). Net effect: failures are what is counted. | Clarifies ADR 0002 (`THROTTLE#<email or ip>` gets a scope and a hashed email) |
| A16 | Timing for unknown accounts | When the email has no account, the login still runs `password_verify` against a constant dummy hash with the **same** parameters (a test pins them), and does one `GetItem` on a fixed dummy user key. Known and unknown emails then cost the same hash time and the same number of reads. | As ADR 0004 |
| A17 | Clock and ids | `Infrastructure\Clock\Clock` (`now(): DateTimeImmutable`, UTC) with `SystemClock`; `FrozenClock` for tests. `Infrastructure\Id\IdGenerator` with `RandomUuidGenerator` (UUID v4 from `random_bytes`), plus `Uuid::isValid()` (lower-case canonical v4). | ADR 0002 ("random UUIDs") |
| A18 | DynamoDB overload | `StorageFailure` with code `ProvisionedThroughputExceededException`, `ThrottlingException` or `RequestLimitExceeded`, and `TransactionCancelled` whose reasons are only throttling or `TransactionConflict` (no `ConditionalCheckFailed`), become **`503` with `Retry-After: 1`**. This only happens after the SDK's own 3 attempts. `TransactionInProgressException` (a retry that finds the first attempt still committing; WP7 review) is handled the same way. Every other storage failure stays a generic 500. | — |
| A19 | False duplicate after a retry | The SDK retries a timed-out write; the first attempt may already have succeeded. So a `ConditionFailed` on a create is **re-read before it is reported**:<br>• register: if the email lock already holds *our* new user id, it is a success;<br>• session create: if the item holds our user id and `createdAt`, it is a success;<br>• anything else is a real conflict.<br>The throttle `ADD` may count one attempt twice on a retry, which errs on the safe side. | — |
| A20 | Duplicate cookie names | Bref rebuilds `Cookie` from API Gateway's `cookies[]`, so two `__Host-session` values can arrive (cookie tossing). **Two or more** means anonymous: no lookup, and a warning `duplicate session cookie` that logs no values. | — |
| A21 | Secrets in logs | `JsonLogger` key-based redaction gains `cookie`, `set-cookie`, `x-origin-verify`, `x-csrf-token`, `password` and `passwordHash` (case-insensitive). Text patterns are added for `Cookie:` / `Set-Cookie:` lines and `__Host-session=<value>`. Every function that takes a password or token marks it `#[\SensitiveParameter]`. Value objects hide secrets from `var_dump`, `print_r` and `json_encode`. The request line gets `userId` (an opaque UUID) once a session or login is known. | — |
| A22 | Local wiring | `api-php` gets `DYNAMODB_ENDPOINT` and `DYNAMODB_TABLE` (both required, `:?`), `AWS_REGION`, the **fake** key pair from `.env`, `AWS_EC2_METADATA_DISABLED=true` and `APP_ORIGINS`. Defence in depth: `api-php` stays **only** on the internal network `api-internal`, which has no route out, and `dynamodb` joins that network. The fake keys therefore cannot reach anything but DynamoDB Local, and `DYNAMODB_ENDPOINT` is already allow-listed to local hosts. `check-env` requires `DYNAMODB_ENDPOINT`. | ADR 0007 (local stack), unchanged |

**Request flow after Phase 2** (outermost first):
1. `ErrorHandling`
2. `RequestLogging`
3. `OriginVerify`
4. deferred 413/400
5. `ClientIp`
6. **`StorageUnavailable`** (WP3)
7. **`Csrf`** (WP4)
8. router, with **`RequireSession`** around protected handlers (WP8)

## 3. Work packages

Each package is one or a few reviewable commits with conventional messages. Tests are committed with or before the code that makes them pass, so RED → GREEN is visible in the history. Every WP is S or S–M.

**Order:** step 0 research → infrastructure (WP2–WP3) → CSRF (WP4) → pure building blocks (WP5–WP6) → repositories (WP7–WP9) → endpoints (WP10–WP12) → end-to-end and log checks (WP13) → docs (WP14). WP5, WP6 and WP9 depend only on WP2 and could be reordered. They all run on one branch, one after another.

**Where commands run:** unchanged from Phase 1. PHP runs **only through make targets**, in the pinned PHP 8.5 tools container: the host PHP is 8.4 and `vendor/` needs 8.5. Node tools run in the pinned node container. Temporary files go in `build/tmp/`. CI calls the same make targets.

**Accepted ADRs are not edited to change decisions.** A real change needs ADR 0013 (gate G2). Clarifications go in the "Implemented in Phase 2" notes written in WP14.

### Shared Docker host rule (applies to every WP marked "Docker: yes")
The Docker host is shared with other projects. An agent may only:
- use the repo's make targets;
- use `docker compose -p expense-tracker …` (`up`, `stop`, `start`, `run --rm`, `logs`, `ps`, `down` **without** `-v`);
- use `docker run --rm` for containers it starts itself.

It may remove **only** containers, networks or volumes that it created in this session, by name. It must **never** run `docker rm $(docker ps -aq)`, any `docker … prune`, `docker compose down -v`, or `docker volume rm` on a volume it did not create. If a cleanup seems to need any of these, it stops and asks the owner.

### Endpoint-security rule (applies to every WP, added 2026-10-19)
The owner's machine runs company endpoint security. An agent must **never** download, vendor, generate or run anything such tools commonly flag: password lists, wordlists or leaked-credential dumps; exploit, payload or fuzzing collections, web shells, the EICAR string or malware samples; offensive tools (nmap, sqlmap, hydra, hashcat, john, metasploit, nikto, gobuster and the like) or any port or network scan, localhost included; unsigned binaries or curl-pipe-to-shell installers. Security tests use invented strings. If a step plausibly needs any of these, the agent stops and the owner decides. (A SecLists password list downloaded for WP6 set off company-wide alerts; see section 10.)

### Model routing summary (`.claude/rules/project/model-routing.md`)
- **Implementation:** `ecc:tdd-guide`, sonnet/medium, one brief per WP (file paths + the exact question).
- **Review:** one language reviewer per diff: `ecc:php-reviewer`, sonnet/medium.
- **Security:** `ecc:security-reviewer`, **opus/high on every WP that touches auth, sessions, cookies, CSRF, throttling, passwords, log redaction or DB writes** (WP2–WP13, marked per WP). **Round 2** (re-review of the fix hunks only) also runs at **opus/high**.
- **CI and Makefile:** diffs under about 30 lines are reviewed inline by the main session. Larger ones go to `ecc:code-reviewer` (sonnet/medium).
- **Docs:** `ecc:doc-updater` (haiku/low), always fact-checked by the main session (WP14).
- **Test runs and output summaries:** general-purpose, haiku/low.
- **Build or lint failures:** `ecc:build-error-resolver`, sonnet/low on the first attempt, then opus/high.

### Step 0: Research & Reuse (ECC step 0, before WP2), fact-checked by the main session
`ecc:docs-lookup` cannot be used here: it depends on Context7, which this setup does not have (found in Phase 1). Instead:
- **R2, R4 and R5** are answered from the code itself: the Bref source in `backend/vendor/bref/bref/src/Event/Http/`, the aws-sdk-php source in `backend/vendor/aws/`, and a short `php -r` run in the pinned `php:8.5-fpm-alpine` and `bref/php-85:3` images (`docker run --rm`). Main session or `general-purpose` haiku/low.
- **R1, R3, R6 and R7** are web research on primary sources (browser vendor docs, the AWS DynamoDB developer guide, OWASP cheat sheets, NIST SP 800-63B-4, the Fetch spec): `general-purpose` sonnet/medium with WebFetch, every claim with a URL.

Each result goes in the WP that needs it:
- **R1 (WP5, A1):** do Chrome, Firefox and Safari accept and send a `__Host-` cookie with `Secure` from `http://localhost:5173` and `http://127.0.0.1:5173`? If Safari does not, `docs/local-dev.md` says "use Chrome or Firefox locally"; the code is not weakened.
- **R2 (WP5, WP8):** Bref 3 FPM with API Gateway payload v2. How is the `Cookie` request header rebuilt from `cookies[]` (separator, duplicates)? How do several `Set-Cookie` response headers become the `cookies` array?
- **R3 (WP3):** the exact DynamoDB error codes for throttling, both as SDK exception codes and as `CancellationReasons` codes (`ThrottlingError`, `ProvisionedThroughputExceeded`, `TransactionConflict`). Which of them does the SDK's `standard` retry mode already retry?
- **R4 (WP7):** does aws-sdk-php fill `ClientRequestToken` on `TransactWriteItems` by itself and reuse it across retries? Does DynamoDB Local honour it? This is informational only: A19's re-read rule does not depend on it.
- **R5 (WP6):** the Argon2id parameters and backend in `php:8.5-fpm-alpine` and `bref/php-85:3` (libargon2 or libsodium; whether `threads > 1` is possible); `password_hash` defaults on 8.5.
- **R6 (WP6, A3, A11):** current OWASP guidance (Password Storage, Session Management, Authentication cheat sheets) and NIST SP 800-63B-4 on password length, Argon2id parameters, idle and absolute timeouts, and lockout. Record the citations next to A3, A11 and A12.
- **R7 (WP4):** the Fetch spec. Does a same-origin `fetch` with `POST`/`PATCH`/`DELETE` always send `Origin`? If not, refusing a missing `Origin` (A8) would break the app.

### Step 0 results (2026-10-16)

Code questions answered by the main session from `vendor/` and the pinned images; web questions by `general-purpose` (sonnet/medium) on primary sources, checked by the main session.

| # | Result | Consequence |
|---|---|---|
| R1 | **Firefox ≥ 75** accepts `Secure` cookies from `http://localhost` (bug 1618113). **Chrome:** current Chromium treats `localhost` as trustworthy for cookie prefixes (`net/cookies/cookie_util.cc`); a 2023 test had `__Host-` refused, so the version is unconfirmed. **Safari:** not reliable (WebKit bug 281149, reopened 2026-04-20). **curl ≥ 7.79** treats `http://localhost` as secure for cookies. `127.0.0.1` is unconfirmed everywhere. | A1 stays (owner decision 7: no local switch). `docs/local-dev.md` says: use **Chrome or Firefox on `http://localhost:5173`** (not `127.0.0.1`, not Safari). WP12's integration tests use the API directly, so they do not depend on a browser. Phase 4 checks the real browser flow. |
| R2 | Bref (`Event/Http/HttpRequestEvent.php:324-328`) joins payload v2 `cookies[]` with `"; "` into one `cookie` header and **keeps duplicates**. Its own `getCookies()` keeps only the last duplicate and URL-decodes. On the way out (`HttpResponse::toApiGatewayFormatV2`), header names are capitalised and every `Set-Cookie` value becomes the `cookies` array; FPM headers are lower-cased first (`FpmHandler::getResponseHeaders`), so two different spellings of `Set-Cookie` would collapse into one. | WP5's own parser (duplicates kept) is required; **never use Bref's `getCookies()`**. `Response` emits exactly one spelling, `Set-Cookie`; WP5 tests a response with two cookies. |
| R3 | Throttling codes: `ProvisionedThroughputExceededException`, `ThrottlingException`, `RequestLimitExceeded` (all HTTP 400, retryable). The SDK's standard retry list (`RetryMiddlewareV2.php`) already includes all three. `CancellationReasons[].Code`: `None`, `ConditionalCheckFailed`, `ItemCollectionSizeLimitExceeded`, `TransactionConflict`, `ProvisionedThroughputExceeded`, `ThrottlingError`, `ValidationError`. The SDK does **not** retry a `TransactionCanceledException`. Sources: DynamoDB Developer Guide "Error handling", API Reference `TransactWriteItems`, "Transactions". | A18 confirmed: those three codes, and cancellations whose reasons are only `ThrottlingError` / `ProvisionedThroughputExceeded` / `TransactionConflict` / `None`, give 503 + `Retry-After`. `ConditionalCheckFailed` is a domain result; `ValidationError` is a bug (500). The code is read from `CancellationReasons`, never from the message. |
| R4 | `ClientRequestToken` on `TransactWriteItems` is an `idempotencyToken` member; the SDK's `IdempotencyTokenMiddleware` fills it in the **init** step (once per command), so every retry of that command reuses it. DynamoDB Local's handling was not tested. | Retries of the register transaction are already idempotent on AWS (10-minute window). A19's re-read rule stays as the guarantee. |
| R5 | Both images: PHP 8.5.11, defaults `m=65536, t=4, p=1`. Provider: **libargon2 in `php:8.5-fpm-alpine`, libsodium in `bref/php-85:3`**, which **refuses `threads` ≠ 1**. A hash made in either image verifies in the other. One production hash: about 0.13–0.15 s on the dev machine. | A12 stays, and `threads = 1` is now **required**, not a preference. WP6 tests the pinned values. Lambda timing is measured in Phase 8. |
| R6 | NIST SP 800-63B-4 §3.1.1.2: single-factor passwords at least **15** characters, allow at least 64, no composition rules, **SHALL** check a blocklist; §3.2.2: at most 100 consecutive failures. AAL1: absolute reauthentication ≤ 30 days, idle timeout optional. OWASP: Argon2id at least 19 MiB, t=2, p=1; idle 15–30 min for low-risk apps; `__Host-` recommended for session ids; failure counters tied to the account. | A12 (64 MiB, t=4) is above the OWASP minimum. A3 (7 days absolute) is within NIST AAL1. The owner chose 12 characters knowing NIST says 15 (decision 4). **Owner decisions, 2026-10-16:** keep the 24 h idle timeout (OWASP's 15–30 min is noted and declined for a personal tool); ~~add a local common-password blocklist in WP6~~ **withdrawn 2026-10-19**: the vendored list (SecLists) set off the company's endpoint-security alerts, so the owner removed it. The app does not meet NIST's blocklist "SHALL"; this is a known gap (section 10). |
| R7 | WHATWG Fetch "append a request `Origin` header": for a non-GET/HEAD request in `cors` mode (the `fetch()` default), `Origin` is always sent, whatever the referrer policy. It becomes `null` only in `same-origin`/`no-cors` modes with some referrer policies, in sandboxed (opaque-origin) documents, or after a cross-origin redirect. | A8 confirmed: refusing a missing or `null` `Origin` does not break the app. **Carried to Phase 4:** API calls use `fetch` with the default `mode: 'cors'`, never `same-origin` or `no-cors`. |

### Part A: Infrastructure

#### WP1: Plan (S)
- **Files:** this plan (`docs/plans/phase-2-authentication.md`).
- **Agent:** written by the main session from the planner's output. **Commit:** `docs: add phase 2 implementation plan`.

#### WP2: Clock, UUID generator, log redaction (S)
- **Files:**
  - `backend/src/Infrastructure/Clock/{Clock,SystemClock}.php`; `backend/tests/Support/FrozenClock.php` (with `advance(int $seconds)`).
  - `backend/src/Infrastructure/Id/{IdGenerator,RandomUuidGenerator,Uuid}.php`.
  - `backend/src/Infrastructure/Logging/JsonLogger.php`: the A21 key list and text patterns.
  - `backend/tests/Architecture/NoNativeSessionsTest.php`, with fixtures. It scans `src/` and `public/` with `PhpToken` and forbids `session_start`, `setcookie`, `setrawcookie`, `$_COOKIE`, `rand`, `mt_rand`, `uniqid` and `lcg_value`: cookies go only through `Response`, and randomness only through `random_bytes`.
- **Tests first (RED):**
  - `Unit/Infrastructure/Clock/SystemClockTest.php`: the time is UTC.
  - `Unit/Infrastructure/Id/RandomUuidGeneratorTest.php`: format, version nibble `4`, variant `10xx`, 1,000 unique values.
  - `UuidTest`: refuses upper case, braces, v1, the nil UUID and whitespace.
  - `JsonLoggerTest`: each new key redacted at any depth; `Cookie: a=b` and `__Host-session=<43 chars>` inside a message redacted; a `password` key inside nested context redacted.
  - `NoNativeSessionsTest`: a leaky fixture is flagged with file and line.
- **Acceptance:** green; PHPStan max clean.
- **Docker:** yes (php tools container).
- **Agents:** tdd-guide; php-reviewer; **security-reviewer (opus/high)** on the redaction (it may be batched with WP3's review).
- **Commit:** `feat: add clock, uuid generator and cookie-aware log redaction`.

#### WP3: DynamoDB in the API, 503 on overload, local wiring (S–M)
- **Files:**
  - `backend/src/Infrastructure/Config/ApiConfig.php`: holds `DynamoDbSettings`, and their errors are merged into one `InvalidConfiguration` (names only). Fail closed when `APP_ENV=local` and `DYNAMODB_ENDPOINT` is empty, so an empty value never silently means real AWS (the in-app twin of `check-env`).
  - `backend/src/Api/ApiServices.php` (a small readonly holder: `ItemStore`, `Clock`, `IdGenerator`, logger). `ApiFactory` builds the client and `ItemStore` **once per request** (php-fpm keeps no state between requests). `Routes::all(ApiServices $services)`.
  - `backend/src/Http/Middleware/StorageUnavailableMiddleware.php` (A18): logs `storage unavailable` with operation, code and AWS request id (never item data), then throws `HttpProblem::of(503, headers: ['Retry-After' => ['1']])`. `ErrorHandlingMiddleware` already keeps `Retry-After` on 5xx.
  - `backend/tests/Contract/RoutesMatchContractTest.php` and `Unit/Api/ApiFactoryTest.php`: build `ApiServices` offline (SDK `MockHandler`, no network).
  - `docker-compose.yml` (A22):
    - `api-php` environment;
    - `dynamodb` gets `networks: [default, api-internal]`;
    - rewrite the `api-php` "NEVER AWS CREDENTIALS" comment: only the fake local pair, which works against DynamoDB Local and nothing else, on a network with no route out.
  - `Makefile`: `LATE_SERVICES = api frontend`; `REQUIRED_ENV_KEYS = DYNAMODB_TABLE DYNAMODB_ENDPOINT`; refresh the comments above both.
  - `.env.example`: say that `DYNAMODB_ENDPOINT` is now required by the API.
- **Tests first (RED):**
  - `ApiConfigTest`: missing `DYNAMODB_TABLE` plus a bad `AWS_REGION` give both errors, by name; `APP_ENV=local` without an endpoint fails.
  - `ApiFactoryTest`: an invalid DynamoDB setting gives 500 on `/health`, and the log names the variable only.
  - `StorageUnavailableMiddlewareTest`: each throttling code gives 503 + `Retry-After: 1`; `AccessDeniedException` is rethrown (so it becomes 500); `TransactionCancelled([None, ThrottlingError])` gives 503; `[ConditionalCheckFailed, None]` is rethrown; the log line has no item data.
- **Acceptance:** `make up` is healthy with the new order (the API starts after `local-aws-init`); `make test-integration` is green; a stale `.env` without `DYNAMODB_ENDPOINT` makes `make up` stop with the "add this line" message.
- **Docker:** yes.
- **Agents:** tdd-guide; php-reviewer; **security-reviewer (opus/high)**: credentials in a container, the network change, error mapping. **Round 2 at opus/high.** The Makefile and Compose diff is reviewed inline if under ~30 lines.
- **Commits:** `feat: wire the dynamodb item store into the api and map overload to 503`, `chore: give api-php the local dynamodb settings and start the api after the bootstrap`.

#### WP4: CSRF middleware (S–M), security-critical
- **Behaviour:** A8. One generic `403` problem ("The request failed the cross-site request check."). A warning `csrf check failed` logs method, path and reason (`missing-header` / `bad-header` / `missing-origin` / `foreign-origin`) and the `Origin` value capped at 128 bytes. The header and Origin values are not secrets.
- **Config:** `APP_ORIGINS` (required) holds 1 to 3 comma-separated origins. Each is exactly `scheme://host[:port]`: lower case, no path, no trailing slash. `https` is required except for `localhost`, `127.0.0.1` and `[::1]`. Anything else fails closed at startup.
- **Files:**
  - `backend/src/Http/Middleware/CsrfMiddleware.php`; `ApiConfig` (+ `appOrigins`); `ApiFactory` (chain position).
  - `docker-compose.yml`: `api-php` gets `APP_ORIGINS: ${APP_ORIGINS:-http://localhost:5173,http://127.0.0.1:5173}` (the Vite proxy's own allowed origins).
  - `.env.example`: the `APP_ORIGINS` line, with a comment.
  - `ApiTestCase`: on every method except GET and HEAD, it sends `Origin` (the first entry of `APP_ORIGINS` from env, default `http://localhost:5173`) and `X-Requested-With: XMLHttpRequest` by default, with `withBrowserOrigin(?string)` and `withoutRequestedWith()` for the negative cases. A header the test sets itself wins.
  - `contract/openapi.yaml`: the `Forbidden` description also covers the CSRF check; `info.version` 0.4.0.
- **Tests first (RED):**
  - `Unit/Http/Middleware/CsrfMiddlewareTest.php`, a data provider:
    - `GET`, `HEAD` and `OPTIONS` with nothing pass;
    - `POST`, `PUT`, `PATCH`, `DELETE`, `PROPFIND` and a lower-case `post` with nothing give 403;
    - header value `xmlhttprequest`, empty, or `XMLHttpRequest, XMLHttpRequest` gives 403;
    - `Origin` missing, `null`, `http://localhost:5173/`, `http://localhost:5174`, `https://localhost:5173`, `http://LOCALHOST:5173`, `http://localhost:5173, http://evil.example`, or whitespace-padded gives 403;
    - an exact allowed origin passes;
    - the log line has the reason.
  - `ApiConfigTest`: `APP_ORIGINS` empty, 4 entries, a path, a trailing slash, `http://` on a public host, upper case.
  - `Integration/Http/CsrfApiTest.php` through nginx, with `requestAs` against `GET /health`'s documented 403/405:
    - `POST /health` without `X-Requested-With` gives 403;
    - with a foreign Origin gives 403;
    - without Origin gives 403;
    - with both headers right gives 405 (the CSRF check passed, and the router answers);
    - `GET /health` without the headers gives 200.
- **Acceptance:** green; the existing `HealthApiTest`, `OriginVerifyApiTest` and `ClientIpOverNginxTest` still pass. **Open the PR as a draft after this WP.**
- **Docker:** yes.
- **Agents:** tdd-guide; php-reviewer; **security-reviewer (opus/high)**; **round 2 at opus/high**.
- **Commit:** `feat: reject state-changing requests that fail the csrf header and origin checks`.

### Part B: Building blocks

#### WP5: Cookie parsing and session tokens (S), security-critical
- **Files:**
  - `backend/src/Http/Cookie/CookieHeader.php`, a strict parser:
    - splits on `;` and trims spaces and tabs;
    - names must be RFC 6265 tokens; values are kept raw (no URL-decoding);
    - **duplicates are kept**, so the caller can see them;
    - a header over 8 KiB parses as empty.
  - `backend/src/Auth/Session/SessionToken.php` (A2):
    - `generate()`, `fromCookieValue(string): ?self`, `hash(): string` (64 hex), `cookieValue()`;
    - `#[\SensitiveParameter]`; `__debugInfo` hides the value; there is no `__toString`; `jsonSerialize` is refused.
  - `backend/src/Auth/Session/SessionCookie.php`:
    - `NAME = '__Host-session'`;
    - `issue(SessionToken, int $maxAge): string` and `clear(): string` build the exact `Set-Cookie` values (A1);
    - `read(Request)` returns `absent | malformed | duplicate | present(token)` (A20).
- **Tests first (RED):**
  - `Unit/Http/Cookie/CookieHeaderTest.php`: the Bref join `a=1; b=2`, an HTTP/2-style merge, a duplicate name kept twice, `=` inside a value, a pair without `=` skipped, a quoted value kept raw, an empty header, an oversize header.
  - `Unit/Auth/Session/SessionTokenTest.php`:
    - 43 characters of the base64url alphabet; 1,000 unique values;
    - a known SHA-256 vector for a fixed value;
    - refuses 42 and 44 characters, `+`, `/`, `=`, non-ASCII and whitespace;
    - `var_dump`, `print_r`, `var_export` and `json_encode` never show the value.
  - `Unit/Auth/Session/SessionCookieTest.php`: the exact `Set-Cookie` strings; `read()` outcomes (`__Host-session` twice gives duplicate; `__host-session` is a different name, so absent; a malformed value gives malformed).
- **Acceptance:** 100% line coverage of the three classes.
- **Docker:** yes.
- **Agents:** tdd-guide; php-reviewer; **security-reviewer (opus/high)**; **round 2 at opus/high**.
- **Commit:** `feat: add cookie header parser and session token`.

#### WP6: Email, password policy, Argon2id hasher (S), security-critical
- **Files:**
  - `backend/src/Auth/Email.php` (A10), with an `InvalidEmail` reason for the field error.
  - `backend/src/Auth/Password/PasswordPolicy.php` (A11): `MIN_CHARS = 12`, `MAX_CHARS = 128`; `violation(string): ?PasswordViolation` (backed enum: invalid encoding, too short, too long, control character); `exceedsLoginLimit(string): bool`.
  - ~~common-password list~~ **removed (owner decision 2026-10-19):** no password list of any kind is kept in the repo, in `build/` or in the package. Never re-add one without asking the owner.
  - `backend/src/Auth/Password/PasswordHasher.php` (A12, A16):
    - `hash()`, `verify()`, `needsRehash()`, `verifyDummy()`;
    - the parameters are constructor arguments (unit tests pass cheap ones), and `production()` gives the pinned constants; an architecture test (`PasswordHasherConstructionTest`) ensures `src/` only uses `production()`;
    - `DUMMY_HASH` is a constant generated once with the production parameters.
- **Tests first (RED):**
  - `Unit/Auth/EmailTest.php`: `"  Alice@Example.COM "` gives `alice@example.com`; 254 bytes pass and 255 fail; no `@`, two `@`, a Unicode local part, a control character and NUL are refused; quoted local parts and domain literals are refused.
  - `Unit/Auth/Password/PasswordPolicyTest.php`: 11, 12, 128 and 129 characters; multibyte characters counted as one; spaces and emoji allowed; `\x00`, `\x1F` and `\x7F` refused; invalid UTF-8 refused; login checks only the maximum length.
  - `Unit/Auth/Password/PasswordHasherTest.php`:
    - the production hash starts with `$argon2id$v=19$m=65536,t=4,p=1$`;
    - verify true and false;
    - `needsRehash` is true for a hash with older parameters;
    - **`password_get_info(DUMMY_HASH)` equals the production parameters**, so changing the cost without regenerating the dummy fails the test.
  - One `slow`-group test (`make test-slow-unit`, also run by `make test` unless `FAST`): one production hash takes under 2 s in the tools container; the measured time is written to `build/tmp/argon2-timing.txt` for the WP review.
- **Acceptance:** green; the measured time is recorded in the WP notes (input for Phase 7 sizing).
- **Docker:** yes.
- **Agents:** tdd-guide; php-reviewer; **security-reviewer (opus/high)**; **round 2 at opus/high**.
- **Commit:** `feat: add email normalisation, password policy and argon2id hasher`.

### Part C: Storage (DynamoDB Local)

#### WP7: Users and email locks (S–M)
- **Files:**
  - `backend/src/Auth/AuthKeys.php`: `user(string $userId)` and `emailLock(Email)`. Ids are checked with `Uuid::isValid`, otherwise `InvalidArgumentException`. WP8 and WP9 add `session()` and `throttle()`. **Keys always take the user id from the session principal or a freshly generated id, never from the request.**
  - `backend/src/Auth/User/{User,UserRepository,EmailTaken}.php`:
    - `User` hides `passwordHash` from `__debugInfo`;
    - `create(User)` is one `transactWrite`: put `USER#<id>`/`PROFILE` and put `EMAIL#<email>`/`EMAIL`, both `attribute_not_exists(PK)`, **items built field by field**;
    - on `ConditionalCheckFailed` the lock is re-read (A19): our id is a success, anything else is `EmailTaken`; other cancellation reasons are rethrown (WP3 maps them to 503);
    - `findByEmail()`, `findById()`, `updatePasswordHash()` (`attribute_exists(PK)`);
    - an item with a missing or wrongly typed attribute is a storage error (500), never trusted.
  - Item attributes: `userId`, `email`, `passwordHash`, `createdAt` (ISO 8601 UTC).
- **Tests first (RED):**
  - `Unit/Auth/AuthKeysTest.php`: refuses a non-UUID, upper case and an empty id. A local part with `#` is fine, because the key is never parsed back.
  - `Integration/Auth/UserRepositoryTest.php` on `TemporaryTable`:
    - create, then find by email and by id;
    - the same email in other letter case gives `EmailTaken`, and no second user item exists;
    - **calling `create` twice with the same `User` succeeds** (the retry rule);
    - an unknown email gives null;
    - `updatePasswordHash` on a missing user gives `ConditionFailed`;
    - a corrupt item gives a storage error.
- **Acceptance:** green; a route reaches the `ItemStore` built by `ApiFactory` (proves the wiring).
- **Docker:** yes.
- **Agents:** tdd-guide; php-reviewer; **security-reviewer (opus/high)** (DB writes, enumeration); **round 2 at opus/high**.
- **Commit:** `feat: add user repository with a transactional email lock`.

#### WP8: Sessions and the route guard (S–M), security-critical
- **Files:**
  - `AuthKeys::session(string $tokenHash)` (64 lower-case hex only).
  - `backend/src/Auth/Session/`:
    - `Session.php`;
    - `SessionLifetime.php`: `IDLE = 86400`, `ABSOLUTE = 604800`, `RENEW_AFTER = 900`;
    - `Principal.php` (`userId`, `tokenHash`; attribute `auth.principal`);
    - `SessionRepository.php`:
      - `create(userId)`: a conditional put, with the A19 re-read, and one new token on a real collision;
      - `find(hash, now)`: valid only while `expiresAt > now`, `absoluteExpiresAt > now` and the `userId` is a valid UUID;
      - `renew()`: condition `attribute_exists(PK) AND expiresAt > :now`; new `expiresAt = min(now + IDLE, absoluteExpiresAt)`;
      - `delete()`;
    - `SessionAuthenticator.php`: Request → `Anonymous(reason)` or `Authenticated(Principal)`. A duplicate cookie gives a warning and **no read**. A failed renewal is logged and is not fatal; a renewal `ConditionFailed` means the session is gone, so anonymous;
    - `RequireSession.php`: a `Handler` decorator. Anonymous gives `401` + `WWW-Authenticate: Session`, plus a clearing cookie if a cookie was sent. Authenticated sets the principal attribute and `RequestLogContext::recordUserId()`.
  - `backend/src/Http/RequestLogContext.php` and `Middleware/RequestLoggingMiddleware.php`: `userId` in the request line (write-once, like `clientIp`).
  - Session item: `PK = SESSION#<hash>`, `SK = SESSION`, `userId`, `createdAt`, `renewedAt`, `absoluteExpiresAt`, `expiresAt` (all epoch seconds as Numbers; TTL needs a Number).
- **Tests first (RED):**
  - `Unit/Auth/Session/SessionAuthenticatorTest.php` (an in-memory fake repository plus `FrozenClock`):
    - absent, malformed and duplicate cookies are anonymous **with zero repository calls**;
    - an unknown hash is anonymous;
    - an item still present with `expiresAt` in the past (TTL lag) is anonymous;
    - the absolute cap reached right after a renewal is anonymous;
    - no renewal before 15 min, one after;
    - renewal never goes past the absolute cap;
    - renewal `ConditionFailed` is anonymous.
  - `Unit/Auth/Session/RequireSessionTest.php`: the 401 problem with its headers; the inner handler is not called; on success the attribute and `userId` are recorded.
  - `Integration/Auth/SessionRepositoryTest.php` on `TemporaryTable`: create, find, renew, delete; a `create` replayed with the same item is a success; the stored types are Numbers.
- **Docker:** yes.
- **Agents:** tdd-guide; php-reviewer; **security-reviewer (opus/high)**; **round 2 at opus/high**.
- **Commit:** `feat: add server-side sessions and the require-session route guard`.

#### WP9: Throttle counters (S–M), security-critical
- **Files:**
  - `AuthKeys::throttle(string $scope, string $key)`.
  - `backend/src/Auth/Throttle/`:
    - `ThrottleRule.php` (scope, limit, window) and the A13 constants;
    - `ThrottleCounter.php`: A15 increment, reset and refund, plus a `delete`;
    - `ThrottleDecision.php`: allowed, or blocked with `retryAfterSeconds` (≥ 1, rounded up);
    - `NetworkPrefix.php`: the IPv6 `/48` from the canonical address; null for IPv4 and unknown;
    - `LoginThrottle.php`: `attempt(Email, ClientAddress)` increments `LOGIN-EMAIL`, `LOGIN-IP` and, for IPv6, `LOGIN-NET`, and returns the strictest decision; `succeeded(...)` deletes the account bucket and refunds the address buckets;
    - `RegisterThrottle.php`: `REGISTER-IP` and `REGISTER-NET`.
- **Tests first (RED):**
  - `Unit/Auth/Throttle/NetworkPrefixTest.php`: `2001:db8:1:2::1` gives `2001:db8:1::/48`; `::1` gives `::/48`; IPv4 and `unknown` give null.
  - `Unit/Auth/Throttle/LoginThrottleTest.php` (a fake counter):
    - one bucket over its limit blocks;
    - `Retry-After` is the largest remaining time;
    - the decision does not depend on whether the account exists (the throttle never asks);
    - success deletes and refunds;
    - the `unknown` address is one shared bucket (ADR 0012).
  - `Integration/Auth/ThrottleCounterTest.php` on `TemporaryTable` with `FrozenClock`:
    - the first hit creates `attempts = 1` and `expiresAt = now + window`;
    - hit N+1 is blocked with the right `Retry-After`;
    - after the clock passes the window, with the item still present (TTL lag), the count resets to 1;
    - **reset race:** with an expired item seeded, two counters reset, and the total is 2, not 1;
    - a refund never goes below 0;
    - `expiresAt` is a Number.
- **Docker:** yes.
- **Agents:** tdd-guide; php-reviewer; **security-reviewer (opus/high)**; **round 2 at opus/high**.
- **Commit:** `feat: add fixed-window login and registration throttling on dynamodb`.

### Part D: Endpoints (contract first)

#### WP10: `POST /auth/register` (S–M), security-critical
- **Contract** (`contract/openapi.yaml`, then `make contract-gen`), in this WP because this is the first endpoint that uses these components:
  - `components.securitySchemes.sessionCookie` (`apiKey`, `in: cookie`, `name: __Host-session`);
  - headers `SetSessionCookie` (required), `ClearSessionCookie`, `RetryAfter` (required), `WWWAuthenticate`;
  - schemas `User` (`id` uuid, `email`; `additionalProperties: false`), `Credentials` (exactly `email` and `password`; `additionalProperties: false`), `ValidationProblemDetails` (`ProblemDetails` + `errors: [{pointer, detail}]`, a carried item);
  - responses `BadRequest`, `ContentTooLarge`, `UnsupportedMediaType`, `ValidationFailed` (422), `Conflict`, `TooManyRequests`, `ServiceUnavailable`;
  - a header parameter `X-Requested-With` (required, `const: XMLHttpRequest`) on every state-changing operation; `Origin` is described in text (browsers set it, JavaScript cannot);
  - `/auth/register`, `post`: `security: []`; responses 201 (`User`, `Set-Cookie`), 400, 403, 405, 409, 413, 415, 422, 429, 500, 503.
- **Contract tests:**
  - `EveryOperationDocumentsErrorsTest` is extended: an operation with a request body documents 400/413/415/422; every operation except `/health` documents 503; every state-changing operation declares `X-Requested-With`.
  - New `EveryOperationDeclaresSecurityTest`: there is no global default, and every operation says `security` explicitly (fail closed).
  - `backend/tests/Support/Contract/OpenApiDocument.php` gains `security()`, `hasRequestBody()` and `headerParameters()`.
- **Files:**
  - `backend/src/Auth/Http/CredentialsInput.php`: from `JsonBody::decode` (its first use), exactly `email` and `password`, both strings. An unknown key, null, an array or a numeric-key object gives a `FieldError` with pointer `/email`, `/password` or `/<key>`. Values are never echoed. Control characters are refused.
  - `backend/src/Auth/Http/RegisterHandler.php`, in order:
    1. the register throttle (429 before any hashing);
    2. email and policy checks (422);
    3. hash;
    4. `UserRepository::create` (409 on `EmailTaken`);
    5. delete the session the request carried, if any;
    6. create a session;
    7. `201` + `Set-Cookie`;
    8. log `user registered` with `userId` (never the email).
  - `Routes.php`: `POST /auth/register`.
  - `ApiTestCase`:
    - `sessionCookieFrom(ResponseInterface)` and `withSessionCookie(?string)`;
    - a **random `CloudFront-Viewer-Address` per test** (`[2001:db8:<rand>:<rand>::1]:<port>`), so throttle buckets never leak between tests; `withoutViewerAddress()` for `ClientIpOverNginxTest`'s "nginx fills it" case (update that test).
- **Tests first (RED):**
  - `Unit/Auth/Http/CredentialsInputTest.php`.
  - `Unit/Auth/Http/RegisterHandlerTest.php` (fakes):
    - throttled gives 429 and **the hasher is not called**;
    - `EmailTaken` gives 409;
    - the replay case gives 201;
    - a carried session is deleted;
    - `TransactionCancelled([ThrottlingError, None])` reaches WP3's middleware as 503.
  - `Integration/Auth/RegisterApiTest.php` (all through `request()`):
    - 201, contract-valid, with the exact cookie attributes;
    - the same email in upper case gives 409;
    - a short password gives 422 with pointer `/password`;
    - `text/plain` gives 415;
    - a body over 64 KiB gives 413;
    - no `X-Requested-With` gives 403;
    - the 6th registration from one viewer address gives 429 with `Retry-After`.
- **Docker:** yes.
- **Agents:** tdd-guide; php-reviewer; **security-reviewer (opus/high)**; **round 2 at opus/high**.
- **Commits:** `feat: document post /auth/register in the contract`, `feat: add post /auth/register`.

#### WP11: `POST /auth/login` (S–M), security-critical
- **Contract:**
  - `components/responses/Unauthorized` (with `WWW-Authenticate`);
  - `/auth/login`, `post`: `security: []`; responses 200 (`User`, `Set-Cookie`), 400, 401, 403, 405, 413, 415, 422, 429, 500, 503;
  - regenerate `schema.gen.ts`.
- **Files:** `backend/src/Auth/Http/LoginHandler.php`; `Routes.php`. The flow:
  1. `CredentialsInput` (shape only, plus `exceedsLoginLimit`) gives 422 on failure.
  2. `LoginThrottle::attempt` (increment first). If blocked: 429 + `Retry-After`, **without verifying the password**.
  3. `findByEmail`. If there is no user: `verifyDummy` plus one dummy `GetItem` (A16), then 401.
  4. `verify`. If it fails: 401 "Invalid email or password." (the same body and headers as step 3).
  5. On success:
     - `succeeded()` (reset and refunds);
     - `needsRehash` → `updatePasswordHash`, best effort and logged;
     - delete the carried session;
     - create a session;
     - `200 {id, email}` + `Set-Cookie`.
  6. Log `login succeeded` / `login failed` with `userId` when known and a reason category, never the email or password.
- **Tests first (RED):**
  - `Unit/Auth/Http/LoginHandlerTest.php` (fakes):
    - an unknown email calls `verifyDummy` and the dummy read, and its response is **byte-identical** to the wrong-password one (status, headers, body);
    - when blocked, `verify` is never called, even with the right password;
    - success deletes the old session, resets the account bucket and refunds the address buckets;
    - `needsRehash` triggers the update; a failed update still logs the user in.
  - `Integration/Auth/LoginApiTest.php`:
    - login after register gives 200 and a **different** cookie;
    - a wrong password gives 401 + `WWW-Authenticate`;
    - an unknown email gives the same 401 body;
    - 11 failures on one email from 11 different viewer addresses: the 11th gets 429 (account bucket);
    - 31 failures from one address on 31 different emails: the 31st gets 429 (address bucket);
    - a `slow` test: 101 failures from distinct `/64`s inside one `/48` give 429 (`/48` tier).
- **Docker:** yes.
- **Agents:** tdd-guide; php-reviewer; **security-reviewer (opus/high)**; **round 2 at opus/high**.
- **Commits:** `feat: document post /auth/login in the contract`, `feat: add post /auth/login with throttling and session rotation`.

#### WP12: `GET /auth/me` and `POST /auth/logout` (S), security-critical
- **Contract:**
  - `/auth/me`, `get`: `security: [{sessionCookie: []}]`; responses 200 (`User`), 401, 403, 405, 500, 503.
  - `/auth/logout`, `post`: `security: []`, no request body; responses 204 (`ClearSessionCookie`), 403, 405, 500, 503.
  - New `ProtectedRoutesMatchContractTest`: the routes whose handler is a `RequireSession` equal the operations with a non-empty `security`.
- **Files:**
  - `backend/src/Auth/Http/MeHandler.php`: reads `USER#<principal.userId>`. A missing user gives 401 and the session is deleted.
  - `backend/src/Auth/Http/LogoutHandler.php` (A5).
  - `Routes.php`: `me` wrapped in `RequireSession`.
- **Tests first (RED):**
  - Add `/auth/me` to `Routes.php` **without** the guard and see `ProtectedRoutesMatchContractTest` fail; then wrap it.
  - Unit tests for both handlers.
  - `Integration/Auth/SessionApiTest.php`:
    - register, then `me` gives 200 with the same id and email;
    - logout gives 204 + a clearing cookie, and `me` with the old cookie gives 401 + `WWW-Authenticate` + a clearing cookie;
    - logout with no cookie gives 204;
    - logout without `X-Requested-With` gives 403 (forced logout refused);
    - two `__Host-session` cookies give 401;
    - after a login, the cookie issued by register gives 401 (rotation).
- **Docker:** yes.
- **Agents:** tdd-guide; php-reviewer; **security-reviewer (opus/high)**; **round 2 at opus/high**. Review checklist includes rule A8: no `GET` / `HEAD` / `OPTIONS` handler changes state (`me` only reads; logout is a `POST`).
- **Commits:** `feat: document get /auth/me and post /auth/logout`, `feat: add get /auth/me and post /auth/logout`.

### Part E: End to end and wrap-up

#### WP13: End-to-end flow, throttle race, log hygiene (S–M)
- **Files:**
  - `backend/tests/Integration/Auth/AuthFlowApiTest.php`: register → me → logout → me (401) → login → me, all contract-validated.
  - `backend/tests/Integration/Auth/LoginThrottleRaceTest.php`: a Guzzle `Pool` sends 25 concurrent wrong-password logins for one email, from distinct viewer addresses. It expects **exactly 10 × 401 and 15 × 429** (A15: increment first).
  - Both tests write canonical markers to `build/auth-test/markers.json`: the test password, one issued session token, and the user id.
  - `Makefile` `test-integration` (following the `CLIENT_IP_MARKERS` pattern), against the `api-php` logs since the start of the run:
    - the marker password and the marker token **do not appear**;
    - a request line for `/api/v1/auth/me` carries `"userId":"<marker id>"`;
    - a `csrf check failed` line exists for the CSRF test.

    Each check fails with a specific, loud message.
- **Acceptance:** `make test-integration` is green twice in a row, with no flake.
- **Docker:** yes.
- **Agents:** tdd-guide; php-reviewer; Makefile diff inline if under ~30 lines, otherwise code-reviewer (sonnet/medium); **security-reviewer (opus/high)**, limited to the race and hygiene assertions.
- **Commit:** `test: end-to-end auth flow, throttle race and log hygiene checks`.

#### WP14: Docs, ADR notes and wrap-up (S)
- **Files:**
  - `docs/local-dev.md`:
    - curl for register, login, me and logout, with a cookie jar (`-c` / `-b`), `X-Origin-Verify`, `Origin` and `X-Requested-With`;
    - what a 429 looks like, and that `make down && make up` clears all data (in memory);
    - existing clones must add `DYNAMODB_ENDPOINT` and `APP_ORIGINS` to `.env`;
    - the R1 browser note.
  - `README.md` quick start.
  - "Implemented in Phase 2" notes (clarifications, drafted by the main session from the WP results):
    - **ADR 0004:** A1–A6, A8, A9, A11–A13, A15, A16.
    - **ADR 0002:** the user, email-lock, session and throttle item shapes (A15's scoped keys, hashed email).
    - **ADR 0003:** authentication as a route guard; CSRF position (A7, A8).
    - **ADR 0012:** the `/48` tier next to `/64` (A14).
    - **ADR 0007:** the accepted risk that `api` and `dynamodb` can reach php-fpm on 9000 (carried from WP3, R13): reasons in R13; revisit if a non-AWS image joins `api-internal`.
  - PLAN §12 Phase 2 status → Done; this plan's status → Done.
- **Haiku output is fact-checked:** doc-updater (haiku/low) drafts. Before the commit, the main session checks:
  - every make target against `make help`;
  - every path with Glob;
  - every env var against `.env.example` and `docker-compose.yml`;
  - **every curl command, by running it.**
- **Before the PR is marked ready:** `/update-docs`, `/code-review`, `/verification-loop`; then `/ecc:cost-report` (compare with Phase 1).
- **Docker:** yes (only to verify the commands).
- **Commit:** `docs: phase 2 local-dev, readme and adr notes`.

## 4. Dependencies

```
WP1 ─► R ─► WP2 ─► WP3 ─► WP4 ───────────────────────────┐
              ├──► WP5 ──────────► WP8 ──┐               │
              ├──► WP6 ──┐               ├─► WP10 ─► WP11 ─► WP12 ─► WP13 ─► WP14
              ├──► WP7 ◄─┘ (Email)       │
              └──► WP9 ──────────────────┘
```
- WP10 needs WP3 (wiring, 503), WP4 (CSRF and `ApiTestCase` defaults), WP6, WP7, WP8 and WP9.
- WP12 needs WP8 (guard) and WP10/WP11 (to have users and sessions to test with).
- WP3, WP4 and WP10 all touch `docker-compose.yml`, `.env.example` or `ApiTestCase`, so they run one after another.

## 5. Test plan

| Layer | What | Where | Run by |
|---|---|---|---|
| Unit | Clock, UUID, redaction, CSRF, cookie parser, token, email, policy, hasher, authenticator, guard, throttle policy, prefix, handlers (with fakes), storage-unavailable mapping, config | `backend/tests/Unit/**` | `make test-unit` (CI `backend`) |
| Architecture | No native sessions, `$_COOKIE`, `setcookie` or weak randomness in `src/` and `public/` (+ the Bref boundary) | `backend/tests/Architecture/**` | `make test-unit` |
| Contract | Route parity; every operation declares `security`; guarded routes = secured operations; documented 401/403/409/422/429/500/503 and body errors; the CSRF header parameter | `backend/tests/Contract/**` | `make test-contract` (CI `integration`) |
| Integration (storage) | `UserRepository`, `SessionRepository`, `ThrottleCounter` on `TemporaryTable` with `FrozenClock` (TTL lag, reset race, retry replays) | `backend/tests/Integration/Auth/*RepositoryTest.php`, `ThrottleCounterTest.php` | `make test-integration` |
| Integration (API) | CSRF, register, login, me, logout, the whole flow, throttle race, through nginx, contract-validated | `backend/tests/Integration/{Http,Auth}/**` | `make test-integration` |
| Log hygiene | No password or token in `api-php` logs; `userId` on authenticated request lines | `Makefile` recipe + markers | `make test-integration` |
| Front-end | Unchanged; `schema.gen.ts` regenerated, and `vue-tsc` catches drift | `frontend/` | `make frontend-test`, `frontend-lint` |

- **Coverage:** ≥ 80% lines on `backend/src` (enforced by `coverage:check`). Target 100% on `CsrfMiddleware`, `CookieHeader`, `SessionToken`, `SessionCookie`, `SessionAuthenticator`, `RequireSession`, `ThrottleCounter` and `NetworkPrefix`.
- **E2E:** the approved deviation (PLAN §12) still applies. The curl smoke test is Phase 6, Playwright is L1.
- **Speed:** unit tests use cheap Argon2 parameters. Only one `slow` test measures the production cost, and only the `/48` test needs about 100 hashes (also `slow`; `make test-fast` skips both).

## 6. Branch, PR, CI and owner gates

- **Branch** `feat/phase-2-authentication`. **One PR for Phase 2**, opened as a **draft after WP4**, marked ready after WP14, and **squash-merged**. Body: definition-of-done checklist, the R1–R7 results, test plan.
- **CI:** `main` requires 7 checks. Phase 2 **adds no job**: new tests run inside `backend`, `contract` and `integration`. If implementation does add or rename a job, the owner updates the ruleset by hand (the main session gives the click steps and only runs read-only `gh api` checks).
- **Deletions:** none planned. Any deletion is asked separately.
- **Owner gates:**
  - **G1, approve this plan**, including the answers to section 10.
  - **G2, only if a design point would change an accepted ADR** (for example, if the owner reads A3, A7, A9 or A14 as a change): ADR 0013 is written and accepted before the WP that needs it.
  - **G3, PR merge**, including the "Implemented in Phase 2" notes.
  - **G4, only if a CI job is added or renamed:** ruleset update.
  - **AWS:** no gate is needed, because **Phase 2 creates or deploys nothing on AWS ($0)**.

## 7. Not in Phase 2, and traceability of the carried items

### 7.1 Everything carried from Phase 1 (its section 6) → where it lands

| Carried item (Phase 1 plan, section 6) | Source | Phase 2 |
|---|---|---|
| Authentication and CSRF go in the chain after `RequestLogging` | Not in Phase 1 | WP4 (CSRF middleware, after `ClientIp`); WP8 (authentication as the `RequireSession` route guard, A7; a clarification recorded in WP14) |
| Login throttling consumes `ClientAddress::throttleKey` | Not in Phase 1 | WP9, WP11 |
| First use of `JsonBody` and `ValidationProblem`; add `ValidationProblemDetails` to the contract | Not in Phase 1 | WP10 |
| `Clock` and the UUID generator | Not in Phase 1 | WP2 |
| Key builders for user, email lock, session, throttle | Not in Phase 1 | WP7 (user, email), WP8 (session), WP9 (throttle) |
| Wire `ItemStore` into `ApiFactory`; DynamoDB env + fake key pair in `api-php`; `LATE_SERVICES = api frontend` | Not in Phase 1 | WP3 |
| Bref rebuilds `Cookie` from `cookies[]`: define which cookie wins, or reject duplicates | WP3 security notes | WP5 (parser keeps duplicates), WP8 (duplicates = anonymous, A20) |
| Reject control characters in string fields; never use `array_is_list` to tell objects from arrays | WP3 security notes | WP6 (email, password), WP10 (`CredentialsInput`) |
| Route ids validated as UUIDs before building keys | WP3 security notes | No route parameters in Phase 2. Keys validate ids (WP7). Still applies in Phase 3 |
| Add `cookie`, `set-cookie`, `x-origin-verify`, `x-csrf-token` to `JsonLogger` redaction | WP4 notes | WP2 (plus `password`, `passwordHash` and text patterns) |
| Never log `$_SERVER`; never read `HTTP_PROXY` | WP4, WP6 notes | Kept: WP3's new env (fake keys) makes this matter more; checked in every security review |
| Optional `X-Request-Id` response header | WP4 notes | Still not planned |
| A coarser IPv6 tier (`/56` or `/48`) next to `/64` | WP7 notes | WP9 (`/48`, A14) |
| A per-account limit, not only per address | WP7 notes | WP9, WP11 (`LOGIN-EMAIL`, A13) |
| CloudWatch metric filter and alarm on `client address unavailable` | WP7 notes | **Deferred to Phase 7.** It is Terraform (log metric filter + alarm next to the explicit log groups); Phase 2 has no AWS |
| Key builders take PK from the session principal, never from the request | WP9 notes | WP7, WP8, WP12 |
| Creates use `attribute_not_exists(PK)` and build items field by field | WP9 notes | WP7, WP8, WP9 |
| Register must not turn `TransactionCancelled` reasons into an enumeration signal beyond what the product decides | WP9 notes | WP7, WP10 (only `409` on a taken email, A9; throttling reasons become 503) |
| `ProvisionedThroughputExceeded` / `ThrottlingException` / `RequestLimitExceeded` → 503 + `Retry-After` | WP9 notes | WP3 (A18) |
| A conditional put retried after a timeout may be a false duplicate: re-read first | WP9 notes | WP7 (register), WP8 (session), A19 |
| `check-env` requires `DYNAMODB_ENDPOINT` once `api-php` uses DynamoDB | WP9 notes | WP3 (also fails closed in `ApiConfig` when `APP_ENV=local`) |

### 7.2 Not in Phase 2 (with the reason)
- **Front-end pages** (register, login, route guards): Phase 4. Carried to Phase 4:
  - every non-GET call sends `X-Requested-With: XMLHttpRequest`;
  - `fetch` keeps the default `credentials: 'same-origin'`;
  - a 401 goes to Login;
  - a 429 shows "try again in N seconds" from `Retry-After`;
  - the R1 Safari note.
- **Sign out everywhere / a list of sessions:** needs a per-user session index; not in the BRIEF.
- **Change password, reset password, account deletion:** not in the BRIEF (email flows are out of scope).
- **Online breached-password lookup and CAPTCHA:** both are third-party calls (ADR 0012). A local list of common or leaked passwords was also dropped (owner decision 2026-10-19: password lists set off the company's endpoint-security alerts).
- **Unicode normalisation of passwords:** needs `ext-intl` in both images. Passwords are compared byte for byte.
- **MFA:** not in the BRIEF.
- **Phase 6:** `scripts/smoke.sh` sends `Origin` and `X-Requested-With` and keeps a cookie jar.
- **Phase 7, Terraform:**
  - API Lambda env gets `APP_ORIGINS` = the CloudFront origin. **Watch for a dependency cycle:** Lambda env → distribution domain → API Gateway origin → Lambda integration. Break it with a custom domain, a two-step apply, or by reading the domain at cold start. To decide in the Phase 7 plan.
  - API Lambda memory sized for Argon2id (at least 512 MB suggested; WP6 timing as input).
  - The `client address unavailable` metric filter and alarm.
  - The origin request policy forwards `Origin`, `X-Requested-With` and all cookies (already in ADR 0012 §4.1).
  - IAM covers `TransactWriteItems` through its item actions (Put/ConditionCheck).
- **Phase 8, verify on AWS:**
  - several `Set-Cookie` headers survive Bref → API Gateway v2 → CloudFront;
  - `Cookie` is rebuilt as R2 found;
  - the `__Host-` cookie is set on the `*.cloudfront.net` domain;
  - login latency with Argon2id at the chosen Lambda memory;
  - a forged `Origin` through CloudFront gets 403;
  - measure the per-request cost of building the DynamoDB client (it is built on every request; the SDK resolves credentials lazily).

### 7.3 Review notes
As in Phase 1, the findings of each WP's reviews (and anything carried to later phases) are added here as "Notes from the WPn reviews", before the next WP starts.

### Notes from the WP2 reviews (2026-10-16)

From ecc:php-reviewer (sonnet) and ecc:security-reviewer (opus/high) on `bbf1d76..0fe491b`; round 2 (opus/high) on `0fe491b..042b8e9` approved. No CRITICAL findings. The fix batch covers:
- objects in log context: a `JsonSerializable` is redacted like any value (at most 8 levels; a throwing `jsonSerialize()` falls back to the class), any other object logs only `{"class": …}`;
- key matching normalises before comparing (lower case, `_` → `-`, one leading `http-` stripped), anchored `\A…\z`, plus `current-password` / `new-password`; string array keys go through the text rules;
- text rules: a `Cookie:` / `Set-Cookie:` / `HTTP_COOKIE:` line is redacted to end of line; `__Host-session%3D…`; Argon2 hashes;
- the native-session guard (HIGH from php-reviewer) flags names anywhere (not only before `(`), `use function` imports, string callables, `header('Set-Cookie: …')`, every `session_*` function, `array_rand` / `shuffle` / `str_shuffle` / `srand` / `mt_srand`, `Mt19937`, `getallheaders`, `INPUT_COOKIE`, `$_SESSION`, `$_REQUEST`, `$GLOBALS['_COOKIE']`;
- weak tests replaced (a `json_decode` check that could not fail, a self-comparing clock test); `FrozenClock` normalises to UTC; `RandomUuidGenerator::fromBytes` tested with fixed vectors.

Optional LOWs from round 2, not done:
- `password_hash` as a key normalises to `password-hash`, not matched (the Argon2 text rule still hides the value): use `password-?hash`.
- The `Authorization` text rule still stops at `,` and `"` (Digest / SigV4 tails).
- `\s*` in the cookie rules also crosses CR/LF (over-redaction only): `[ \t]*`.
- The Argon2 text rule runs to the next whitespace (can hide a following `,userId=…`).
- `use const INPUT_COOKIE as C`, `$GLOBALS['_SESSION']` / `['_REQUEST']`, `apache_request_headers`, the seedable engines `Xoshiro256StarStar` / `PcgOneseq128XslRr64`, and an interpolated `"Set-Cookie: $v"` are not caught by the guard.
- The exact `session_*` string match would flag an array key or named argument such as `'session_id'`: the code uses camelCase and `SESSION#`, so it is fine; never whitelist broadly.

Carried (do when it first applies):
- **Enums and dates in log context** now log as `{"class": …}`. Before logging the first enum (a `reason` or `status`) or date, map `BackedEnum` → `->value`, `UnitEnum` → `->name`, `DateTimeInterface` → `format(DATE_ATOM)`.
- **JSON inside a string:** there is no text rule for `"password":"…"` / `"cookie":"…"` already encoded into a string. Never log a raw request body; if one is ever needed, add a quoted-key rule first.

### Notes from the WP3 reviews (2026-10-16)

From ecc:php-reviewer (sonnet) and ecc:security-reviewer (opus/high) on `e2e5c07..42cf690`; round 2 (opus/high) on `42cf690..d0c1b68` approved; the last LOW (`d0c1b68..ab22cc7`, under 30 lines) was reviewed inline by the main session. No CRITICAL findings. The fix batch covers:
- **Shell AWS keys (security MEDIUM):** Compose takes `AWS_*` from the caller's shell before `.env`, so exported real keys would have reached `api-php`, whose request code can read its environment. `COMPOSE` in the Makefile now starts with `env -u AWS_ACCESS_KEY_ID -u AWS_SECRET_ACCESS_KEY -u AWS_SESSION_TOKEN -u AWS_PROFILE`; `scripts/check-env.sh` (tested by `scripts/tests/check-env.test.sh`) refuses a key pair other than the fake one from `.env.example`, any session token, and any credential line not written as `KEY=value` (Compose also reads `KEY: value` and `KEY = value`, checked with `docker compose config`). Messages name the variable, never the value. `docs/local-dev.md`'s direct `docker compose run` example carries the same prefix.
- **"Built once per process" was false (php-reviewer HIGH):** under php-fpm, locally and with Bref FPM on Lambda, every request starts with fresh PHP state, so no static cache can carry the client across requests (real reuse would need `BREF_RUNTIME=function`, an ADR 0012 change). Comments and this plan now say "once per request"; no cache was added. WP6's common-password list was to load only when a registration checks a password (the list was later removed, 2026-10-19). Phase 8 measures the per-request cost.
- `APP_ENV` compared as `strtolower(trim(…))`; a whitespace-only `DYNAMODB_ENDPOINT` counts as empty.
- Weak tests replaced: the "no item data in the log" check now plants the note where it could leak; a near-tautological wiring test was removed (WP7 proves the wiring).
- **Accepted risk (R13):** `dynamodb` on `api-internal` can reach php-fpm on 9000 (FastCGI has no authentication). Using that path needs code execution inside AWS's digest-pinned DynamoDB Local image; the target is read-only, non-root, `cap_drop: ALL`, holds only the fake keys, and has no route out. `listen.allowed_clients` would need fixed subnets that could clash with a developer's network. Revisit if a non-AWS image ever joins `api-internal`. WP14 records this in an ADR 0007 note.

Carried:
- **WP7:** `backend/tests/Support/Api/OfflineServices.php` is offline only for construction. A store call through it hangs, because the SDK's default credential chain falls through to the instance metadata service (the Phase 1 lesson again). Before WP7 uses it, give the client explicit static fake credentials (and keep `AWS_EC2_METADATA_DISABLED=true`).
- **Agent briefs:** a WP3 fix agent killed debugging containers by an image filter. Only its own were running, but on the shared host that filter could hit another project. Every brief now says: kill or remove only containers you started, **by name**.

### Notes from the WP4 reviews (2026-10-16)

From ecc:php-reviewer (sonnet) and ecc:security-reviewer (opus/high) on `ca0cd89..80cc69e`. Both approved with **no CRITICAL, HIGH or MEDIUM** findings. The security reviewer could not get a cross-site state-changing request through: methods are matched exactly by the router (Bref upper-cases them on Lambda, nginx refuses lower case locally), nothing reads `X-HTTP-Method-Override`, the API never sends `Access-Control-*` headers (so a foreign page can never make the browser send `X-Requested-With`), repeated headers arrive comma-joined and are refused, and the only `GET`/`HEAD` route is `/health`. It agreed with ADR 0004 and A8 that `SameSite=Lax` + `X-Requested-With` + exact `Origin` needs no synchronizer token here, login and register included (they are `POST`s behind the global middleware).

The LOW fix batch covers: `APP_ORIGINS` refuses default ports (`:80` on http, `:443` on https), leading-zero ports and over-long host labels (browsers never send those forms, so such an entry would refuse every real `POST`); the CSRF integration tests assert the problem `detail`, so each 403 provably comes from this check; the contract's `Forbidden` description is reworded; rule A8 now states that no `GET`/`HEAD`/`OPTIONS` handler may change state, and WP12's review checks it.

Optional, not done: a separate `null-origin` log reason (today `Origin: null` logs as `foreign-origin`); refusing `Sec-Fetch-Site: cross-site` as extra defence (needs CloudFront to forward it); a raw integration request with two `Origin` lines (unit tests cover the comma-joined form, and both nginx and API Gateway join repeated headers).

### Notes from the WP5 reviews (2026-10-19)

From ecc:php-reviewer (sonnet) and ecc:security-reviewer (opus/high) on the uncommitted WP5 diff (base `698eead`); round 2 (opus/high) on the fix hunks approved, and confirmed each new test fails without its fix. Both approved with **no CRITICAL, HIGH or MEDIUM** findings. The security reviewer could not make `read()` return `present(attacker)` while the victim's cookie is also sent: names are compared byte for byte (`__host-session`, `__HOST-session`, `__Host%2Dsession` and `name =v` are other names or skipped), values are never URL-decoded, the header is split only on `;` (a `,`-joined value can never pass the 43-character check), and every join point (Bref payload v2, nginx since 1.23, HTTP/2 cookie crumbs) joins with `"; "`, so a second `__Host-session` always arrives as its own pair and gives `duplicate`. An oversize header (over 8 KiB) gives `absent`, which fails closed. `zend.exception_ignore_args = On` in both `app.ini` files keeps arguments out of traces.

`SessionToken` keeps its value in a private static `WeakMap` keyed by the instance, not in a property. Both reviewers agreed to keep this: `var_export`, `(array)` and `get_object_vars` ignore `__debugInfo`, so a private property would leak through them. Deliberate `Reflection` on the static can still read it (out of scope, as for any private property).

The LOW fix batch covers: `#[\SensitiveParameter]` on every parameter that receives the raw cookie header or token (`CookieHeader::parse` and its pair helper, `SessionCookie`'s builder), checked by a reflection test; `cookieValue()` fails with a clear `LogicException` when the token has no value; member order in `SessionToken`; a comment on the `SessionCookieRead` invariant; tests for two distinct live tokens and for a `;` inside a quoted value (it splits, as documented); a duplicate `ResponseTest` case and an unreachable assertion removed.

Optional, not done: an `Oversize` state so cookie-jar stuffing is logged (today it is indistinguishable from `absent`); a single import style for exception classes in the tests.

Carried:
- **Phase 7/8 (deployed smoke test):** A20 is verified only at the Bref hop. Send `Cookie: __Host-session=<A>; __Host-session=<B>` through CloudFront and API Gateway and assert the request is anonymous and logs `duplicate session cookie`. Keep the HTTP API on **payload v2**: with v1 / `multiValueHeaders` (REST API, ALB), Bref's `FpmHandler` keeps only the last value of a repeated `Cookie` header.
- **WP8:** on `Duplicate`, also send the clearing cookie (and log the A20 warning).
- **WP14 / threat model:** nameless-cookie tossing (a cookie with no name whose value is `__Host-session=<x>`, set over plain HTTP before HSTS is cached) cannot be told apart by any server parser; current Chrome, Firefox and Safari refuse such cookies, and `*.cloudfront.net` is on the Public Suffix List. Mitigation is HSTS from ADR 0005's response-headers policy; record the residual risk.
- **Before the first handler calls `SessionCookie::issue()` (WP10):** the token is plain text once it is in a `Set-Cookie` value or `Request::$headers['cookie']`. `JsonLogger` already redacts `cookie` / `set-cookie` keys and lines (WP2); never `var_dump`/`print_r` a `Request` or `Response`, or add a `__debugInfo` that hides those headers if one is ever needed.

### Notes from the WP6 reviews (2026-10-19)

Before the reviews, the common-password list was removed (owner decision, section 10) after it set off the company's endpoint-security alerts; the endpoint-security rule in section 3 now applies to every brief. From ecc:php-reviewer (sonnet) and ecc:security-reviewer (opus/high) on the uncommitted WP6 diff (base `7160a94`); round 2 (opus/high) on the fix hunks approved with LOW notes only. No CRITICAL or HIGH findings.

**Measured (input for Phase 7 sizing):** one production Argon2id hash (m=65536, t=4, p=1) takes 0.15–0.18 s in the tools container (PHP 8.5.11, Linux).

The fix batch covers:
- **Email (security MEDIUM):** `FILTER_VALIDATE_EMAIL` accepted quoted local parts (`"alice"@example.com`, `"victim@example.com"@attacker.example`) and domain literals (`alice@[192.0.2.1]`), so one mailbox could hold several accounts and odd characters would reach logs, throttle keys and later email code. Any `"`, `\`, `[` or `]` is now refused (A10). `a@localhost` and a domain without a dot were already refused by PHP; tests pin both.
- **Hasher:** an architecture test keeps `new PasswordHasher` out of `src/` (a cheap hasher would store weak hashes and break the A16 timing equality); `$hash` is `#[\SensitiveParameter]` in `verify()` and `needsRehash()`; a fast test pins the production cost via the private `options()`.
- **Unicode:** NFKC normalisation was planned but `intl` is missing in both images, so passwords are compared byte for byte (A11), documented and pinned by a test.
- Violation reasons are a backed enum `PasswordViolation`, like `InvalidEmail`; `exceedsLoginLimit` measures invalid UTF-8 in bytes; `make test` runs the slow unit test unless `FAST`; weaker tests tightened.

Carried:
- **WP11 (A16), from the security review:** never store `DUMMY_HASH` as a real user's hash; the fixed dummy-user `GetItem` must never return a loginable account; an over-length password is refused the same way whether or not the email exists; equal timing holds only while user hashes use the current cost (rehash on login keeps it so).
- **Accepted residual risk (no blocklist):** 12 repeated characters or 12 spaces are accepted; online guessing is bounded only by the A13 throttles and the Argon2id cost.
- **Phase 7:** the API Lambda needs at least 64 MiB of headroom per concurrent hash (A12); size from the timing above.
- **Email characters that stay valid:** `%`, `!`, `'`, `` ` ``, `|`, `/`, `{`, `#`, `?` are legal in a local part. Store addresses only through parameters, render them only through Vue's escaping, and send mail only through the SES API (no SMTP header assembly, no shell).
- **Unicode normalisation, if ever wanted** (for example `normalize('NFC')` in the Vue client, no `intl` needed): decide before real accounts exist; later it needs a migration (on login try both forms, then rehash).

Optional, not done: listing `new (PasswordHasher::class)(…)`, `class_alias` and `unserialize` as known gaps of the construction test (code review covers them); a CI step for `make test-slow-unit` (a CI runner's timing would not reflect Lambda; the cost is pinned by fast tests).
- Any future CSV export must escape email local parts starting with `=`, `+`, `-` or `@` (formula injection); they are valid addresses.

### Notes from the WP7 reviews (2026-10-19)

From ecc:php-reviewer (sonnet) and ecc:security-reviewer (opus/high) on the uncommitted WP7 diff (base `de400bf`); round 2 (opus/high) on the fix hunks approved, and showed by mutating a copy of the code that each new unit test fails without its fix. No CRITICAL or HIGH findings. The security reviewer could not make two accounts share one normalised email: `Email` can only be built by `parse()`, the lock and the user are one `transactWrite` with `attribute_not_exists(PK)`, nothing else writes `EMAIL#` items, and the A19 re-read can only report success for our own freshly generated UUID. The SDK also fills in `ClientRequestToken` for `TransactWriteItems`, so a retried write that already committed normally succeeds without reaching the re-read at all.

The carried WP3 item is done: `OfflineServices` now runs with the fake keys from `.env.example` (set in the PHPUnit config), so a store call through it no longer hangs on the instance metadata service.

The fix batch covers:
- **No credentials seam in production code:** the optional credentials parameter added to `DynamoDbClientFactory` was removed; unit tests get the fake keys from the PHPUnit environment. An architecture test fails if anything in `src/` other than the factory passes `credentials` or builds an SDK client, so the Lambda can only use its execution role.
- **`User` hides its password hash like `SessionToken`** (static `WeakMap`), so `var_export`, `(array)` and `get_object_vars` cannot show it (A21).
- **A18 addendum:** `TransactionInProgressException` (a retried transaction while the first attempt is still committing) is a `503` with `Retry-After: 1`, not a `500`.
- **A19 tightened:** a lock holder that is not a valid UUID is corrupt; when the holder is our id, the stored user must also match our `createdAt` and hash before the create counts as a success.
- **Hash format:** an empty hash or one that is not `$argon2id$…` is refused on write and treated as corrupt on read.
- More tests: a real user-id collision rolls back the new lock and rethrows; a non-UTC clock is stored as UTC; impossible dates and wrongly typed lock ids are corrupt; a refused duplicate leaves the original lock intact.

Carried:
- **Phase 3+ (categories, expenses, receipts):** `PROFILE` (with the password hash) lives in the `USER#<id>` partition. Every query on `PK = USER#<id>` must use a `begins_with(SK, …)` condition, checked by a test, so it can never return the profile item.
- **Before the first S3 client (Phase 3), harden `AwsClientConstructionTest`:** round 2 got past it with `DynamoDbClient::factory([...])` (the SDK's deprecated static factory), another client class (`new \Aws\S3\S3Client`), a concatenated `'cred' . 'entials'` key and a heredoc key; it also scans only `src/`, not `entrypoints/` or `public/`. Flag `::factory`, any `Aws\` client import outside the factory and heredoc text, and scan all three roots. Code review is the backstop until then.
- **WP10 (register handler):** a user who gets the A18 `503` (for example `TransactionInProgressException`) and retries creates a new id and hash, so they may see `409` for their own just-committed account, as after any timeout. Consider the message wording.
- **The fake keys are forced in `phpunit.xml.dist`** (so tests can never pick up real keys from a shell or CI); `.env.example` says to change both together.
- **WP10:** once a real route uses the store, prove the wiring through it and remove the routes-closure seam from `ApiFactory`.
- **WP11:** an unknown email costs one `GetItem`, a known one two; accepted (A9 already reveals existence through the register `409`, and both are throttled), but the dummy Argon2id verify must stay on the unknown-email path so the hash dominates the timing.
- **If account deletion is ever added:** recheck the uniqueness argument and the "no lock" branch of the A19 re-read.

### Notes from the WP8 reviews (2026-10-19)

From ecc:php-reviewer (sonnet) and ecc:security-reviewer (opus/high) on the uncommitted WP8 diff (base `6a95fc9`); round 2 (opus/high) on the fix hunks approved; its one recommended follow-up (below) is under 30 lines and was reviewed inline by the main session. No CRITICAL or HIGH findings. The security reviewer found no way for an expired, deleted or tampered session to authenticate:
- both expiry checks are strict (`> now`), run in `find()` and again in the authenticator, against one `$now`;
- the renewal condition `attribute_exists(PK) AND expiresAt > :now` cannot recreate a deleted item, so a logout racing a renewal gives `ConditionFailed`, which is anonymous; `absoluteExpiresAt` is never rewritten, so the 7-day cap holds;
- reads are strongly consistent, so a deleted session is never read back from a stale replica;
- a renewal that fails for another reason still authenticates: the consistent read had just shown the session active, so the failure only means the expiry is not extended;
- the raw token never reaches a key, and absent, malformed and duplicate cookies cost no read (tests count the calls);
- every `401` has the same body and `WWW-Authenticate: Session`; the cookie is cleared for every reason except "no cookie"; a storage failure (500/503) does not clear it, so a transient fault never logs anyone out;
- logging an enum or a date (new in `JsonLogger`, the WP2 carried item) opens no path around redaction.

The fix batch covers:
- **Unit tests for `SessionRepository`** with simulated DynamoDB answers (create, retry and collision paths, find, renew, delete, invalid input with zero requests), like `UserRepository`.
- **No weak token generator in production:** an architecture test fails if anything in `src/` passes `SessionRepository` its own generator (the closure is a test seam only).
- **Absolute lifetime enforced on read:** the effective cap is `min(absoluteExpiresAt, createdAt + 7 days)`, so a bad writer can never make a session immortal, and lowering `SessionLifetime::ABSOLUTE` later simply ends older sessions sooner (round 2: rejecting such items would have turned them into `500`s). An `absoluteExpiresAt` at or before `createdAt`, or a `createdAt` more than 5 minutes in the future, is corrupt.
- **A corrupt item returned by a renewal is a `500`,** not a logged warning.
- Explicit `match` arms instead of `default` (a new reason or an impossible state can no longer be swallowed); comments for the deliberate double expiry check and for two overlapping renewals (they may lower `expiresAt` by a few seconds, harmlessly); test tidy-ups.

Optional, not done (round 2): the token-generator architecture test does not catch deliberate obfuscation (`new (SessionRepository::class)(…)`, `new $class`, spread arguments, `class_alias`, `new self` inside the class, files outside `src/`); it guards against accidental misuse, and code review covers the rest. Non-transient renewal failures (`ValidationException`, `AccessDenied`…) are only logged; sessions then end after the idle limit, which fails safe.

Decisions recorded: a second token collision in a row is a `500` (`SessionCollision`; with 256-bit tokens it does not happen); the request line always carries `userId`, `null` when anonymous.

Carried:
- **WP14 / threat model:** a corrupt session item makes every request with that cookie a `500` until TTL removes it (fails closed; the cookie is not cleared).

### Notes from the WP9 reviews (2026-10-20)

From ecc:php-reviewer (sonnet) and ecc:security-reviewer (opus/high) on the uncommitted WP9 diff (base `d8bca51`); round 2 (opus/high) on the fix hunks approved, with two LOW hardening items carried to the start of WP11 (below). No CRITICAL or HIGH findings. The security reviewer found that limits hold under races, TTL lag and SDK retries:
- the increment is one atomic `ADD` returning the new count, so parallel hits get distinct counts;
- the reset put applies only to an ended window, so it never overwrites a live one, and when two requests reset at once the loser counts into the new window (the integration test forces this interleaving deterministically through an SDK middleware, with no production hook);
- blocked hits keep counting but never move `expiresAt`, so a flood cannot lock anyone out beyond the window;
- the throttle never learns whether an account exists, so known and unknown emails get the same writes, decision and `Retry-After`;
- every failure refuses the request (503 for DynamoDB throttling, otherwise 500), and no email, hash or key reaches a log or exception message.

The fix batch covers:
- **`LoginAttempt` (API shape for WP11):** `LoginThrottle::attempt()` hands out an opaque `LoginAttempt` only when the decision is allowed; `succeeded(LoginAttempt)` is the only way to delete or refund, and refuses a second use. A refund is tied to the window that counted the attempt (`expiresAt = :countedWindow`), so it can never give back an attempt in a later window.
- **Clean-up after a correct login is best effort:** each delete and refund runs on its own; a storage error is logged with the scope and error code only and never fails the login.
- **Self-repair:** the reset condition is the exact complement of the increment guard, so a bucket with a missing or non-numeric `expiresAt` becomes a fresh window instead of a permanent `500`. A far-future `expiresAt` stays a `500` (fails closed).
- The `/48` and the throttle sort key are each defined once; test names and setup tidied; IPv4 whitespace cases added to the key checks.

Carried:
- **WP11, first step (round 2 LOW-1 and LOW-2, before any handler uses `LoginAttempt`):**
  - `LoginAttempt` can be cloned or serialised, and a copy is accepted a second time (round 2 proved it: two refunds and two deletes). Add `__clone`, `__serialize` and `__unserialize` that throw `LogicException`, as `SessionToken` and `User` do, with tests.
  - The `@internal` factory `LoginAttempt::issuedBy()` and `consume()` are public, so any code holding the `LoginThrottle` could forge an attempt, and `ThrottleStore::refund()` / `delete()` can be called directly. Add an architecture test (like `PasswordHasherConstructionTest`) that fails if `LoginAttempt::issuedBy`, `->consume(`, or a throttle store's `->refund(` / `->delete(` appear anywhere outside `LoginThrottle.php`.
  - `NetworkPrefix` should derive its byte count with `intdiv` (or a test should pin the prefix bits to a multiple of 8), so it can never disagree with `AuthKeys`.
- **WP11:** a blocked attempt must never reach `password_verify` or `succeeded()`; a wrong password must never call `succeeded()`; `succeeded()` runs only after the password is verified (the account-bucket delete is deliberately unconditional: any real success resets it, A13); test all three.
- **WP13:** the race test must send truly concurrent `UpdateItem`s (for example `executeAsync` / `CommandPool`) and expect exactly `limit` allowed; the WP9 integration test is sequential.
- **Phase 7:** alarm on `CorruptItem` and on the warning "Login throttle clean-up after a successful login failed" (an IAM gap such as a missing `dynamodb:DeleteItem` would otherwise show up only as warnings); rely on API Gateway / WAF rate limits for floods (each blocked login still costs two or three DynamoDB writes).
- **WP14 (ADR notes / threat model), accepted by design (A13, A14, R3, owner decision 5):** fixed windows allow a burst of up to twice a limit around a window boundary; IPv4 has no coarse tier; each successful login by the owner gives an attacker a fresh 10 attempts on that account; an attacker whose address is already blocked can still push a victim's account bucket over its limit (a lockout for at most 15 minutes); the account key is an unsalted SHA-256 of the email, so someone who can read the table can confirm that a guessed email was tried in the last 15 minutes (registered emails are stored in plain text anyway; an HMAC with an SSM key is the upgrade if ever wanted).

### Notes from the WP11 hardening reviews (2026-10-20)

The WP9 round-2 LOW-1/LOW-2 items, done before any handler uses `LoginAttempt`. Reviewed by ecc:php-reviewer (sonnet) and ecc:security-reviewer (opus/high); no CRITICAL, HIGH or (security) MEDIUM findings; the fix batch was re-checked inline.
- `LoginAttempt` refuses `clone`, `serialize` and `unserialize` (`LogicException`) and dumps as `[]` (`__debugInfo`), so a receipt cannot be copied and accepted twice and the account key never reaches a dump.
- `ThrottleRefundBypassTest` (shares `tests/Architecture/Support/TokenScanner` with `PasswordHasherConstructionTest`) fails if anything under `src/` other than `LoginThrottle.php` calls `issuedBy` (any owner, so aliases and `self::` are caught), `->consume(` / `->refund(` (any receiver, nullsafe included), `->delete(` in a file naming a throttle type, or names one of those methods in a string callable. A future legitimate `refund` / `consume` elsewhere means renaming it or adding a commented exclusion.
- `NetworkPrefix` derives its byte count with `intdiv`; a test pins the prefix bits to a multiple of 8.
- **Accepted limits (WP14 threat model):** reflection or `Closure::bind` can reset a receipt's `used` flag, dynamic method names (`$o->$m()`) and a store reached through an untyped value are not caught by the scan. Code doing that could reach the store directly anyway; review is the control.

### Notes from the WP10 reviews (2026-10-20)

From ecc:php-reviewer (sonnet) and ecc:security-reviewer (opus/high) on the uncommitted WP10 diff (base `59fb301`); round 2 (opus/high) on the fix hunks approved with no findings. No CRITICAL or HIGH findings. The security reviewer confirmed:
- the throttle runs before the body is read and before any Argon2id work; a body over 64 KiB is answered 413 before routing (not counted, not hashed); a missing address falls into `unknown`; a throttle-store failure refuses the request;
- the A19 re-read cannot sign anyone into an account they did not create: a retried create counts as ours only if the email lock holds this request's fresh UUID **and** `createdAt` **and** the salted hash match (`hash_equals`, `ConsistentRead`);
- 503 + `Retry-After` for throttling cancellations, a generic 5xx body, one 429 body for every bucket, and a 409 that carries neither the address nor a reason;
- logs carry only `requestId` and `userId`; CSRF runs before the router; every operation declares `security` explicitly.

The fix batch covers:
- **Carried session ended best effort:** after the user is created, the new session is created first, then the carried one is deleted; a `StorageFailure` on that delete is logged (`requestId`, operation, AWS code) and the response is still `201` + `Set-Cookie`. The browser's cookie is replaced, and the old token still maps only to its original user until it expires, so A4's fixation guarantee holds. `CarriedSession` (new, reused by WP11) also logs `duplicate session cookie` (A20) and deletes nothing in that case. It lives outside `RegisterHandler` so `ThrottleRefundBypassTest`'s `->delete(` rule stays strict.
- **Field errors:** exhaustive `match` over `InvalidEmail` / `PasswordViolation`, texts built from `Email::MAX_BYTES` and `PasswordPolicy` constants; at most 5 unknown-member errors (`MAX_UNKNOWN_KEY_ERRORS`), so a 422 cannot be much larger than the request; `CredentialsInput` dumps redact both fields.
- **Contract:** `Credentials.email` has no `maxLength` (the server trims first; the description states the 254-byte limit after trimming). `securitySchemes.sessionCookie`, `ClearSessionCookie` and `WWWAuthenticate` wait for WP11/WP12, their first users (the contract lint rejects unused components).
- **409 after a 503 retry:** "An account with this email address already exists. If your previous attempt ended with a temporary error, it may have gone through: try signing in."
- **One commit:** contract and implementation are committed together (owner decision 2026-10-20), because the contract alone fails `RoutesMatchContractTest` until the route exists.

Carried:
- **WP12:** build route handlers lazily in `Routes::all()` (today every request builds the register handler and its stores).
- **Phase 7:** reserved concurrency on the API Lambda is the backstop for hash cost from many addresses (each `/64` or `/48` buys 5 or 20 hashes an hour; there is no global cap); smoke-check `CLIENT_IP_HEADER` in production, because a misconfiguration puts everyone in `unknown` (5 registrations an hour for all, fails safe).

## 8. Risks and decision points

| # | Risk / trigger | Mitigation / fallback | WP |
|---|---|---|---|
| R1 | A browser refuses `Secure` / `__Host-` cookies on `http://localhost` (Safari) | Research R1. Recommend Chrome or Firefox locally; the code stays as on AWS. If no browser works, the owner decides (G2) | WP5, WP14 |
| R2 | A login flood burns CPU (Argon2id costs about 0.1–0.5 s per attempt) | Throttling before hashing on blocked buckets; API Gateway rate limits (Phase 7); WP6 measures the cost; `needsRehash` allows retuning | WP6, WP11 |
| R3 | An attacker locks a victim out with 10 wrong passwords | Short 15-minute window; only the account bucket blocks the account; documented trade-off (owner decision 5) | WP9 |
| R4 | Misconfiguration puts every user in the shared `unknown` bucket | Fail closed by design (ADR 0012); alarm in Phase 7 | WP9 |
| R5 | Parallel requests overshoot a limit | Increment first, atomic `ADD`; WP13 race test expects exact counts | WP9, WP13 |
| R6 | TTL deletes late, so an expired session or window looks alive | Expiry always checked against `expiresAt` in code; TTL is only cleanup | WP8, WP9 |
| R7 | A retried write reports a false duplicate (409, or a lost session) | Re-read rule A19; tested by replaying `create` | WP7, WP8 |
| R8 | Register reveals that an email exists | Accepted (A9, owner decision 2); throttled per address and `/48`; login stays generic and timing-equalised (A16) | WP10, WP11 |
| R9 | A password or token reaches a log | `#[\SensitiveParameter]`, value objects that hide secrets, key and text redaction, the WP13 log grep | WP2, WP5, WP13 |
| R10 | A protected route is added without the guard | `ProtectedRoutesMatchContractTest` + explicit `security` on every operation | WP12 |
| R11 | CSRF bypass with a missing `Origin` | Refused (A8); R7 confirms browsers always send it on non-GET | WP4 |
| R12 | Integration tests trip each other's throttle buckets | A random viewer address per test; unique emails per test | WP10 |
| R13 | `api-php` with credentials reaches something other than DynamoDB Local | Fake keys only; endpoint allow-list; `api-internal` has no route out; `dynamodb` joins that network. **Accepted:** `api` and `dynamodb` can both reach php-fpm on 9000 (DynamoDB Local is AWS's digest-pinned image, using that path needs code execution inside it, and the target is read-only, non-root, cap_drop ALL, holds only the fake keys and has no route out; `listen.allowed_clients` would need fixed subnets that could clash with a developer's network). Revisit if a non-AWS image joins `api-internal`. Shell AWS keys are kept out by `env -u` in `COMPOSE` and by `check-env` | WP3 |
| R14 | An older `.env` lacks `DYNAMODB_ENDPOINT` / `APP_ORIGINS` | `check-env` names the line (endpoint); Compose default (origins); `local-dev.md` note | WP3, WP4, WP14 |
| R15 | The `APP_ORIGINS` ↔ CloudFront domain cycle in Terraform | Recorded for Phase 7 (section 7.2) | Phase 7 |
| R16 | Scope creep into front-end pages or Phase 3 | Explicit list in section 7.2 | all |
| R17 | Shared Docker host damaged by cleanup | The rule in section 3, repeated in every agent brief | all |

## 9. Lessons from Phase 1, applied

1. **Security round 2 at opus/high** on every security-critical WP (Phase 1 did this for WP3, WP6 and WP7; here it covers WP2–WP13).
2. **Bref differences are researched up front** (R2). The Phase 1 surprise was the query string normalised by Bref, found only in review.
3. **Log-grep tests use canonical markers.** The WP7 integration test flaked about 12% of the time on a non-canonical IPv6 value; WP13's markers are written in exactly the form the logger prints.
4. **A stale `.env` never starts a half-configured stack:** `check-env` for keys without a Compose default, Compose defaults equal to `.env.example` for the others (WP8 lesson).
5. **Fail closed on configuration**, with all errors collected and only names logged (WP4/WP6 lesson), now also for DynamoDB and `APP_ORIGINS`.
6. **Haiku docs are fact-checked** against the tree and by running every command (WP14).
7. **Review notes are recorded per WP** in section 7.3 before the next WP, so nothing carried is lost.
8. **Small, contract-first WPs:** shared contract pieces arrive with their first endpoint, and every WP is S or S–M.

## 10. Owner decisions (answered 2026-10-16: all nine recommended defaults)

1. **Session lifetime (A3).** Idle 24 h with sliding renewal, absolute 7 days? **Recommended: yes.** It suits a personal finance tool. The alternative is idle 7 days / absolute 30 days: fewer sign-ins, a longer window for a stolen cookie.
2. **Register when the email is taken (A9).** Answer `409` honestly (with registration throttled), keeping login generic? **Recommended: yes.** Without email verification a fake "success" would leave the person unable to sign in.
3. **Register signs the user in (A9).** `201` with a session cookie? **Recommended: yes** (one step fewer for the user; the session is rotated on every later login).
4. **Password length (A11).** Minimum 12, maximum 128 characters, no composition rules? **Recommended: 12.** NIST SP 800-63B-4 asks for 15 when a password is the only factor; choose 15 for the strictest reading. R6 confirms the citations.
5. **Throttle limits (A13).** Login: 10 per account, 30 per address, 100 per IPv6 `/48`, each per 15 minutes. Register: 5 per address, 20 per `/48`, per hour. **Recommended: these values.** They are constants, cheap to change later.
6. **Coarse IPv6 tier (A14).** `/48` or `/56`? **Recommended: `/48`.** It bounds a `/48` holder, and its high limit with failures-only counting keeps residential neighbours safe. `/56` matches typical home allocations but leaves a `/48` holder 256 buckets.
7. **Cookie on localhost (A1).** The same `__Host-session` + `Secure` everywhere, with no local switch? **Recommended: yes.** If Safari refuses it (R1), use Chrome or Firefox locally.
8. **`userId` in request log lines (A21).** It is an opaque id, kept for the same 30-day retention as the client IP. **Recommended: yes** (needed to investigate abuse).
9. **PR shape.** One PR for the phase, draft after WP4, squash-merged, as in Phases 0 and 1? **Recommended: yes.**

**Later owner decision (2026-10-19): no password blocklist.** The R6 decision to add a local common-password list is withdrawn. The list chosen in WP6 (SecLists, from public password leaks) set off the company's endpoint-security alerts on download and use, and the security team asked for it to go. Everything related was deleted before any commit, so it is not in the git history. Consequence: registration does not refuse common or leaked passwords; the defences left are the 12-character minimum, Argon2id and the login throttles (A13). This falls short of NIST SP 800-63B-4's blocklist "SHALL" and is recorded as an accepted gap. Any future blocklist must be cleared with the owner and the company's security team first.
