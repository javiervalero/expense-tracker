# Phase 1: Back-end core. Implementation plan

| | |
|---|---|
| Status | **Approved by the owner, 2026-10-13** (gate G1, including the three WP4 deletions; section 9 answered with the recommended defaults) |
| Phase | 1 (PLAN §12, essential track) |
| Branch | `feat/phase-1-backend-core`, from `main` (Phase 0 merged in PR #3, commit 4911635) |
| Inputs | PLAN §5, §6, §9, §10, §12, §13; ADR 0002, 0003, 0005, 0007, 0011, 0012; the Phase 0 code (`backend/`, `docker/`, `contract/`, `Makefile`, `ci.yml`) |
| Drafted by | `ecc:planner` (opus/high) |
| AWS | **None.** Phase 1 makes no AWS calls, uses no credentials, deploys nothing and costs $0. Every SDK client in tests runs against a local stand-in. Phase 1 has no AWS deploy, so it has no deploy gate. If any step ever seems to need AWS, the work stops and the owner is asked first. |

**Scope (PLAN §12, row 1):** router, middleware (including origin verification and the trusted client IP, ADR 0012), problem-details errors, the DynamoDB access layer, table creation (table + `LSI1`), `/health`, and the OpenAPI contract test helper plus route parity (ADR 0011). Phase 1 includes no authentication, sessions, CSRF, throttling, categories or expenses (see section 6).

## 1. Goal and definition of done

**Goal:** replace Phase 0's temporary health endpoint with the real back-end skeleton that every later phase plugs into. That means a request pipeline that refuses anything that did not come through "CloudFront", works out the caller's IP safely, answers every error as problem details, logs every request as one JSON line, and is checked against the OpenAPI contract by its integration tests. Next to it sits a tested DynamoDB access layer over a table that `make up` creates.

**Done means (each item can be demonstrated):**
- **`make up`** from a clean clone is still idempotent and healthy. It now also creates the DynamoDB table `expense-tracker-local` with `LSI1`, and TTL on `expiresAt` if DynamoDB Local supports it (see WP8). A second run logs "no changes" for both S3 and DynamoDB.
- **Origin lockdown, locally:**
  - `curl -i localhost:8080/api/v1/health` returns `403 application/problem+json`.
  - The same request with `-H 'X-Origin-Verify: local-origin-verify-not-a-secret'` returns `200 {"status":"ok"}`.
  - `http://localhost:5173` still shows "API: ok", because the Vite proxy adds the header.
  - An unknown path without the header gets 403, not 404.
- **Fail closed on configuration:** if `ORIGIN_VERIFY_SECRETS` is empty or invalid, every request (including `/health`) gets `500` problem+json, and the log names the variable but never its value. The `api` healthcheck then fails, so `make up` fails loudly.
- **Client IP:** every request log line has `clientIp`. A valid `CloudFront-Viewer-Address` shows the address. A duplicated header (merged by nginx into `a:1, b:2`) shows `unknown` and adds an error line. This is checked end to end by `make test-integration`.
- **Contract:**
  - Every integration request to `/api/...` goes through `ApiTestCase::request()`, which validates the status, content type, required headers and body against `contract/dist/openapi.json`.
  - A route-parity test asserts that the router's (method, path) set equals the spec's.
  - A completeness test asserts that every operation documents `403` and `500`.
- **`make test`** passes, with line coverage ≥ 80% for `backend/src` and `docker/local-aws/src`. **`make lint`** passes.
- **CI:** the same 7 required checks (`backend`, `local-aws`, `guards`, `contract`, `frontend`, `integration`, `terraform`) are green. No new job is planned (see section 5).
- **Docs and ADRs:**
  - ADR 0011 gets "Verified in Phase 1" (does opis resolve the bundled `$ref`s?).
  - ADR 0012 gets "Implemented in Phase 1" (the exact `ClientIp` rules and how the origin compare works).
  - ADR 0002 and 0007 each get a short implementation note (table schema file, local table bootstrap, in-memory DynamoDB Local).
  - `docs/local-dev.md`, `README.md` and `.env.example` are updated.

## 2. Work packages

Each package is one or a few reviewable commits with conventional messages. Tests are committed with or before the code that makes them pass, so RED → GREEN is visible in the history.

**Order:** step 0 research → contract validator (it settles ADR 0011's open point first) → HTTP core → kernel → contract harness and CI → origin check → client IP. The DynamoDB track (WP8–WP9) depends only on WP1 and may be done before, after or between the HTTP packages. It touches `docker-compose.yml` and `.env.example`, as WP6 and WP7 do, so on one branch do these packages one after another, not in parallel.

**Where commands run:** unchanged from Phase 0. PHP tools run in the pinned `php` tools container and Node tools in the pinned node container, both as the host user. CI calls the same make targets with `RUN_PHP=` / `RUN_NODE=`.

### Shared Docker host rule (applies to every WP marked "Docker: yes")
The Docker host is shared with other projects. An agent may only:
- use the repo's make targets;
- use `docker compose -p expense-tracker …` (`up`, `stop`, `start`, `run --rm`, `logs`, `ps`, `down` **without** `-v`);
- use `docker run --rm` for containers it starts itself.

It may remove **only** containers, networks or volumes that it created in this session, by name. It must **never** run `docker rm $(docker ps -aq)`, any `docker … prune`, `docker compose down -v`, or `docker volume rm` on a volume it did not create. If a cleanup seems to need any of these, it stops and asks the owner.

### Model routing summary (`.claude/rules/project/model-routing.md`)
- **Implementation:** `ecc:tdd-guide`, sonnet/medium.
- **Review:** one language reviewer per diff (`ecc:php-reviewer`, sonnet/medium).
- **Security:** `ecc:security-reviewer`, opus/high, only on WPs that touch input parsing, the origin guard, client IP, config secrets or DB access (marked per WP).
- **Lesson from Phase 0, review round 2:** for security-critical parsing or guard code (WP3 parsers, WP6, WP7), the second review round (re-review of the fix hunks only) runs at **opus/high**, not sonnet.
- **CI and Makefile:** diffs under about 30 lines are reviewed inline by the main session. Larger ones go to `ecc:code-reviewer` (sonnet/medium).
- **Docs:** `ecc:doc-updater` (haiku/low), always fact-checked (WP10).
- **Test runs and output summaries:** general-purpose agent, haiku/low.
- **Build or lint failures:** `ecc:build-error-resolver`, sonnet/low on the first attempt, then opus/high.

### Step 0: Research & Reuse (ECC step 0, before WP2), `ecc:docs-lookup` haiku/low, results fact-checked by the main session
Answer from primary docs. Each result goes in the WP that needs it:
- **R1:** how `opis/json-schema` 2.6 resolves `#/components/...` refs inside a whole document registered as a resource (`SchemaLoader` / `Validator::resolver()->registerRaw`), and how it treats OpenAPI 3.1 annotation keywords (`example`, `discriminator`).
- **R2:** AWS docs for the format of `CloudFront-Viewer-Address`, in particular the IPv6 form (bracketed or not). ADR 0012 already allows both.
- **R3:** API Gateway HTTP API payload v2: are duplicate request headers joined with commas?
- **R4:** whether DynamoDB Local 3.3.1 accepts `UpdateTimeToLive` / `DescribeTimeToLive`.
- **R5:** php-fpm `log_limit` (default 1024 bytes splits long lines) and whether a `[global]` section is allowed in a `php-fpm.d/*.conf` include.

### Part A: Contract and HTTP core

#### WP1: Plan (S)
- **Files:** this plan (`docs/plans/phase-1-backend-core.md`).
- **Agent:** written by the main session from the planner's output. **Commit:** `docs: add phase 1 implementation plan`.

#### WP2: Contract validator helper (S–M). Settles ADR 0011's "To verify in Phase 1"
- **Purpose:** the ~100-line helper that ADR 0011 describes, built and proven on its own before anything depends on it.
- **Files:**
  - `backend/composer.json` and `composer.lock`: `opis/json-schema: ~2.6.0` in **require-dev** (version verified on PHP 8.5 in Phase 0, A9).
  - `backend/tests/Support/Contract/OpenApiDocument.php`: loads the bundled JSON; maps (method, concrete path) to the spec's path template (`/expenses/{id}`); finds the response for a status and media type; lists its `required: true` headers.
  - `backend/tests/Support/Contract/ContractValidator.php`: registers the whole document under a fixed `$id` and validates bodies against a JSON-pointer `$ref` into it. Returns a list of `ContractViolation`.
  - `backend/tests/Support/Contract/ContractViolation.php`.
  - `backend/tests/Fixtures/contract/openapi.fixture.json`: a small bundled spec with `$ref` chains `responses → schemas`, `const`, `additionalProperties: false`, a templated path and a problem+json response.
- **Tests first (RED):** `backend/tests/Unit/Support/Contract/{OpenApiDocumentTest,ContractValidatorTest}.php`. Cases:
  - a valid body passes;
  - an extra property fails;
  - a wrong type fails;
  - a `const` mismatch fails;
  - an undocumented status fails;
  - an undocumented content type fails;
  - a missing required header fails;
  - HEAD is checked with no body;
  - a templated path matches, and a non-matching path fails as "no such operation";
  - `format: uri-reference` and annotation keywords do not error.
- **Decision D1 (gate G2, only if it fires):** if opis cannot resolve the bundled refs cleanly, switch to ADR 0011's fallback (`league/openapi-psr7-validator` + `nyholm/psr7`, dev only). That is recorded in ADR 0011 and needs the owner's OK.
- **Acceptance:** the unit tests are green and `make lint-php` passes.
- **Docker:** yes (php tools container).
- **Agents:** tdd-guide (sonnet/medium); php-reviewer (sonnet/medium). No security reviewer (dev-only test code).
- **Commit:** `test: add openapi contract validator helper (opis)`.

#### WP3: HTTP primitives, router and problem details (M)
- **Files** (`backend/src/Http/`):
  - `Request.php`: immutable. Method, raw path, query (`array<string,string>`), headers (lower-cased names), body, attributes; `header()`, `withAttribute()`.
  - `RequestFactory.php`: `fromGlobals(array $server, string $body)`, which reads `HTTP_*`, `CONTENT_TYPE` and `CONTENT_LENGTH`.
  - `QueryString.php`: a strict parser. A repeated key or `[]` syntax gives a 400 problem, and the result is never nested arrays. This deliberately avoids `parse_str`.
  - `Response.php`: replaces `HttpResponse`, with **multi-value headers** (`array<string, list<string>>`), as the Phase 0 docblock asked; helpers `json()` and `empty()`.
  - `ResponseEmitter.php`: header and output writers are injected so it can be unit-tested. No body for HEAD.
  - `Handler.php` and `Middleware.php` (interfaces), `Pipeline.php`.
  - `Routing/{Route,Router,RouteResult}.php`:
    - routes are mounted under `/api/v1` and path templates use OpenAPI's `{name}` syntax;
    - the result is Matched (handler + params), NotFound, or MethodNotAllowed (with the `Allow` list);
    - HEAD is explicit per route, never implicit, and OPTIONS gets 405;
    - `routes()` is exposed for the parity test.
  - `Problem/{ProblemDetails,HttpProblem,FieldError,ValidationProblem}.php`:
    - RFC 9457, `about:blank` by default;
    - validation errors go in the `errors: [{pointer, detail}]` extension;
    - `HttpProblem` is the exception that carries a problem.
  - `JsonBody.php`:
    - the type must be `application/json` (with an optional `charset=utf-8`), otherwise 415;
    - a body over 64 KiB gives 413;
    - invalid JSON, a non-object or depth over 32 gives 400;
    - big integers are decoded with `JSON_BIGINT_AS_STRING`.
- **Tests first (RED):** `backend/tests/Unit/Http/{RequestFactoryTest,QueryStringTest,ResponseTest,ResponseEmitterTest,PipelineTest,JsonBodyTest}.php`, `Routing/RouterTest.php`, `Problem/ProblemDetailsTest.php`. Router edge cases:
  - trailing slash, `//`, `/api/v2/...` and a path outside `/api/v1` are each 404;
  - a lower-case method gets 405 with `Allow`;
  - a `{id}` parameter never matches an empty string or `/`;
  - a percent-encoded `/` inside a segment is not a separator;
  - the query string is ignored for routing.
- **Acceptance:** coverage of the new classes ≥ 90% (they are small and pure). PHPStan max is clean.
- **Docker:** yes.
- **Agents:** tdd-guide; php-reviewer; **security-reviewer (opus/high)**, because this is user-input parsing. **Round 2 at opus/high** for `QueryString`, `JsonBody`, `RequestFactory` and the router's path matching.
- **Commits:** `feat: add http request, response and pipeline primitives`, `feat: add router with problem-details 404 and 405`, `feat: add problem details and strict json body reader`.

#### WP4: Kernel, composition root, error handling, request log, health (M)
- **Files:**
  - `backend/src/Infrastructure/Config/{ApiConfig,InvalidConfiguration}.php`: `ApiConfig::fromEnvironment(array $env)` collects **all** errors and names variables, never values. It grows in WP6 and WP7.
  - `backend/src/Http/Middleware/ErrorHandlingMiddleware.php`:
    - an `HttpProblem` becomes its response;
    - any other `Throwable` becomes a generic `500` problem;
    - the log line carries the request id, exception class, message, file and line (no args or trace).
  - `backend/src/Http/Middleware/RequestLoggingMiddleware.php`: one JSON line per request, with `requestId`, `method`, `path` (no query string, truncated to 256 characters), `status` and `durationMs` (`hrtime`). It never logs headers, cookies or the body.
  - `backend/src/Http/ApiKernel.php`:
    - generates the request id (`bin2hex(random_bytes(8))`) and runs the pipeline;
    - adds `Cache-Control: no-store` and `X-Content-Type-Options: nosniff` to **every** response;
    - never throws.
  - `backend/src/Api/ApiFactory.php` (composition root, like `App\Worker\WorkerFactory`):
    - `ApiFactory::fromEnvironment(array $env, $logStream)`;
    - on `InvalidConfiguration` it returns a fail-closed kernel that answers 500 to everything.
  - `backend/src/Api/Routes.php`: the route table.
  - `backend/src/Health/HealthHandler.php`.
  - `backend/public/index.php` (~15 lines): reads the body (capped at 1 MiB + 1), builds the request, emits the response.
  - `docker/php/php-fpm.d/zz-app.conf`: `log_limit = 8192` in a `[global]` section, so JSON lines are not split (R5).
  - `docker/php/conf.d/app.ini`: `zend.exception_ignore_args = On`.
- **Deleted (approved with this plan, G1):** `backend/src/Http/HealthEndpoint.php`, `backend/src/Http/HttpResponse.php`, `backend/tests/Unit/Http/HealthEndpointTest.php`. These were Phase 0 temporary code, replaced here. No other deletions.
- **Middleware chain** (PLAN §5, ADR 0012 §4.2), outermost first:
  1. `ErrorHandling`
  2. `OriginVerify` (WP6)
  3. `ClientIp` (WP7)
  4. `RequestLogging`
  5. *(Phase 2: Authentication, then Csrf)*
  6. Router

  `ApiKernel` applies the default headers after the chain.
- **Tests first (RED):** `backend/tests/Unit/Http/Middleware/{ErrorHandlingMiddlewareTest,RequestLoggingMiddlewareTest}.php`, `Unit/Http/ApiKernelTest.php`, `Unit/Api/ApiFactoryTest.php`, `Unit/Health/HealthHandlerTest.php`, `Unit/Infrastructure/Config/ApiConfigTest.php`. Among them:
  - a thrown `RuntimeException('secret-ish')` gives a 500 body without that text, and the log has it;
  - the default headers are on 404, 405 and 500.
- **Acceptance:** the **existing** `HealthOverNginxTest` passes unmodified, which proves the behaviour is preserved. The Bref-boundary test still passes.
- **Docker:** yes.
- **Agents:** tdd-guide; php-reviewer; **security-reviewer (opus/high)** limited to error leakage, the fail-closed config and log redaction. It may be batched with WP6's review in one pass.
- **Commits:** `feat: add api kernel with error handling and request logging`, `refactor: replace phase 0 health endpoint with router-based handler`.

#### WP5: Contract harness, route parity and CI wiring (S–M)
- **Files:**
  - `backend/tests/Support/Api/ApiTestCase.php`: `request()` goes through nginx (`API_BASE_URL`, default `http://api:8080`) and validates every response with WP2's validator. A test fails on any contract violation, with no opt-out. `rawRequest()` exists only for nginx-level paths that are not in the spec.
  - `backend/tests/Contract/{RoutesMatchContractTest,EveryOperationDocumentsErrorsTest}.php`: parity (method, template) in both directions; every operation documents `500` (`403` is added in WP6).
  - `backend/phpunit.xml.dist`: a new suite `contract`.
  - `backend/composer.json`: script `test:contract`.
  - `contract/openapi.yaml`: `components/responses/InternalServerError`; `'500'` on GET and HEAD `/health`; `info.version` 0.2.0.
  - `frontend/src/api/schema.gen.ts`: regenerated (`make contract-gen`).
  - `Makefile`: `test-contract` (needs `contract-bundle`; runs in the php tools container, no stack), added to `make test`.
  - `.github/workflows/ci.yml`, `integration` job: steps `make contract-install`, `make contract-bundle`, `make test-contract` before the conformance tests. **This adds no new job.**
  - `HealthOverNginxTest` is split:
    - the API cases move to `tests/Integration/Http/HealthApiTest.php` (`ApiTestCase`);
    - the nginx-level cases (path outside `/api`, 413 from nginx, `Server` header, php-fpm unreachable) stay as raw requests.
- **Tests first (RED):** add a route to `Routes.php` that is not in the spec, and see the parity test fail; then remove it.
- **Acceptance:** `make test-contract` and `make test-integration` are green, and CI `integration` is green with the new steps. The PR is **opened as a draft** after this WP.
- **Docker:** yes.
- **Agents:** tdd-guide; php-reviewer; the CI and Makefile diff is reviewed inline by the main session if under ~30 lines, otherwise code-reviewer (sonnet/medium).
- **Commits:** `test: add api test case with contract validation and route parity`, `ci: bundle the contract and run contract tests in the integration job`.

### Part B: Origin lockdown and client IP (ADR 0012 §4)

#### WP6: Origin-verify middleware (S–M), security-critical
- **Behaviour** (ADR 0012 §4.2):
  - `ORIGIN_VERIFY_SECRETS` is a comma-separated list of 1–2 slots, each ≥ 32 characters of visible ASCII with no spaces or commas. Otherwise `InvalidConfiguration` → fail-closed 500.
  - Comparison: `hash_equals(hash('sha256', $slot), hash('sha256', $given))` for **every** slot, with no early exit. The fixed-length digests stop the comparison from leaking the secret's length.
  - A missing, empty or wrong header gets one generic `403` problem. The body is the same in every case, so it never says which.
  - The middleware logs a warning `origin verification failed` with method, path and reason (`missing` / `mismatch`). It **never** logs the header value. The secrets are also passed to `JsonLogger`'s redaction list.
  - No route is exempt, and unknown paths get 403.
- **Files:**
  - `backend/src/Http/Middleware/OriginVerifyMiddleware.php`; `ApiConfig` (+ `originVerifySecrets`); `ApiFactory` wiring.
  - `docker-compose.yml`:
    - `api-php` gets `ORIGIN_VERIFY_SECRETS: ${ORIGIN_VERIFY_LOCAL:-local-origin-verify-not-a-secret}`, so the proxy and the API share one value and an older `.env` still works;
    - the **`api` healthcheck must send the header**, or the stack goes unhealthy: `CMD-SHELL` with `wget -q -O /dev/null --header "X-Origin-Verify: $$ORIGIN_VERIFY_LOCAL" http://127.0.0.1:8080/api/v1/health`, plus that env var on `api`;
    - update the `api-php` comment ("NO SECRETS…": the local value is non-secret).
  - `.env.example` and `frontend/vite/proxyHeaders.ts`: replace the "Phase 0 only sends it" comments.
  - `contract/openapi.yaml`: `components/responses/Forbidden`, `'403'` on every operation; regenerate `schema.gen.ts`. `EveryOperationDocumentsErrorsTest` now also requires 403.
  - `ApiTestCase`: sends `X-Origin-Verify` from `ORIGIN_VERIFY_LOCAL` (the `php` service has `env_file: .env`), with an explicit `withoutOriginHeader()` / `withOriginHeader($value)`.
- **Tests first (RED):**
  - `Unit/Http/Middleware/OriginVerifyMiddlewareTest.php`:
    - header missing, empty, wrong, a prefix of the secret, the secret plus a suffix, a case change, comma-merged `A, A`, and whitespace-padded each give 403;
    - slot A passes and slot B passes;
    - the log stream never contains the value;
    - HEAD and OPTIONS are also guarded.
  - `ApiConfigTest`: empty, too short, three slots, a space, a comma-only value.
  - `ApiFactoryTest`: empty secrets give 500 on `/health`, and the log names the variable only.
  - `Integration/Http/OriginVerifyApiTest.php`: through nginx, 403 without the header, 403 with a wrong one, 200 with the right one, 403 for an unknown path without it.
- **Acceptance:** `make up` is healthy (the healthcheck carries the header); "API: ok" shows through Vite; CI is green.
- **Docker:** yes.
- **Agents:** tdd-guide; php-reviewer; **security-reviewer (opus/high)**; **round 2 at opus/high**.
- **Commit:** `feat: reject api requests without the cloudfront origin secret`.

#### WP7: Trusted client IP, `Http\ClientIp` (M), security-critical

**Requirement (owner-specified, refines ADR 0012 §4.3):**
- R-CIP-1: `ClientIp` reads **only** the header named by `CLIENT_IP_HEADER` (`CloudFront-Viewer-Address` in every environment). `REMOTE_ADDR`, `X-Forwarded-For`, `Forwarded` and `X-Real-IP` are never read.
- R-CIP-2: the value must be **exactly one `host:port`**. nginx (and, per R3, API Gateway v2) joins a duplicated header into one comma-separated value. Any value with `,` is rejected (reason `multiple-values`).
- R-CIP-3: no trimming or repair. Any character outside `[0-9A-Fa-f.:\[\]]` (whitespace, `%` zone ids, letters beyond hex) is rejected. Values longer than 64 characters are rejected (the longest legitimate form is 53).
- R-CIP-4, the forms accepted:
  - IPv4 `a.b.c.d:port`;
  - bracketed IPv6 `[addr]:port`;
  - unbracketed IPv6 `addr:port` (CloudFront's form, split at the last `:`).

  Brackets are only allowed around IPv6. The host must pass `FILTER_VALIDATE_IP` with **no** range flags, because private and loopback addresses are legitimate locally.
- R-CIP-5: the port must be decimal, with no sign, no leading zero, and in `1–65535`.
- R-CIP-6: the result is canonical (`inet_ntop(inet_pton())`, lower case). IPv4-mapped IPv6 (`::ffff:a.b.c.d`) is normalised to IPv4. Otherwise every IPv4 client would share the single `::/64` bucket.
- R-CIP-7, throttle key: the IPv4 address, or the IPv6 `/64` prefix (`2001:db8:1:2::/64`).
- R-CIP-8, **fail closed**: a missing or invalid value gives `ClientAddress::unknown()` (throttle key `unknown`) and an error log line `client address unavailable` with the reason and the value truncated to 64 characters, JSON-encoded. The request is **not** rejected (ADR 0012 §4.3) and there is no fallback source.
- Known and accepted: an unbracketed IPv6 address *without* a port whose last group is 1–5 decimal digits (`2001:db8::1:2`) is read as host `2001:db8::1`, port `2`. The `/64` key is the same either way, so throttling is unaffected. This is documented in the class docblock and tested.

**Files:**
- `backend/src/Http/{ClientIp,ClientAddress}.php`: a pure parser returning a value object with `ip|null`, `throttleKey` and `reason|null`.
- `backend/src/Http/Middleware/ClientIpMiddleware.php`: sets the request attribute `clientAddress` and logs on unknown.
- `ApiConfig` gets `clientIpHeader` (required, a valid header-name token).
- `RequestLoggingMiddleware` adds `clientIp` (the ip, or `unknown`).
- `docker-compose.yml`: `api-php` gets `CLIENT_IP_HEADER: CloudFront-Viewer-Address`.

**Tests first (RED):** `backend/tests/Unit/Http/ClientIpTest.php`, a data provider with at least these cases:

| # | `CloudFront-Viewer-Address` | Expected |
|---|---|---|
| 1 | *(absent)* | unknown, `missing` |
| 2 | `` (empty) | unknown |
| 3 | `203.0.113.7:4711` | ip `203.0.113.7`, key `203.0.113.7` |
| 4 | `203.0.113.7:1`, `203.0.113.7:65535` | valid (port boundaries) |
| 5 | `[::1]:8080` | ip `::1`, key `::/64` |
| 6 | `[2001:db8:85a3:8d3:1319:8a2e:370:7348]:443` | key `2001:db8:85a3:8d3::/64` |
| 7 | `2001:db8:85a3:8d3:1319:8a2e:370:7348:443` (unbracketed, CloudFront form) | same as 6 |
| 8 | `2001:DB8::1:443` | ip `2001:db8::1` (canonical), key `2001:db8::/64` |
| 9 | `::ffff:198.51.100.9:5000`, `[::ffff:198.51.100.9]:5000` | ip and key `198.51.100.9` |
| 10 | `203.0.113.7:1, 198.51.100.2:2` (nginx merge) | unknown, `multiple-values` |
| 11 | `203.0.113.7:1,198.51.100.2:2`, `[::1]:1, [::2]:2` | unknown, `multiple-values` |
| 12 | `203.0.113.7`, `[::1]`, `::1` (no port) | unknown |
| 13 | `203.0.113.7:0`, `:65536`, `:99999`, `:123456` | unknown, port out of range |
| 14 | `203.0.113.7:`, `:-1`, `:+80`, `:8a`, `:080` | unknown |
| 15 | `garbage`, `not-an-ip:80`, `:::`, `:80`, `[]:80` | unknown |
| 16 | `256.1.1.1:80`, `01.2.3.4:80`, `1.2.3:80` | unknown |
| 17 | ` 203.0.113.7:80`, `203.0.113.7:80 `, with `\t`, with `\r\n` | unknown (no trimming) |
| 18 | `fe80::1%eth0:80`, `[fe80::1%25eth0]:80` | unknown (zone id) |
| 19 | `[203.0.113.7]:80`, `[::1]80`, `[::1:80`, `::1]:80`, `[[::1]]:80` | unknown |
| 20 | 65 characters | unknown, `too-long` |
| 21 | `"\xff\xfe:80"` (not UTF-8) | unknown |
| 22 | `2001:db8::1:2` (ambiguous, see above) | ip `2001:db8::1`, key `2001:db8::/64` |
| 23 | header absent but `X-Forwarded-For: 198.51.100.1` and `REMOTE_ADDR` set | unknown (neither read) |

Also `Unit/Http/Middleware/ClientIpMiddlewareTest.php` (attribute set; one error line on unknown; the configured header name is honoured) and `ApiConfigTest` (missing or invalid `CLIENT_IP_HEADER`).

**End-to-end (fulfils the note in `HealthOverNginxTest`):** `backend/tests/Integration/Http/ClientIpOverNginxTest.php` sends:
1. a unique valid `CloudFront-Viewer-Address`;
2. two copies of the header (nginx merges them);
3. no header (nginx fills it from the socket).

All three expect 200 (they are not rejected). The test writes the unique values to `build/client-ip-test/markers.json`. The `make test-integration` recipe then greps `api-php` logs since the start of the run, following the existing `PIPE_MARKERS` pattern. It expects:
- `"clientIp":"<value 1>"`;
- for request 2, an error line `client address unavailable` with reason `multiple-values` and `"clientIp":"unknown"`;
- for request 3, a 172.x/10.x address.

**Acceptance:** all of the above are green; coverage of `ClientIp` is 100% of lines.
- **Docker:** yes.
- **Agents:** tdd-guide; php-reviewer; **security-reviewer (opus/high)**; **round 2 at opus/high**.
- **Commit:** `feat: derive client address from cloudfront-viewer-address and fail closed`.

### Part C: DynamoDB (ADR 0002)

#### WP8: Table schema and local table bootstrap (S–M)
- **Single source of the table definition:** `infra/dynamodb/table.json`. It has the CreateTable fields (`AttributeDefinitions` PK/SK/LSI1SK as `S`, `KeySchema`, `LocalSecondaryIndexes` `LSI1` = PK + LSI1SK with projection `ALL`, `BillingMode: PAY_PER_REQUEST`) plus `"TimeToLiveAttribute": "expiresAt"`. The table name is not in it; it comes from `DYNAMODB_TABLE`. Phase 7's Terraform reads the same file with `jsondecode(file(…))`.
- **Files:**
  - `docker/local-aws/src/Bootstrap/{DynamoDbBootstrapper,DynamoDbClientFactory,TableSchema}.php`. The bootstrapper:
    - creates the table if it is missing and waits for `TableExists`;
    - enables TTL if DynamoDB Local supports it (R4; otherwise it logs "TTL not supported locally" and continues);
    - for an existing table, compares it with the schema: if it matches, logs "no changes"; if it differs, **fails** with "run `make down && make up`". It **never deletes** a table.
  - `BootstrapSettings.php` (+`DYNAMODB_ENDPOINT`, checked by `LocalEndpoint`, and `DYNAMODB_TABLE`); `bin/bootstrap.php` (S3, then DynamoDB).
  - `docker/local-aws/Dockerfile`: `COPY infra/dynamodb/table.json ./config/dynamodb-table.json`.
  - `docker-compose.yml`: `local-aws-init` gets the DynamoDB env and `depends_on: dynamodb: service_healthy`.
  - `.env.example`: `DYNAMODB_TABLE=expense-tracker-local`.
  - DynamoDB Local **stays `-inMemory`** (see Q1).
- **Tests first (RED):**
  - `docker/local-aws/tests/Unit/Bootstrap/{DynamoDbBootstrapperTest,TableSchemaTest}.php` with the SDK `MockHandler`: missing table → create + TTL; matching → only `DescribeTable`; mismatched → exception and no delete call; malformed JSON → exception.
  - `backend/tests/Conformance/DynamoDb/AppTableMatchesSchemaTest.php`: `DescribeTable(DYNAMODB_TABLE)` equals `table.json` (keys, LSI1, projection, billing).
- **Acceptance:** a second `make up` logs "no changes"; `docker/local-aws/src` coverage ≥ 80%.
- **Docker:** yes.
- **Agents:** tdd-guide; php-reviewer. The security reviewer is not triggered (local dev tool; the endpoint allowlist is reused, not changed). If `LocalEndpoint` changes, add security-reviewer (opus/high).
- **Commit:** `feat: create the dynamodb table with lsi1 in the local bootstrap`.

#### WP9: DynamoDB access layer (M)
- **Purpose:** a thin, typed adapter over the SDK that later repositories use. **No feature key builders or repositories here** (they come with Phases 2 and 3).
- **Files** (`backend/src/Infrastructure/DynamoDb/`):
  - `DynamoDbSettings.php`: `DYNAMODB_TABLE` is required; `DYNAMODB_ENDPOINT` and `AWS_REGION` are optional.
  - `DynamoDbClientFactory.php`: connect timeout 1 s, request timeout 3 s, `standard` retry mode with 3 attempts; no explicit credentials (the default chain, which on Lambda is the role).
  - `TableLayout.php`: constants `PK`, `SK`, `LSI1`, `LSI1SK`, `EXPIRES_AT`.
  - `ItemStore.php`:
    - `getItem` (always `ConsistentRead`), `putItem`, `updateItem` (returns `ALL_NEW`), `deleteItem`, `query` (returns `QueryPage{items, lastEvaluatedKey}`) and `transactWrite`;
    - every expression takes `ExpressionAttributeNames` and `ExpressionAttributeValues`, never interpolated values;
    - a `Marshaler` with `wrap_numbers` off.
  - `QueryPage.php`.
  - Error mapping:
    - `ConditionFailed.php` ← `ConditionalCheckFailedException`;
    - `TransactionCancelled.php` ← `TransactionCanceledException`, with the cancellation reason codes;
    - `StorageFailure.php` ← anything else.
    - Exception messages never contain item contents.
- **Tests first (RED):**
  - `Unit/Infrastructure/DynamoDb/{ItemStoreTest,DynamoDbSettingsTest,DynamoDbClientFactoryTest}.php` with `MockHandler`: request shapes (`ConsistentRead`, names and values present), the error mapping, and no item data in messages.
  - `backend/tests/Integration/DynamoDb/ItemStoreTest.php` against DynamoDB Local. It uses `backend/tests/Support/TemporaryTable.php` (a random table name created from `infra/dynamodb/table.json` and deleted in `tearDown`), with clients only from `LocalAws`. Cases:
    - round trip of integers (`amountCents`), Unicode and empty-string attributes;
    - a conditional put on an existing item raises `ConditionFailed`;
    - `updateItem` with `attribute_exists(PK)` on a missing item raises `ConditionFailed`;
    - `LSI1 BETWEEN EXP#<from> AND EXP#<to>~`, descending and consistent;
    - paging with `Limit` + `ExclusiveStartKey`;
    - a transaction in the shape of a user + email lock, which succeeds, then is cancelled with the reasons `[ConditionalCheckFailed, None]`;
    - a query never returns another partition's items.
- **Acceptance:** `backend/src` coverage ≥ 80%; integration green in `make test-integration` (part 1, no runner needed).
- **Docker:** yes.
- **Agents:** tdd-guide; php-reviewer; **security-reviewer (opus/high)** (DB queries, expression handling, error leakage).
- **Commit:** `feat: add dynamodb item store with condition and transaction error mapping`.

### Part D: Docs and wrap-up

#### WP10: Docs, ADR notes and wrap-up (S)
- **Files:**
  - `docs/local-dev.md`:
    - curl examples now need `X-Origin-Verify`;
    - how to read the request log and `clientIp` (`make logs`);
    - `make test-contract`;
    - the DynamoDB table, which is in memory and recreated by `make up`.
  - `README.md` quick start.
  - ADR 0011: "Verified in Phase 1" (the opis result).
  - ADR 0012: "Implemented in Phase 1", the R-CIP rules and the sha256 + `hash_equals` compare. These are clarifications within the accepted decision. If the owner reads any of them as a *change*, it becomes a new ADR 0013 instead.
  - ADR 0002: note that `infra/dynamodb/table.json` is the single table definition.
  - ADR 0007: note on the DynamoDB bootstrap and in-memory storage.
  - PLAN §12, Phase 1 status → Done; this plan's status → Done.
- **Lesson from Phase 0, Haiku output is fact-checked:** doc-updater (haiku/low) drafts. Before the commit, the main session checks every make target against `make help`, every path with Glob, every env var against `.env.example` and `docker-compose.yml`, and runs every curl command shown. The ADR note text is drafted by the main session from the WP results; haiku only formats it.
- **Before the PR is marked ready:** `/update-docs`, `/code-review`, `/verification-loop`; then `/ecc:cost-report` (compare with Phase 0).
- **Docker:** yes (only to verify the commands).
- **Commit:** `docs: phase 1 local-dev, readme and adr notes`.

## 3. Dependencies

```
WP1 ─► WP2 ─► WP3 ─► WP4 ─► WP5 ─► WP6 ─► WP7 ─┐
  └──────────────► WP8 ─► WP9 ─────────────────┴─► WP10
```
- WP5 needs WP2 (validator) and WP4 (kernel). WP6 and WP7 need WP5, so their integration tests are contract-validated.
- WP8 and WP9 are independent of WP2–WP7. They are done on the same branch, one after the other (they share `docker-compose.yml` and `.env.example`).

## 4. Test plan

| Layer | What | Where | Run by |
|---|---|---|---|
| Unit | Request, query and JSON parsing, router, problems, pipeline, kernel, config, middlewares, `ClientIp` table, `ItemStore` with `MockHandler`, contract validator | `backend/tests/Unit/**` | `make test-unit` (CI `backend`) |
| Unit (local tooling) | DynamoDB bootstrapper, table schema | `docker/local-aws/tests/Unit/**` | `make test-local-aws` (CI `local-aws`) |
| Contract | Route parity; every operation documents 403/500; bundled spec loads | `backend/tests/Contract/**` | `make test-contract` (CI `integration`) |
| Conformance | App table equals `table.json` (+ the existing suites) | `backend/tests/Conformance/**` | `make test-conformance` |
| Integration | Health, origin verify, client IP through nginx (contract-validated); `ItemStore` against DynamoDB Local | `backend/tests/Integration/**` | `make test-integration` |
| Front-end | Unchanged; `schema.gen.ts` regenerated, `vue-tsc` catches drift | `frontend/` | `make frontend-test`, `frontend-lint` |

- **Coverage:** ≥ 80% lines on `backend/src` and `docker/local-aws/src` (enforced by the existing `coverage:check`). Target 100% on `ClientIp`, `OriginVerifyMiddleware`, `QueryString` and `JsonBody`.
- **E2E:** the approved deviation (PLAN §12) still applies. The curl smoke test is Phase 6 and Playwright is L1.

## 5. Branch, PR, CI and owner gates

- **Branch** `feat/phase-1-backend-core`. **One PR for Phase 1**, opened as a **draft after WP5**, marked ready after WP10, and **squash-merged** like Phase 0. Body: definition-of-done checklist, the D1 outcome, test plan.
- **CI:** `main` requires 7 checks. Phase 1 **adds no job**: the new contract steps go inside `integration`. If implementation does add or rename a job, the owner must update the ruleset by hand (the main session gives the click steps and only runs read-only `gh api` checks).
- **Owner gates:**
  - **G1, approve this plan.** This includes deleting exactly the three Phase 0 files listed in WP4. Any other deletion is asked separately.
  - **G2, only if D1 fires:** switch to ADR 0011's fallback validator.
  - **G3, PR merge,** including the ADR notes (and whether any of them needs to be an ADR 0013 instead).
  - **G4, only if a CI job is added or renamed:** ruleset update.
  - **AWS:** no gate is needed, because **Phase 1 creates or deploys nothing on AWS ($0)**.

## 6. Not in Phase 1 (carried notes)

- **Phase 2:**
  - Authentication and CSRF middlewares go in the chain slot after `RequestLogging`.
  - Login throttling consumes `ClientAddress::throttleKey`.
  - First use of `JsonBody` and `ValidationProblem`; add a `ValidationProblemDetails` schema to the contract when first used.
  - `Clock` and the UUID generator (introduced with their first consumer).
  - Key builders for user, email lock, session and throttle.
  - Wire `ItemStore` into `ApiFactory`; give `api-php` the DynamoDB env and the **fake** local key pair; set `LATE_SERVICES = api frontend` in the Makefile.
- **Phase 3:** category and expense keys, the `LSI1` read budget and cursor, the category resolver.
- **Phase 7:**
  - Terraform reads `infra/dynamodb/table.json`.
  - Lambda env: `ORIGIN_VERIFY_SECRETS` (two slots), `CLIENT_IP_HEADER=CloudFront-Viewer-Address`.
  - The origin request policy forwards `CloudFront-Viewer-Address`.
  - Log retention.
- **Phase 8, verify on AWS:**
  - a forged `CloudFront-Viewer-Address` is replaced by CloudFront;
  - the IPv6 format through CloudFront (R2);
  - API Gateway v2 joining duplicate headers (R3);
  - ~~Bref FPM does not split long JSON log lines.~~ Closed by the WP4 security review: the pinned Bref image's `php-fpm.conf` already sets `log_limit = 256000`.

### Security notes from the WP3 review (2026-10-13)

From the security review of the WP3 HTTP primitives (opus/high).

- **The query string on Lambda is normalised by Bref** (S-M1).
  - Bref rebuilds `QUERY_STRING` from API Gateway's `rawQueryString`, so on AWS:
    - a repeated name is merged, and the last value wins;
    - an invalid `%` escape is accepted as text;
    - an empty name is dropped.
  - `QueryString`'s strict rules hold only for raw queries, i.e. locally behind nginx. `QueryStringBrefTest` pins the production behaviour.
  - Decision (main session): accept this and document it. Handlers validate their own parameters. Switching to `BREF_RUNTIME=function` would be an ADR 0012 change and is not planned. A note goes in ADR 0012 in WP10.
- **Route ids** (S-M2). The router returns 404 when a decoded parameter contains `/`, `\`, a Unicode C-category character, or is exactly `.` or `..`. **Every handler must still validate ids as UUIDs before building DynamoDB or S3 keys** (`EXP#<id>`, `receipts/<user>/<id>/…`).
- **WP4:**
  - The error middleware never renders `getPrevious()`, a non-HttpProblem message or a trace.
  - Lower nginx `client_max_body_size` toward 64k.
  - `nosniff` and `no-store` go on every PHP response, as planned.
  - Never read `HTTP_PROXY` (httpoxy) or `getenv('HTTP_PROXY')`.
- **WP6, origin check:** `hash_equals` runs on the **whole** header value. Never split on commas, because API Gateway joins repeated headers.
- **WP7, client IP:**
  - Read only `cloudfront-viewer-address`.
  - On AWS this is safe only while the CloudFront origin request policy is an **allow-list** (never "all viewer headers") and the origin check runs first. Underscore variants such as `X_Origin_Verify` reach FPM there.
- **Phase 7/8 smoke test, through CloudFront and API Gateway:** send `%2F`, `%23`, `..`, an underscore header and a duplicate query name. Assert what PHP receives, including whether `rawPath` keeps the encoding.
- **Phase 3, money:** amounts must be integer cents or a decimal string in the schema, never JSON floats, which lose precision silently.
- **Phase 2, sessions:** Bref rebuilds `Cookie` from v2 `cookies[]`, so duplicate cookie names are possible (cookie tossing). Define which session cookie wins, or reject duplicates.
- **Validators:** JSON objects with numeric keys decode to PHP lists. Never use `array_is_list` to tell objects from arrays. Reject control characters in string fields.

### Notes from the WP4 reviews (2026-10-14)

From ecc:php-reviewer (sonnet) and ecc:security-reviewer (opus/high) on `3605388..5705d1d`. No CRITICAL or HIGH security findings. The fix batch (tdd-guide) covers:
- 4xx `HttpProblem`s logged with their real status, not 500;
- fail closed on **any** startup `Throwable` (factory logs the class only), a `try/catch` and `set_error_handler` in the front controller, and a logged last-resort catch in `ApiKernel`;
- Lambda ini parity: `backend/php/conf.d/app.ini` (`zend.exception_ignore_args`, `expose_php = Off`, `enable_post_data_reading = Off`), copied into the worker image and checked by `make check-php-ini` in the pinned Bref image;
- caps on logged `exceptionMessage` and `method`; generic body for 5xx `HttpProblem`s; explicit 413 when the raw body exceeds `JsonBody::MAX_BYTES`; requests rejected in `handleGlobals` are logged; nginx `client_max_body_size` lowered toward 64k (closes the WP3 note).

Deferred, with the owner WP:
- **WP6:**
  - Build the request-path `JsonLogger` **after** config validation and pass the `ORIGIN_VERIFY_SECRETS` slots to its redaction list. Test: an exception whose message contains a slot is logged as `[REDACTED]`.
  - Use the validated `ApiConfig` returned by the loader; never re-read `$env`.
  - Reject whitespace-only values; `InvalidConfiguration` names variables only.
  - Make `ApiConfig::validate()` private (or `@internal`) and test it through `fromEnvironment` once `REQUIRED` is filled.
- **Phase 2:** add `cookie`, `set-cookie`, `x-origin-verify` and `x-csrf-token` to `JsonLogger`'s key-based redaction.
- **Phase 7/8, Lambda packaging (round-2 security review):** the PHP hardening reaches AWS only if the package ships it. `make check-php-ini` proves Bref reads `/var/task/php/conf.d`, not that the zip contains the file.
  - The API and worker Lambda zips use `backend/` as their root and **must** contain `php/conf.d/app.ini`.
  - The zip build step asserts that entry (list the zip, fail if missing).
  - The Phase 8 smoke test asserts no `X-Powered-By` header through CloudFront and API Gateway.
- **Optional, not planned:** an `X-Request-Id` response header for support correlation.

### Notes from the WP6 reviews (2026-10-14)

From ecc:php-reviewer (sonnet) and ecc:security-reviewer (opus/high) on `6ba0699..262c8fd`. No CRITICAL or HIGH findings. The fix batch covers: the origin check now runs before the 413/400 pre-pipeline rejections (a caller without the header always gets 403), redaction before capping plus a pass over the encoded log line (objects, array keys, JSON-escaped secrets), `"` and `\` no longer allowed in secret slots, duplicate slots rejected.

Carried:
- **Phase 7, Terraform:** the generated `ORIGIN_VERIFY_SECRETS` slots must match `ApiConfig::SLOT_PATTERN` (visible ASCII, no space, comma, `"` or `\`): set `random_password.override_special` accordingly.
- **Phase 7/8 smoke test:** also send `X_Origin_Verify: junk` through CloudFront and expect 200 (the allow-list policy must not forward it, and CloudFront overwrites the same-named custom header).
- **WP10, ADR 0012 §4.2 wording:** the check is the first middleware after error handling **and request logging** (logging reads no headers, and keeping it outside gives every 403 a request line).
- **Keep it that way:** with `clear_env = no`, `ORIGIN_VERIFY_SECRETS` is in `$_SERVER` on every request. Never log `$_SERVER`.
- **Later (ops):** every rejected request writes two lines (warning + request line). If scanner traffic against the origin floods CloudWatch, sample or drop the warning; note it in the runbook.

### Notes from the WP7 reviews (2026-10-14)

From ecc:php-reviewer (sonnet) and ecc:security-reviewer (opus/high) on `98151a7..c3d0adb`. No CRITICAL or HIGH findings; the parser rejected every hostile form probed. The fix batch covers: redact before cutting the logged header value, `CLIENT_IP_HEADER` accepts only `CloudFront-Viewer-Address` (case-insensitive), a canonical IPv6 marker in the integration test (was a ~12% flake), specific and loud Makefile log checks, the attribute renamed to `clientAddress`, a write-once `RequestLogContext`.

Design note: `RequestLogContext` is a small per-request mutable holder, a deliberate exception to the immutability rule. `RequestLogging` must stay outside the origin check, and the request is immutable and flows inward, so the outer logger cannot otherwise see the address that `ClientIp` derives. `"clientIp":"unknown"` in a request line means "failed **or never evaluated**" (403, deferred 413/400); a consumer must not read it as proof that the header was parsed.

Carried:
- **Phase 7, Terraform:** a test/check that the API origin request policy's `header_behavior` is an **allow-list** (never "all viewer headers") and lists no underscore names. Bref maps `CloudFront_Viewer_Address` and `CloudFront-Viewer-Address` to the same FastCGI variable, so the allow-list is what keeps a client from choosing its own address.
- **Phase 7/8 smoke test:** send a spoofed `CloudFront-Viewer-Address`, a `CloudFront_Viewer_Address`, and both; assert the logged `clientIp` is the real source address.
- **Phase 2, throttling:**
  - add a coarser IPv6 tier (`/56` or `/48`) next to the `/64` key: a holder of a `/48` (hosting, 6to4) spreads over 65,536 `/64` keys; Teredo users collapse into their relay's `/64`;
  - add a per-account limit, not only per address;
  - a CloudWatch metric filter and alarm on `"message":"client address unavailable"` (real users in the shared `unknown` bucket mean a misconfiguration).

## 7. Risks and decision points

| # | Risk / trigger | Mitigation / fallback | WP |
|---|---|---|---|
| D1 | opis cannot resolve the bundled `$ref`s | ADR 0011 fallback (`league/openapi-psr7-validator`, dev only); owner gate G2 | WP2 |
| R1 | The origin check breaks the Compose healthchecks (`api` gets 403) | The healthcheck sends the header; `frontend` already goes through the proxy | WP6 |
| R2 | The owner's existing `.env` lacks the new variables | Compose defaults (`${VAR:-…}`) equal `.env.example` | WP6–8 |
| R3 | php-fpm splits log lines over 1024 bytes, which breaks JSON lines and the log-grep tests | `log_limit = 8192` (verify R5); Bref checked in Phase 8 | WP4 |
| R4 | DynamoDB Local lacks the TTL API | Skip TTL locally with a log line; nothing depends on expiry locally (Phase 0 note) | WP8 |
| R5 | The table definition drifts between local and AWS | One JSON file, a conformance test, and the same file in Terraform | WP8 |
| R6 | Unbracketed IPv6 is ambiguous | Harmless for the `/64` key; documented and tested (case 22) | WP7 |
| R7 | IPv4-mapped addresses share one `/64` bucket | Normalised to IPv4 (R-CIP-6) | WP7 |
| R8 | A timing or length leak in the secret compare | sha256 both sides, then `hash_equals` over all slots | WP6 |
| R9 | Error responses leak internals or secrets | Generic 500; `zend.exception_ignore_args`; `JsonLogger` redaction with the secrets | WP4 |
| R10 | Scope creep into Phase 2 | Explicit list in section 6; WP9 has no repositories or key builders | all |
| R11 | Shared Docker host damaged by cleanup | The rule in section 2, repeated in every agent brief | all |

## 8. Lessons from Phase 0, applied

1. **Round-2 escalation:** the re-review of fixes in WP3 (parsers), WP6 and WP7 runs at opus/high.
2. **Haiku docs are fact-checked** against the tree and by running the commands (WP10).
3. **Shared Docker host:** the rule above is in every brief for a WP marked "Docker: yes".
4. **Small, contract-first WPs:** Phase 0 grew from M to L. Phase 1 keeps feature-specific code out (section 6) and tight briefs (file paths + exact question).

## 9. Owner decisions (answered 2026-10-13: all three recommended defaults)

1. **DynamoDB Local data:** keep it in memory, so data vanishes on `make down` (the same lifecycle as the S3 emulator's tmpfs), or persist it in a named volume? **Decided: in memory.** Persisted records would point at receipt files that no longer exist.
2. **IP addresses in request logs:** log the full client IP on every request line (useful for security; it is personal data, kept for the log retention set in Phase 7)? **Decided: yes, with 30-day retention in Phase 7.**
3. **PR shape:** one PR for the whole phase, draft after WP5, as in Phase 0? **Decided: yes.**
