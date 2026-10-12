# Phase 0 — Foundations: implementation plan

| | |
|---|---|
| Status | **Approved by the owner, 2026-10-09** |
| Phase | 0 (PLAN §12, essential track) |
| Branch | `feat/phase-0-foundations`, from `main` (ADR 0012 accepted, PR #2) |
| Inputs | PLAN §4, §5, §10–§13; ADR 0001, 0003, 0007, 0009, 0010, 0011, 0012; Phase 0 research (2026-10-09) |
| Drafted by | `ecc:planner`, from the `Research & Reuse` report (ECC step 0) |
| AWS | **None.** No AWS calls, no credentials, no cost. Terraform only runs `fmt` and `validate`. |

**Owner decisions at approval (2026-10-09):** one PR for the whole phase, opened as a draft after WP4; front-end types generated from the contract already in Phase 0; PHP and Node tools run in pinned containers locally, with the 80% coverage bar enforced from Phase 0; the S3 conformance tests stay as a permanent suite; `spikes/` is deleted at the end of Phase 0 (G3).

## 1. Goal and definition of done

**Goal:** a repo where the whole local stack starts with one command, the receipt pipe runs end to end on a placeholder
handler, and every CI check that later phases depend on already exists and is required. Two spikes settle the open
points in ADR 0007 and ADR 0012 before anything is built on them.

**Done means (each item is demonstrable):**
- **`make up`** from a clean clone, with only Docker, make and git (no host PHP or Node):
  - creates `.env` from `.env.example` if missing, builds images and starts `dynamodb`, `sqs` (ElasticMQ), `s3` (the
    chosen emulator), `s3-events-relay`, `local-aws-init` (one-shot), `api` (nginx), `api-php` (php-fpm), `worker`
    (Bref + RIE), `worker-runner` and `frontend` (Vite);
  - waits until all are healthy; running it again changes nothing; all host ports bind to `127.0.0.1` only;
  - then: `curl localhost:8080/api/v1/health` returns `200 {"status":"ok"}`; `http://localhost:5173` shows
    "API: ok" (browser → Vite proxy → nginx → php-fpm); an object uploaded under `receipts/` is consumed by the
    worker within about 10 s (worker log line, queue and DLQ empty); a poison message lands in `receipts-dlq` after 3
    receives.
- **`make test`** runs backend unit and architecture tests, `docker/local-aws` unit tests, conformance tests (S3
  emulator, ElasticMQ, DynamoDB Local), pipeline integration tests and front-end Vitest. Line coverage is at least
  80% for `backend/src`, `docker/local-aws/src` and `frontend/src`.
- **`make lint`** runs PHP-CS-Fixer (dry run) and PHPStan (max level, strict rules, phpunit extension) for both PHP
  projects; ESLint, Prettier `--check`, `vue-tsc`; Redocly lint plus "regenerated `schema.gen.ts` has no diff";
  `terraform fmt -check` and `validate` in Docker; the platform-pin, image/action-pin and Bref-boundary checks;
  `composer audit` and `npm audit --audit-level=high`.
- **CI** (`.github/workflows/ci.yml`) is green on the PR: actions pinned by commit SHA, `permissions: contents: read`,
  no secrets. Every job is a required check on `main` (a GitHub setting the owner applies, gate G4).
- **ADRs:** ADR 0007 records the emulator decision; ADR 0012 gets a "Verified in Phase 0" section.
- **Docs:** `README.md` and `docs/local-dev.md` explain how to run and test everything.

## 2. Work packages

Each package is one reviewable commit (or a few), with conventional commit messages. Order: spikes → decision gate
→ foundations → pipe → API/front-end/contract → infra/CI → docs. CI and the Makefile grow with each package (PLAN §10:
"as each check is introduced").

**Where commands run:** locally, PHP tools run in a `php` tools container (`php:8.5-cli-alpine` + Composer + pcov),
because the host PHP is 8.4; Node tools run in a pinned `node:24.21.0-alpine` container. Both run as the host user
(`UID`/`GID` exported by make). In CI, jobs use `shivammathur/setup-php` / `actions/setup-node` and call the same make
targets with `RUN_PHP=` / `RUN_NODE=` empty, so the commands are identical.

### Part A: Spikes and decisions

#### WP1: Plan and repo hygiene (S)
- **Files:** this plan; `.gitignore` (add `.env`, `vendor/`, `node_modules/`, `coverage/`, `.phpunit.cache/`,
  `contract/dist/`, `.terraform/`, `*.tfstate*`, `build/`); `.editorconfig`; `.gitattributes` (LF).
- **Agent:** doc-updater. **Commit:** `docs: add phase 0 implementation plan`.

#### WP2: Spike A: Bref 3 on php-85 (M, timebox 1 day)
- **Purpose:** answer every "To verify in Phase 0" item of ADR 0012 and confirm PHP 8.5 is workable.
- **Files:** `spikes/bref-php85/{run.sh, Dockerfile.worker, worker.php, public/index.php, composer.json, events/*.json}`;
  report `docs/spikes/bref-php85.md`.
- **Tests first (RED):** `run.sh` is a list of named checks printing PASS/FAIL, exiting non-zero on any failure;
  run it before the images exist to see it fail.
  - **A1 Pins:** digests of `bref/php-85:3`, `bref/php-85-dev:3`, `bref/extra-gd-php-85:3`, `php:8.5-fpm-alpine`,
    `php:8.5-cli-alpine`, `composer:2.10.3`; exact `PHP_VERSION` patch of `bref/php-85`.
  - **A2 Function mode:** `BREF_RUNTIME=function`, closure handler, POST to `:8080/2015-03-31/functions/function/invocations`:
    a 3-record batch with one `"fail"` body returns `batchItemFailures` with that id. When the handler throws,
    record status, body (`errorType`/`errorMessage`) and any `X-Amz-Function-Error` header (WP11 depends on it).
    Record behaviour when `BREF_RUNTIME` is unset.
  - **A3 FPM mode:** API Gateway v2 event with a `cloudfront-viewer-address` header: `REMOTE_ADDR` is `127.0.0.1`,
    the header arrives as `HTTP_CLOUDFRONT_VIEWER_ADDRESS`, container env vars are visible to PHP.
  - **A4 GD:** with the GD layer copied in, `gd_info()` reports JPEG, PNG and **WebP**; `imagecreatefromwebp` works.
  - **A5 Argon2id:** `password_hash(…, PASSWORD_ARGON2ID)` works in `bref/php-85` and `php:8.5-fpm-alpine`.
  - **A6 Telemetry:** find the switch in `vendor/bref/bref` 3.0.12 (name and code path); capture traffic with a
    pinned tcpdump sidecar with and without it. If the ping is skipped under RIE anyway, record that the result rests
    on reading the source.
  - **A7 GD layer version:** does `bref/extra-php-extensions` ship a `layers.json`?
  - **A8 Dependency tree:** `composer show --tree bref/bref` on 8.5 with the platform pin.
  - **A9 Tools on 8.5** (E_ALL, deprecations as errors): PHPUnit 13 (fallback 12.5); PHPStan 2.3.1 + strict-rules +
    phpstan-phpunit; PHP-CS-Fixer 3.95.27 without `PHP_CS_FIXER_IGNORE_ENV`; opis/json-schema 2.6.0 (validate a
    2020-12 schema); aws-sdk-php ^3.400 (create DynamoDB, S3, SQS clients).
  - **A10 Coverage driver:** `pecl install pcov` on `php:8.5-cli-alpine` (fallback Xdebug).
  - **A11 Production vendor:** a `--no-dev` `vendor/` loads inside `bref/php-85` (`platform_check.php` passes).
- **Acceptance:** report table per item (result, command, output excerpt), digests, decisions D1, D2, D3, D7.
- **Agents:** security-reviewer reads A3 and A6. **Commits:** `chore: add bref php-85 spike harness`,
  `docs: bref php-85 spike report`.

#### WP3: Spike B: S3 emulator (`pgsty/silo`) (M, timebox 1 day; Floci repeat +0.5 day)
- **Files:** `spikes/s3-emulator/{compose.yml, composer.json, phpunit.xml, tests/{PostPolicyTest,NotificationTest,CorsTest}.php, capture/webhook-capture.php, bootstrap.php, run.sh}`;
  report `docs/spikes/s3-emulator.md`; fixtures in `docs/spikes/fixtures/`.
- **Tests first (RED),** written before silo is started; every presigned POST built with aws-sdk-php `PostObjectV4`
  and sent as multipart with Guzzle:
  - **Eight POST tests:** over max size, under min size, wrong `Content-Type`, different key, extra unsigned field,
    expired policy and bad signature are each rejected (record status and error code); a valid upload is accepted.
  - **Notifications:** exactly one webhook per upload to `receipts/`; none outside the prefix, for
    `receipt-thumbnails`, or on delete; record what an overwrite sends; `Records[0].s3.object.key` is URL-encoded
    (key with a space and a `+`); record `eventName` and `eventSource`; auth token header is sent; what happens when
    the receiver is down (`queue_dir` replay?).
  - **Configuration without mcli (preferred):** webhook target via server env vars, bucket rule via SDK
    `PutBucketNotificationConfiguration` with a prefix filter; fallback a pinned `mcli` image.
  - **CORS:** preflight from `http://localhost:5173` allowed after `PutBucketCors`; another origin refused.
  - **Presigned GET** signed for the host-facing endpoint (`http://localhost:9000`) works while the API container
    uses `http://s3:9000` (matters in Phase 5).
  - **Health** endpoint usable by the Compose healthcheck; bootstrap idempotent.
- **If silo fails a mandatory criterion:** rerun the suite on `floci/floci:2.2.0` with
  `FLOCI_AUTH_VALIDATE_SIGNATURES=true`.
- **Acceptance:** report scores ADR 0007's three criteria (pass/fail per test), digest, licence (AGPL: unmodified,
  dev-only, not distributed), single-maintainer risk, fixtures; recommends silo, Floci or pinned MinIO.
- **Commits:** `chore: add s3 emulator spike harness`, `docs: s3 emulator spike report`.

#### WP4: Gate G1, record spike decisions (S) — owner approval required
- **Files:** ADR 0007 — fill in "S3 emulator: chosen in a Phase 0 spike" with the result; ADR 0012 — add a "Verified
  in Phase 0" section (results, links to reports) and record any fallback (8.4, WebP, telemetry); a decision that
  changes 0012 rather than confirming it gets a new ADR; `docs/adr/README.md` if titles change; PLAN §4/§13 — replace
  "S3-compatible emulator" with the chosen one.
- **Agents:** architect reviews, doc-updater applies. **Commit:** `docs: record phase 0 spike decisions in ADR 0007 and 0012`.
- The Phase 0 PR is opened as a **draft** here so G1 is reviewed early.

### Part B: Backend foundation

#### WP5: Backend Composer project, tools and first CI job (M)
- **Files:**
  - `backend/composer.json`: `require` `php: ~8.5.<patch>`, `aws/aws-sdk-php: ^3.400`, `bref/bref: 3.0.12`;
    `require-dev` PHPUnit, PHPStan + extensions, PHP-CS-Fixer (opis deferred to Phase 1); `config.platform.php =
    8.5.<patch>` (from A1), `sort-packages`; PSR-4 `App\\` → `src/`, `App\\Tests\\` → `tests/`; scripts `test`,
    `test:unit`, `stan`, `cs`, `cs:fix`; plus `composer.lock`.
  - `backend/phpunit.xml.dist` (suites `unit`, `architecture`, `conformance`, `integration`; coverage `src/`;
    `failOnRisky`, `failOnWarning`, deprecations fail); `backend/phpstan.neon.dist` (level max, strict-rules,
    phpunit extension; paths `src`, `public`, `entrypoints`, `tests`); `backend/.php-cs-fixer.dist.php` (PER-CS +
    `declare_strict_types`).
  - `docker/php/Dockerfile` (stages `fpm` = `php:8.5-fpm-alpine@sha256`, `dev` = cli + pcov + Composer from
    `composer:2.10.3@sha256`); `docker/php/conf.d/app.ini` (`expose_php=Off`, `display_errors=Off`, `log_errors=On`).
  - `scripts/check-coverage.php` (fails below threshold from the clover report).
  - `Makefile`: `help`, `php-install`, `test-unit`, `lint-php`, `fmt`.
  - `.github/workflows/ci.yml`: job `backend` (`composer validate --strict`, install, audit, cs, stan, unit +
    architecture tests with coverage); `permissions: contents: read`; `concurrency`; `timeout-minutes`.
- **Tests first (RED):** `backend/tests/Architecture/BrefBoundaryTest.php` backed by
  `tests/Support/ForbiddenReferenceScanner.php` (`token_get_all`). Fixtures `clean/` and `leaky/` (`use Bref\…`,
  `\Bref\Context`, `getenv('AWS_LAMBDA_FUNCTION_NAME')`, `$_SERVER['AWS_LAMBDA_…']`): every leak flagged, clean passes;
  then the real `src/` and `public/` have no hits.
- **Acceptance:** `make lint-php test-unit` green in the container; CI `backend` green; the leaky fixture fails.
- **Agents:** tdd-guide, then php-reviewer. **Commits:** `chore: scaffold backend composer project and tooling`,
  `test: enforce bref boundary in src and public`, `ci: add backend job`.

#### WP6: Platform-pin guard (S)
- **Files:** `scripts/check-php-platform.sh`, `scripts/tests/check-php-platform.test.sh` + fixtures; Makefile
  `check-platform`; CI job `guards`.
- **Check:** read the `bref/php-85` digest from `docker/worker/Dockerfile` (fixture until WP10), run
  `php -r 'echo PHP_VERSION;'` in it, compare with `config.platform.php` (`jq`); install `--no-dev` into
  `build/vendor-check/` and require `vendor/autoload.php` inside the image.
- **Tests first (RED):** matching and mismatching `composer.json` fixtures assert exit codes.
- **Agent:** code-reviewer. **Commit:** `ci: check composer platform matches bref php-85 layer`.

### Part C: Local AWS stand-ins

#### WP7: Compose base, DynamoDB Local and ElasticMQ, with conformance tests (M)
- **Files:**
  - `docker-compose.yml`: `dynamodb` (`amazon/dynamodb-local:3.3.1@sha256`, `-sharedDb -inMemory`, 8000); `sqs`
    (`softwaremill/elasticmq-native:1.7.1@sha256`, 9324); `php` tools service (profile `tools`).
  - `docker/elasticmq/elasticmq.conf`: `node-address.host="*"`; `receipts` (`defaultVisibilityTimeout = 10 seconds`,
    DLQ `receipts-dlq`, `maxReceiveCount = 3`); `conformance-redrive` + DLQ with 1 s visibility for fast tests.
  - `.env.example`: non-secret local values only, header comment saying so; `AWS_REGION` placeholder;
    `AWS_ACCESS_KEY_ID=localdev`, `AWS_SECRET_ACCESS_KEY=localdevsecret`; `AWS_EC2_METADATA_DISABLED=true`;
    endpoints and queue URLs.
  - `backend/tests/Support/LocalAws.php`: SDK clients from env; **throws if no explicit endpoint is set**, so tests
    can never reach real AWS. `~/.aws` is never mounted.
  - Makefile: `up`, `down` (no `-v`), `ps`, `logs`, `test-conformance`.
- **Tests first (RED):** `tests/Conformance/Sqs/ElasticMqTest.php` (send/receive/delete over the SDK JSON protocol;
  `ApproximateReceiveCount` increments; DLQ after the 3rd receive); `tests/Conformance/DynamoDb/DynamoDbLocalTest.php`
  (temporary table with `LSI1`, `LSI1SK BETWEEN` with `ConsistentRead`, table deleted; no reliance on TTL).
- **Acceptance:** `make up && make test-conformance` passes; CI `integration` job (compose `up --wait`, suites in the
  `php` container, logs dumped on failure) green.
- **Agents:** tdd-guide; security-reviewer (127.0.0.1 ports, `.env.example`). **Commits:** `chore: add compose with
  dynamodb-local and elasticmq`, `test: conformance tests for elasticmq and dynamodb-local`, `ci: add integration job`.

#### WP8: S3 emulator service, idempotent bootstrap and POST-policy conformance (M)
- **Files:** `s3` service (digest from WP3; webhook target via env vars if confirmed, relay token, `queue_dir`,
  healthcheck); `local-aws-init` one-shot service; `docker/local-aws/` — a separate Composer project (namespace
  `LocalAws\`, `php:8.5-cli-alpine`) with its own `Dockerfile`, `composer.json`/`.lock`, `phpunit.xml.dist`,
  `phpstan.neon.dist`, `.php-cs-fixer.dist.php`; `bin/bootstrap.php`; `src/Bootstrap/{ServiceWaiter,S3Bootstrapper}.php`
  (create `receipts` and `receipt-thumbnails` if missing; CORS for the Vite origin; notification rule ObjectCreated +
  prefix `receipts/` on `receipts` only).
- **Tests first (RED):** `docker/local-aws/tests/Unit/Bootstrap/S3BootstrapperTest.php` with an SDK `MockHandler`
  (existing buckets → no create calls; rule only on `receipts`); the 8 POST cases, CORS and presigned GET ported
  from the spike into `backend/tests/Conformance/S3/{PostPolicyEnforcementTest,CorsTest,PresignedGetTest}.php` —
  a permanent conformance suite that catches regressions on every emulator digest bump.
- **Acceptance:** second `make up` logs "no changes"; all 8 POST cases pass; CI `local-aws` job (cs, stan, unit with
  coverage) green.
- **Agents:** tdd-guide; php-reviewer; security-reviewer. **Commits:** `chore: add s3 emulator and idempotent local
  bootstrap`, `test: s3 post policy and cors conformance`, `ci: add local-aws job`.

#### WP9: s3-events-relay (S–M)
- **Files:** `docker/local-aws/bin/relay.php` (router for `php -S 0.0.0.0:8080`; not published to the host);
  `src/Relay/{RelayHandler,MessageSender,SqsMessageSender}.php`; `s3-events-relay` service; fixtures
  `tests/Fixtures/s3-events/{silo-object-created-post.json, aws-object-created-post.json, aws-s3-test-event.json}`
  (AWS ones from AWS documentation examples, silo one captured in WP3).
- **Behaviour:** only `POST` with the right bearer token (405 other methods, 401 missing/wrong token); 400 for invalid
  JSON or missing/empty `Records`; otherwise sends `{"Records":[…]}` (records untouched, emulator wrapper dropped) to
  the queue and returns 204; SQS failure → 502 so the emulator retries; JSON-line logs, never the token.
- **Tests first (RED):** `tests/Unit/Relay/RelayHandlerTest.php` with a fake `MessageSender`, every status above,
  body equals the fixture's `Records` exactly (encoded key included).
- **Integration:** `backend/tests/Integration/Pipeline/S3ToQueueTest.php`: presigned POST to `receipts/…` produces
  exactly one AWS-shaped message; `receipt-thumbnails` or out-of-prefix uploads produce none (runner stopped via
  Compose profile `no-runner`, or the test reads the queue first).
- **Agents:** tdd-guide; security-reviewer. **Commit:** `feat: add local s3 events relay to elasticmq`.

#### WP10: Worker handler skeleton, worker image and boundary (M)
- **Purpose:** the smallest real worker, same code locally and on Lambda, reporting `batchItemFailures`. Receipt
  logic is Phase 5.
- **Files:**
  - `backend/src/Queue/{RecordProcessor.php (interface), SqsBatchProcessor.php}`: one call per record; any
    `Throwable` → that `itemIdentifier` in `batchItemFailures`; event without `Records` → exception (whole
    invocation fails, as on AWS).
  - `backend/src/Receipts/{S3EventParser,S3ObjectRef,ReceivedReceiptLogger}.php`: parser URL-decodes keys (`+` →
    space), never reads `eventSource` or the `eventName` prefix, `s3:TestEvent` → zero objects; logger logs bucket and
    key (Phase 5 replaces it with `ReceiptProcessor`).
  - `backend/src/Infrastructure/Logging/JsonLogger.php` (JSON lines; reused in Phase 1);
    `backend/src/Worker/WorkerFactory.php` (composition root).
  - `backend/entrypoints/lambda/worker.php`: ~5 lines, `return static fn (array $event): array => …`; no Bref imports.
  - `docker/worker/Dockerfile`: `FROM bref/php-85:3@sha256`, `COPY --from=bref/extra-gd-php-85:3@sha256 /opt /opt`,
    `ENV BREF_RUNTIME=function` and the telemetry switch confirmed in A6, handler
    `entrypoints/lambda/worker.php`, code at `/var/task`. `worker` service: RIE on 8080, internal network only.
- **Tests first (RED):** `tests/Unit/Queue/SqsBatchProcessorTest.php` (all succeed, one fails, all fail, malformed
  event); `tests/Unit/Receipts/S3EventParserTest.php` (AWS and silo fixtures → same `S3ObjectRef`; decoding;
  TestEvent; missing fields → exception); `tests/Integration/Worker/RieInvocationTest.php` (2-record SQS event, one
  malformed body → `batchItemFailures` has exactly that id).
- **Acceptance:** `src/` coverage ≥ 80%; Bref-boundary test still passes; `check-platform` reads the real Dockerfile.
- **Agents:** tdd-guide; php-reviewer. **Commits:** `feat: add worker sqs batch skeleton with s3 event parsing`,
  `chore: add bref php-85 worker image with gd`.

#### WP11: Worker runner (M)
- **Purpose:** stand in for the Lambda SQS event source mapping: delete only succeeded messages; failures return to
  the queue and redrive to the DLQ.
- **Files:** `docker/local-aws/bin/runner.php` (signal-aware: SIGTERM finishes the current batch);
  `src/Runner/{QueueClient,SqsQueueClient,LambdaInvoker,RieHttpInvoker,SqsEventBuilder,BatchResultInterpreter,Runner}.php`;
  `worker-runner` service.
- **Behaviour:** long-poll (`WaitTimeSeconds=20`, `MaxNumberOfMessages=10`, all attributes); build the AWS-shaped event
  (`messageId`, `receiptHandle`, `body`, `attributes`, `messageAttributes`, `md5OfBody`, `eventSource: aws:sqs`,
  `eventSourceARN`, `awsRegion`); POST to RIE with an HTTP timeout below the visibility timeout. Not 200, an error
  payload (shape from A2) or invalid JSON → whole batch failed, nothing deleted; an `itemIdentifier` missing, empty or
  not in the batch → whole batch failed (as AWS documents); otherwise `DeleteMessageBatch` for non-failed messages.
- **Tests first (RED):** `SqsEventBuilderTest` (golden fixture from the AWS docs), `BatchResultInterpreterTest` (cases
  above), `RunnerTest` (fake queue and invoker; asserts exactly which receipt handles are deleted).
- **Integration:** `backend/tests/Integration/Pipeline/ReceiptPipeTest.php`: a presigned POST upload ends with queue
  and DLQ empty within 15 s; a poison body sent to `receipts` is in `receipts-dlq` after 3 receives
  (`#[Group('slow')]`, ~35 s).
- **Acceptance:** pipe works end to end in CI; `docker/local-aws/src` coverage ≥ 80%.
- **Agents:** tdd-guide; php-reviewer. **Commit:** `feat: add local worker runner emulating sqs event source mapping`.

### Part D: API, contract and front-end

#### WP12: API skeleton through nginx and php-fpm (S)
- **Purpose:** prove nginx + php-fpm + `public/index.php` work together, **without** building Phase 1's router,
  middleware or problem-details library.
- **Files:** `backend/src/Http/HealthEndpoint.php` (pure (method, path) → (status, headers, body): `GET /api/v1/health`
  → `200 {"status":"ok"}`; other method on that path → `405 application/problem+json`; anything else → `404`
  problem+json `{type,title,status}`; docblock marks it temporary until Phase 1); `backend/public/index.php` (~10
  lines); `docker/api/nginx.conf` (`server_tokens off`, `client_max_body_size 1m`, `/api/` → fastcgi
  `api-php:9000` with `SCRIPT_FILENAME /var/task/public/index.php`, else 404; a `map` that fills
  `HTTP_CLOUDFRONT_VIEWER_ADDRESS` from `$remote_addr:$remote_port` only when absent, ADR 0012 §4.5);
  `docker/php/php-fpm.d/zz-app.conf` (`clear_env = no`, so env vars reach PHP as under Bref); `api` and `api-php`
  services.
- **Tests first (RED):** `tests/Unit/Http/HealthEndpointTest.php`; `tests/Integration/Http/HealthOverNginxTest.php`
  (status, content type, body; no `X-Powered-By`; no nginx version in `Server`; 404 returns problem+json).
- **Stays in Phase 1:** `X-Origin-Verify` check, `ClientIp`, routing, contract-validation helper.
- **Agents:** tdd-guide; php-reviewer; security-reviewer (nginx). **Commit:** `feat: serve /api/v1/health through nginx and php-fpm`.

#### WP13: Contract skeleton, lint and type generation (S)
- **Files:** `contract/openapi.yaml` (OpenAPI 3.1; `servers: /api/v1`; `GET /health` → 200 `HealthResponse
  {status: const "ok"}`, `additionalProperties: false`; `components.schemas.ProblemDetails` per RFC 9457);
  `contract/redocly.yaml`; `contract/package.json` + lock with exact `@redocly/cli 2.62.1`, `openapi-typescript
  7.13.0` and **its own `typescript` 5.x** (isolated, so the front-end stays on TS 6 without `overrides`); scripts
  `lint`, `bundle` (→ `contract/dist/openapi.json`), `gen` (→ committed `frontend/src/api/schema.gen.ts`); Makefile
  `contract`; CI job `contract` (lint, bundle, gen, `git diff --exit-code`).
- **Tests first (RED):** a deliberately stale `schema.gen.ts` makes the CI diff check fail; then regenerate.
- **Fallbacks:** `overrides` in `frontend/package.json`; then TS 5.9 in the front-end (recorded deviation).
- **Agents:** typescript-reviewer; doc-updater. **Commit:** `feat: add openapi contract skeleton with lint and generated types`.

#### WP14: Front-end scaffold, Vite proxy headers and API status (M)
- **Files:**
  - `frontend/` from `create-vue` (TS, Router, Pinia, Vitest, ESLint flat config, Prettier; no JSX/E2E), demo
    components removed, exact versions, `engines.node = 24.21.0`, `.node-version`.
  - `frontend/vite/proxyHeaders.ts`: `buildForwardHeaders(remoteAddress, remotePort, originVerify)` — **always
    overwrites** `CloudFront-Viewer-Address` (as CloudFront does), normalises `::ffff:a.b.c.d` to IPv4, IPv6 as
    `addr:port` without brackets, sets `X-Origin-Verify`.
  - `frontend/vite.config.ts`: proxy `/api` → `API_PROXY_TARGET` with `configure` + `proxyReq` (confirm in Vite 8);
    `ORIGIN_VERIFY_LOCAL` via `loadEnv(mode, cwd, '')`, **not** `VITE_`-prefixed so it never reaches the bundle;
    `server.host` and `allowedHosts` for the container.
  - `frontend/src/api/health.ts` (`fetchHealth()` typed from `schema.gen.ts`);
    `frontend/src/components/ApiStatus.vue` shown in `App.vue`.
  - `frontend` service (pinned `node:24.21.0-alpine`, named volume for `node_modules`, `127.0.0.1:5173`).
  - CI job `frontend`: lint, `format:check`, `type-check`, `test:unit --coverage` (thresholds 80), `build`,
    `npm audit --audit-level=high`.
- **Tests first (RED):** `vite/proxyHeaders.spec.ts` (IPv4, IPv4-mapped, IPv6, client-supplied header overwritten);
  `src/api/health.spec.ts` (mocked `fetch`: ok, non-200, invalid JSON); `src/components/__tests__/ApiStatus.spec.ts`
  (loading, ok, error).
- **Acceptance:** browser shows "API: ok" through the proxy; CI asserts no origin-verify value in `dist/`.
- **Agents:** tdd-guide; vue-reviewer + typescript-reviewer; security-reviewer. **Commits:** `chore: scaffold vue
  frontend with create-vue`, `feat: vite proxy adds origin and viewer-address headers`, `feat: show api health status`.

### Part E: Infrastructure, CI hardening and docs

#### WP15: Terraform skeleton (S)
- **Files:** `infra/terraform/bootstrap/{versions.tf, main.tf, .terraform.lock.hcl}`;
  `infra/terraform/envs/dev/{versions.tf, providers.tf, variables.tf, .terraform.lock.hcl}` (`required_version >=
  1.10`, `aws = "6.68.0"`, partial `backend "s3" {}`, `default_tags` project/env/`managed-by=terraform`, no
  resources); `infra/terraform/modules/README.md`; `scripts/terraform.sh` (pinned
  `hashicorp/terraform:1.16.5@sha256`, repo mounted); Makefile `tf-check`; CI job `terraform`.
- **Check:** `init -backend=false` then `validate` per root; lock files for linux/darwin × amd64/arm64.
- **Tests first (RED):** a misformatted `.tf` fails `fmt -check`.
- **Acceptance:** no AWS credentials anywhere; only network access is the provider download.
- **Agents:** security-reviewer, code-reviewer. **Commit:** `chore: add terraform skeleton with fmt and validate checks`.

#### WP16: Pin and secret guards, final Makefile and CI (M)
- **Files:** `scripts/check-image-pins.sh` + `scripts/tests/check-image-pins.test.sh` (every Dockerfile `FROM`/`COPY
  --from`/`RUN --mount from=`/`# syntax=` and every Compose image, as resolved by `docker compose config`, pinned by
  `@sha256:`; no variables in image references; pinned and unpinned fixtures); `scripts/lint-workflows.sh` +
  `.github/zizmor.yml` (zizmor in a pinned image: workflow and action `uses:`, `container:` and `services:` images
  hash-pinned, plus its other audits); gitleaks in a pinned image over full history (`fetch-depth: 0`, fails on a
  shallow clone) in the `guards` job; final Makefile (`make test` =
  unit + local-aws + conformance + integration + frontend; `make test-fast` skips `slow`; `make lint` = section 1).
- **Tests first (RED):** unpinned fixture fails, pinned passes.
- **Acceptance:** all jobs green: `backend`, `local-aws`, `integration`, `frontend`, `contract`, `terraform`, `guards`.
- **Agents:** security-reviewer (workflow permissions, `${{ }}` injection in `run:`); build-error-resolver if CI
  fails. **Commit:** `ci: add pin and secret guards and finalise required checks`.

#### WP17: Docs and wrap-up (S)
- **Files:** `README.md` (what it is, prerequisites, quick start, repo map); `docs/local-dev.md` (AWS ↔ local services
  table with ports, receipt pipe flow, make targets, running one test, resetting state — `make down` keeps volumes,
  `docker compose down -v` deletes them, ask first — troubleshooting); PLAN §12 Phase 0 status → Done.
- **`spikes/`:** deleted with the owner's OK (G3); reports keep the commit SHA where the harness lived.
- **Before the PR is marked ready:** `/update-docs`, `/code-review`, `/verification-loop`.
- **Agent:** doc-updater. **Commit:** `docs: local development guide and readme for phase 0`.

## 3. Risks and decision points

| # | Trigger | Decision / fallback | Impact |
|---|---|---|---|
| D1 | A9: a pinned tool fails on 8.5 | Per ADR 0012: whole stack on 8.4 (`bref/php-84`, `gd-php-84`, `php:8.4-*`, platform pin), recorded in ADR 0012 | Image tags and pins only |
| D2 | A4: no WebP in GD | Drop WebP from the upload policy (amend ADR 0006) | None in Phase 0 |
| D3 | A2: RIE missing or different on php-85 | Add the pinned `aws-lambda-rie` binary (checksum-verified); if function mode is broken, new ADR | WP10, WP11 |
| D4 | WP3: silo fails a mandatory criterion | Floci with signature validation (native S3→SQS could replace relay + ElasticMQ only if redrive matches); last resort MinIO pinned by digest | WP8, WP9; owner decides at G1 |
| D5 | B: webhook can't be set via env/SDK | Pinned `mcli` step in `local-aws-init` | WP8 |
| D6 | B: duplicate or overwrite events | Relay never deduplicates; Phase 5's conditional updates handle duplicates | None |
| D7 | A6: telemetry switch differs or can't be disabled | Use the real name in Dockerfile and ADR; if impossible, owner decides | ADR 0012 text |
| D8 | A7: no `layers.json` for gd | Pinned Terraform variable (ADR 0012 fallback) | Phase 7 |
| D9 | openapi-typescript vs TS 6 | Isolated `contract/` package → `overrides` → TS 5.9 in the front-end | WP13 |
| D10 | ElasticMQ vs SDK JSON protocol | Newer ElasticMQ, or pin the SDK minor that works | WP7 |
| D11 | silo single maintainer / AGPL | Dev tool only, unmodified; conformance suite on every digest bump; Floci is second choice | None |
| D12 | WSL2 bind-mount speed; x86-only worker image | Named volumes for `node_modules`; Apple Silicon runs under emulation (documented) | Docs |
| D13 | Scope creep into Phase 1 | `HealthEndpoint` is explicitly temporary; no router, middleware or origin check | — |

Phase 0 is closer to **L** than the M in PLAN §12 (17 packages). If time matters, WP9–WP11 (the pipe) could be a
separate PR; that breaks "one PR per phase", so the owner decides.

## 4. Not in Phase 0

- **Phase 1:** router, Request/Response, middleware chain, problem-details library; origin-verify middleware and
  `Http\ClientIp`; config loader and API composition root; table-creation script (table + `LSI1`) and DynamoDB Local
  persistence choice; `ApiTestCase` with opis contract validation and the route-parity test.
- **Phase 2:** sessions, auth, CSRF, throttling. **Phase 3:** categories, expenses, pagination. **Phase 4:** app
  shell, pages, guards, stores. **Phase 5:** signed uploads in the API, `ReceiptProcessor`, thumbnails, conditional
  updates, cleanup, TTL. **Phase 6:** `scripts/smoke.sh`. **Phase 7:** real Terraform resources, layer-version wiring,
  plan tests. **Phase 8:** any AWS call or deploy.
- **Later:** L1 Playwright; L2 `deploy.yml` + OIDC; L3 tflint/checkov; L6 `oasdiff`.
- **Not planned yet:** Dependabot/Renovate for digest and action bumps (worth a small later PR).

## 5. Branch and PR strategy

- Branch `feat/phase-0-foundations` from `main`. Commits per work package, conventional, tests committed with or
  before the code that makes them pass (RED → GREEN visible in history).
- **One PR for Phase 0** (PLAN §12), opened as a draft after WP4, marked ready after WP17. Body: definition-of-done
  checklist, spike summaries, decisions, test plan.
- **Owner gates:** G1 spike decisions (after WP3); G2 the PR merge; G3 deleting `spikes/`; G4 branch protection with
  required checks (GitHub setting). Nothing in Phase 0 touches AWS or costs money.
- **Reviews:** tdd-guide while writing; php-reviewer / typescript-reviewer / vue-reviewer after; security-reviewer
  for WP7, 8, 9, 12, 14, 15, 16; before merge `/code-review`, `/verification-loop`, `/update-docs`.

## 6. Effort

| WP | Size | WP | Size |
|---|---|---|---|
| 1 Plan and hygiene | S | 10 Worker skeleton and image | M |
| 2 Spike: Bref php-85 | M | 11 Worker runner | M |
| 3 Spike: S3 emulator | M | 12 API health through nginx/fpm | S |
| 4 G1 decisions | S | 13 Contract | S |
| 5 Backend tooling and CI | M | 14 Front-end | M |
| 6 Platform-pin guard | S | 15 Terraform skeleton | S |
| 7 Compose, DynamoDB, ElasticMQ | M | 16 Guards, Makefile, CI final | M |
| 8 S3 emulator and bootstrap | M | 17 Docs and wrap-up | S |
| 9 s3-events-relay | S–M | | |
