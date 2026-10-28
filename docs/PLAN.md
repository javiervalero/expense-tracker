# Implementation Plan — Expense Tracker

> **Status: APPROVED — 2026-10-09.**
> Approved by the owner after review round 1 on PR #1. The architectural decisions are recorded as ADRs in
> [`docs/adr/`](adr/README.md), all **Accepted**. Changes from now on follow [Review](#14-review): a changed
> decision gets a new ADR that supersedes the old one.

| | |
|---|---|
| Requirements | [`docs/BRIEF.md`](BRIEF.md) |
| Constraints | [`CLAUDE.md`](../CLAUDE.md) |
| Drafted | 2026-10-07 |
| Last updated | 2026-10-09 (approved; ADR 0012 accepted) |

---

## 1. Summary

A personal expense tracker built as a realistic proof of concept: small in scope, but built and deployed like a
production application. It uses a Vue single-page app, a JSON API written in vanilla PHP (no framework), and a
**fully serverless AWS architecture**: nothing runs, and almost nothing is billed, when nobody is using it. The whole
system also runs locally with Docker and no AWS account.

## 2. Requirements (restated from the brief)

**Users can:**
- create an account, sign in and sign out;
- record expenses with an amount, date, description and category;
- manage their own categories;
- view, filter, edit and delete their expenses;
- attach a receipt (image or PDF) to an expense and preview it;
- see a monthly summary of spending by category.

**The system must:**
- let users see and change only their own data;
- let receipts upload without passing through the application server;
- process receipts (e.g. thumbnails) without blocking the user;
- be safe to expose on the public internet;
- run fully locally without AWS, and deploy to AWS from code (create and destroy);
- have automated tests and a CI pipeline.

**Out of scope:** sharing between users, mobile apps, multi-currency conversion, OCR, email flows, i18n.

## 3. Key decisions

| ADR | Decision |
|---|---|
| [0001](adr/0001-serverless-aws-php-on-lambda-with-bref.md) | Fully serverless on AWS; PHP runs on Lambda using the Bref 3 runtime, confined to thin entry points |
| [0002](adr/0002-dynamodb-single-table-data-model.md) | DynamoDB single-table data model, expenses keyed by id with a date index (`LSI1`) |
| [0003](adr/0003-vanilla-php-backend-structure.md) | Vanilla PHP back-end structure: Composer autoload, hand-written router, problem-details errors |
| [0004](adr/0004-server-side-sessions-with-cookies.md) | Server-side sessions in an HttpOnly cookie instead of JWTs |
| [0005](adr/0005-single-origin-through-cloudfront.md) | One domain for app and API via CloudFront |
| [0006](adr/0006-direct-to-s3-receipt-uploads-and-async-processing.md) | Direct-to-S3 receipt uploads; asynchronous processing via S3 → SQS → worker Lambda; explicit cleanup |
| [0007](adr/0007-local-development-with-docker-compose.md) | Local development with Docker Compose; the worker gets the same SQS event locally as on AWS |
| [0008](adr/0008-frontend-stack.md) | Front-end stack: Vue 3, TypeScript, Vite, Pinia, Vue Router |
| [0009](adr/0009-terraform-for-infrastructure.md) | Terraform for infrastructure as code |
| [0010](adr/0010-github-actions-ci-cd-with-oidc.md) | GitHub Actions for CI/CD, deploying with OIDC (no stored AWS keys) |
| [0011](adr/0011-openapi-contract.md) | OpenAPI 3.1 contract as the API source of truth, checked in CI on both sides |
| [0012](adr/0012-runtime-platform.md) | Runtime platform: PHP 8.5 on Bref 3 layers, all Lambdas x86_64 (GD extra layer), client IP from CloudFront's viewer-address header, API reachable only through CloudFront |

## 4. Architecture

```
                         ┌────────────── AWS ─────────────────────────────────────────────────────┐
Browser ──HTTPS──► CloudFront ──┬── /*      → S3 bucket (built Vue app)                              │
                         │      └── /api/*  → API Gateway (HTTP API) → Lambda: PHP API ──► DynamoDB   │
                         │                                                  │                        │
                         │                       creates short-lived signed S3 URLs                  │
Browser ──signed POST──► S3 receipts bucket (originals) ──ObjectCreated, prefix receipts/──► SQS (+ DLQ)
                         │                                         └──► Lambda: PHP worker ──► DynamoDB
                         │                                                   └──► S3 receipt-thumbnails (no events)
                         └─────────────────────────────────────────────────────────────────────────┘
```

- **No VPC, no NAT gateway, no database server, no database password.** Every component authenticates with IAM.
- **All Lambdas run on x86_64** with PHP 8.5 from Bref 3 layers; the worker adds Bref's GD layer, which has no arm64 build ([ADR 0012](adr/0012-runtime-platform.md)).
- **Idle cost is close to $0**: everything is billed per request or per GB stored. AWS WAF has a fixed monthly fee,
  so it is available behind a switch but off by default.
- App and API are served from **one domain**, so the session cookie is first-party and the API needs no CORS. The API accepts only requests carrying CloudFront's secret origin header, and takes the client IP only from the `CloudFront-Viewer-Address` header that CloudFront adds.

### Local equivalent (no AWS account needed)

| AWS | Local (Docker Compose) |
|---|---|
| CloudFront + S3 site | Vite dev server (proxies `/api` to the API, adding the same origin-verify and `CloudFront-Viewer-Address` headers as CloudFront) |
| API Gateway + Lambda (API) | nginx + php-fpm (`php:8.5-fpm-alpine`), running the same PHP code |
| DynamoDB | `amazon/dynamodb-local` |
| S3 | pgsty/silo (maintained MinIO fork, pinned by digest; chosen in the Phase 0 spike, see ADR 0007) |
| SQS + DLQ | ElasticMQ (same redrive settings) |
| S3 event → SQS → worker Lambda | Emulator webhook → small relay → ElasticMQ → local runner → worker in the `bref/php-85:3` image (plus GD) under the Lambda Runtime Interface Emulator. The worker receives the same SQS event as on AWS. |

## 5. Back-end design

- **PHP 8.5, no framework.** Composer's `config.platform.php` is pinned to the Bref layer's exact PHP version. Composer is used for PSR-4 autoloading and two runtime libraries: `aws/aws-sdk-php`, and
  `bref/bref` (needed by the Lambda runtime layer's bootstrap, never imported by our code). Development: PHPUnit,
  PHPStan, PHP-CS-Fixer, and an API contract validator.
- `public/index.php` is the single HTTP entry point. A small hand-written router dispatches to handlers through
  middleware: error handling, origin verification (only CloudFront may call the API), request logging (JSON lines), authentication, and CSRF protection.
- **Errors** follow RFC 9457 "problem details" (`application/problem+json`), with field-level validation errors.
- **Modules:** `Http`, `Auth`, `Categories`, `Expenses`, `Receipts`, `Reports`, `Infrastructure` (DynamoDB, S3, clock,
  config).
- **Money** is stored as integer cents, with one currency configured for the whole app.
- On AWS, Bref provides only the PHP runtime. The API runs unchanged in Bref's FPM mode. The worker's Lambda entry
  point is a small file returning a plain closure. All logic lives in runtime-agnostic classes under `src/`, and a CI
  check keeps Bref references out of them. Lambda settings (runtime, x86_64, `BREF_RUNTIME`, handlers, layers) are in [ADR 0012](adr/0012-runtime-platform.md).

### API (`/api/v1`, JSON)

The authoritative API definition is `contract/openapi.yaml` (OpenAPI 3.1,
[ADR 0011](adr/0011-openapi-contract.md)). The table below is only a summary.

| Area | Endpoints |
|---|---|
| Auth | `POST /auth/register`, `POST /auth/login`, `POST /auth/logout`, `GET /auth/me` |
| Categories | `GET /categories`, `POST /categories`, `PATCH /categories/{id}`, `DELETE /categories/{id}` (only the category is deleted; its expenses show as *Uncategorized* from then on, see ADR 0002) |
| Expenses | `GET /expenses?from&to&category&q&limit&cursor` (returns `{ items, nextCursor }`; `category=none` = Uncategorized), `POST /expenses`, `GET /expenses/{id}`, `PATCH /expenses/{id}`, `DELETE /expenses/{id}` |
| Receipts | `POST /expenses/{id}/receipt/upload` (returns a signed upload policy), `GET /expenses/{id}/receipt` (status + short-lived view URLs), `DELETE /expenses/{id}/receipt` |
| Reports | `GET /reports/monthly?month=YYYY-MM` |
| Health | `GET /health` |

Another user's resource always returns `404`, so the API never reveals that it exists.

## 6. Data model (DynamoDB, single table)

See [ADR 0002](adr/0002-dynamodb-single-table-data-model.md) for the reasoning and the full access-pattern table.

| Item | PK | SK | Main attributes |
|---|---|---|---|
| User | `USER#<userId>` | `PROFILE` | email, passwordHash, createdAt |
| Email lock | `EMAIL#<normalisedEmail>` | `EMAIL` | userId (written in the same transaction as the user, so emails are unique) |
| Category | `USER#<userId>` | `CAT#<categoryId>` | name, colour |
| Expense | `USER#<userId>` | `EXP#<expenseId>` | LSI1SK = `EXP#<yyyy-mm-dd>#<expenseId>`, date, amountCents, description, categoryId |
| Receipt | `USER#<userId>` | `RCPT#<expenseId>` | status (`pending`/`ready`/`failed`), objectKey (`receipts/<userId>/<expenseId>/<receiptId>`), contentType, size, expiresAt (TTL; set while `pending` or `failed`, removed when `ready`) |
| Session | `SESSION#<tokenHash>` | `SESSION` | userId, expiresAt (DynamoDB TTL) |
| Login throttle | `THROTTLE#<email or ip>` | `THROTTLE` | attempts, expiresAt (DynamoDB TTL); ip = IPv4 address or IPv6 /64 prefix (ADR 0012) |

One local secondary index, **`LSI1`** (the table's `PK` + `LSI1SK`, all attributes projected), lists expenses by
date. Only expense items carry `LSI1SK`. The thumbnail key is worked out from `objectKey`, so it is not stored.

**Access patterns:**
- A user's own data always uses `PK = USER#<current user>`, so isolation is built into the key.
- One expense by id: direct `GetItem`/`UpdateItem`/`DeleteItem` on `SK = EXP#<expenseId>`. Changing the date updates
  `LSI1SK` in the same single-item write; the id and receipt never change.
- Expenses in a date range: query `LSI1` with `LSI1SK BETWEEN EXP#<from> AND EXP#<to>~` (strongly consistent).
- Monthly summary: query one month of a user's expenses and total them by category in PHP.
- Text and category filters: applied in PHP while filling each page, within a read budget; see ADR 0002 for the page
  and cursor contract.

**Rules enforced in code (and covered by tests), since DynamoDB has no foreign keys:**
- an expense's category must exist in the same user's partition when the expense is saved;
- a deleted category's expenses are reported as Uncategorized when read (no rewrite);
- deleting an expense deletes its receipt record and then its S3 objects (original and thumbnail); deleting or
  replacing a receipt does the same (record first, then files; see ADR 0006).

The table is billed on demand, with point-in-time recovery and TTL enabled.

## 7. Receipts flow

1. The user picks a file. The front-end asks the API for an upload policy.
2. The API returns a **signed S3 POST policy** (≤ 10 MB; JPEG, PNG, WebP, PDF; exact key
   `receipts/<userId>/<expenseId>/<receiptId>`; 5-minute expiry). It records the receipt as `pending` with a 24-hour
   TTL.
3. The browser uploads directly to the `receipts` bucket; the file never passes through PHP.
4. S3 sends an `ObjectCreated` event (only for `receipts/`) to SQS. The worker Lambda then:
   - checks the real file type (magic bytes) and image dimensions;
   - writes a thumbnail to the separate `receipt-thumbnails` bucket, which sends no events;
   - marks the receipt `ready` with a conditional update, or `failed` (deleting the object) if the file is invalid;
   - deletes the objects if the receipt was deleted, replaced or expired in the meantime.

   Repeated failures land in a dead-letter queue.
5. The front-end polls the receipt status. Previews use **short-lived signed GET URLs**. PDFs show a generic icon
   and open in the browser's built-in viewer; no PDF thumbnails (that would need Ghostscript in Lambda).

**Cleanup:** deleting or replacing a receipt, or deleting its expense, removes the record first and then the original
and thumbnail. A `pending` record that is never uploaded expires through TTL. See ADR 0006, "Lifecycle and cleanup".

## 8. Front-end design

- Vue 3, TypeScript, Vite, Vue Router, Pinia; plain CSS with a few shared components. API types are generated from
  the OpenAPI contract.
- **Pages:**
  - Register and Login;
  - Expenses (list, filters, "Load more" pagination);
  - Expense form (with receipt upload, progress and preview);
  - Categories;
  - Monthly summary (a table; a Chart.js chart follows in phase L5).
- Route guards send signed-out users to Login.

## 9. Security baseline

- **HTTPS only.** CloudFront adds security headers: CSP, HSTS, `X-Content-Type-Options`, `Referrer-Policy` and
  frame protection.
- **Sessions:**
  - a random token in a `__Host-` cookie that is `HttpOnly`, `Secure` and `SameSite=Lax`;
  - only a hash of the token is stored;
  - sessions expire via TTL, and sign-out deletes them.
- **Passwords** are hashed with `password_hash` (Argon2id).
- **CSRF:** `SameSite` cookie, plus a required custom request header and an `Origin` check on requests that change
  data.
- **Abuse limits:** login attempts are throttled per account and per client IP; API Gateway enforces rate limits.
  The client IP comes only from CloudFront's `CloudFront-Viewer-Address` header (port stripped, IPv6 counted per
  /64), never from `REMOTE_ADDR` (always `127.0.0.1` under Bref) or `X-Forwarded-For` (ADR 0012).
- **Origin lockdown:** CloudFront adds a secret `X-Origin-Verify` header to API requests. The first PHP middleware
  rejects any request without it (constant-time comparison), so calling the API Gateway URL directly fails and
  the client-IP header cannot be forged. Terraform generates the secret and rotates it with two overlapping slots.
- **Receipts:** private, encrypted buckets (originals and thumbnails) with all public access blocked; versioning off,
  so deletes really delete; strict upload policies; content and image dimensions checked by the worker; view links
  expire within minutes.
- **Least privilege:** each Lambda has its own IAM role, limited to what it needs. The worker cannot write to the
  originals bucket.
- **No third-party telemetry:** Bref's anonymous usage ping is turned off (`BREF_PING_DISABLE`).
- **Optional:** AWS WAF with managed rule sets on CloudFront (off by default because of its fixed monthly fee; WAF cannot be attached to an HTTP API).

## 10. Testing and CI/CD

| Layer | Tools |
|---|---|
| PHP | PHPUnit: unit tests, plus integration tests against DynamoDB Local, the S3 emulator and ElasticMQ, including the receipt pipeline end to end, retries to the DLQ and cleanup races; PHPStan (strict); PHP-CS-Fixer |
| API contract | Redocly lint; generated TS types must match the spec; PHPUnit integration tests validate every response against it; route-parity test |
| Front-end | Vitest + Vue Test Utils; ESLint; Prettier; `vue-tsc` |
| End-to-end | Phase 6: curl smoke script (locally and on AWS). Phase L1: Playwright against the full Compose stack, including a test that user A cannot see user B's data |
| Infrastructure | `terraform fmt` / `validate` and a plan test for the receipt pipeline settings (Phase 0/7); tflint, checkov (Phase L3) |

- **CI:** GitHub Actions runs all of the above on every pull request, as each check is introduced.
- **CD:** the first deployment (Phase 8) uses `make deploy`, run by the owner with short-lived AWS SSO credentials.
  Phase L2 adds a manually triggered GitHub Actions workflow, protected by an approval environment, that runs the same
  script: Terraform plan, approval, apply, front-end upload, smoke test (valid and invalid receipt upload; asserts
  `ready` and `failed` and an empty DLQ). It authenticates with **OIDC**, so no AWS keys are stored in GitHub.

## 11. Repository layout

```
backend/            PHP API + worker (shared code), Dockerfile, tests/
  src/              runtime-agnostic application code
  public/           index.php (HTTP entry point)
  entrypoints/      lambda/worker.php (the only Bref-specific code)
frontend/           Vue app, tests/
contract/           openapi.yaml (API source of truth); dist/ is generated
infra/terraform/    bootstrap/ (state bucket), modules/, envs/dev/
docs/               BRIEF.md, PLAN.md, adr/, architecture.md, local-dev.md, deploy.md
.github/workflows/  ci.yml (deploy.yml in phase L2)
docker-compose.yml
Makefile            make up / make test / make lint / make deploy …
```

## 12. Phases

Each phase is delivered as one branch and pull request. It follows the ECC workflow:
- tests first, using the `tdd-workflow` skill;
- review with the `php-reviewer`, `vue-reviewer` and `security-reviewer` agents and `/code-review`;
- `/verification-loop` before merge;
- `/update-docs` to keep documentation current.

Phases are split into two tracks:
- **Essential:** the smallest set that meets every requirement in [`BRIEF.md`](BRIEF.md) and gives a working
  end-to-end deployment.
- **Later:** improves assurance, operations or polish. Each "later" phase can be merged on its own, in any order,
  after Phase 9.

### Essential: first working end-to-end deployment

| # | Phase | Delivers | Size | Status |
|---|---|---|---|---|
| 0 | Foundations | Repo skeleton; Docker Compose (DynamoDB Local, S3 emulator, ElasticMQ, s3-events-relay, php-fpm, worker runner); Makefile; CI running PHPUnit, PHPStan, PHP-CS-Fixer, ESLint/Prettier, `vue-tsc`, Vitest, `terraform fmt`/`validate`, contract lint, Bref-boundary check; all checks required on PRs. **Spikes:** S3 emulator choice (ADR 0007); confirm on `bref/php-85:3` the Bref 3 findings recorded in ADR 0012 (its "To verify in Phase 0" list). CI check that `config.platform.php` matches the Bref image's PHP version. Detailed plan: [plans/phase-0-foundations.md](plans/phase-0-foundations.md). | M | Done |
| 1 | Back-end core | Router, middleware (including origin verification and trusted client IP, ADR 0012), problem-details errors, DynamoDB access layer, table-creation script (table + `LSI1`), `/health`, OpenAPI skeleton + contract test helper (ADR 0011). Detailed plan: [plans/phase-1-backend-core.md](plans/phase-1-backend-core.md). | M | Done |
| 2 | Authentication | Register/login/logout/me, sessions, CSRF, login throttling (per account and per client IP). Detailed plan: [plans/phase-2-authentication.md](plans/phase-2-authentication.md). | M | Done |
| 3 | Categories and expenses API | CRUD; date and category filters; cursor pagination with read budget; category resolver; ownership rules; **cross-user integration tests**. Detailed plan: [plans/phase-3-categories-expenses.md](plans/phase-3-categories-expenses.md). | M | Done |
| 4 | Front-end | App shell, auth pages, route guards, expenses and categories UI, generated API types, Vitest. Detailed plan: [plans/phase-4-frontend.md](plans/phase-4-frontend.md). | M | Done (PR #7 merge pending) |
| 5 | Receipts | Signed uploads, emulator → ElasticMQ → worker, thumbnails bucket, conditional status updates and cleanup, TTL on pending, status polling, preview (PDF icon) | L | Not started |
| 6 | Monthly summary | Report endpoint and summary **table** page; `scripts/smoke.sh` (curl) covering every feature against any base URL | S | Not started |
| 7 | Infrastructure (Terraform) | State bootstrap; DynamoDB (table + `LSI1`, TTL on `expiresAt`); Lambdas (x86_64, `provided.al2023`, `BREF_RUNTIME`; Bref `php-85` layer, plus `gd-php-85` for the worker; layer versions from `composer.lock`, ADR 0012); API Gateway with throttling; **S3 ×3** (`site`, `receipts` with the only event notification, `receipt-thumbnails`); SQS (visibility timeout ≥ 6× worker timeout) + DLQ (`maxReceiveCount` 3) and event source mapping with `ReportBatchItemFailures`; CloudFront + security headers, origin request policy forwarding `CloudFront-Viewer-Address`, secret origin header (Terraform-generated, sensitive); per-function IAM (worker cannot write to `receipts`); explicit log groups with retention; **alarms: budget, DLQ depth, receipt-cleanup errors**; plan test for the pipeline settings. **Written and planned only, not applied.** | L | Not started |
| 8 | First deployment | `make deploy` (Terraform apply + front-end upload) run by the owner with short-lived AWS SSO credentials; smoke test on AWS (also: a direct API Gateway call is rejected; a forged `CloudFront-Viewer-Address` is ignored); then one destroy → re-create → smoke test. **Every apply and destroy only with the owner's explicit go-ahead.** | M | Not started |
| 9 | Essential documentation | README, `local-dev.md`, `deploy.md` (deploy and destroy runbook), `architecture.md` with the current diagram | S | Not started |

### Later: assurance, operations and polish

| # | Phase | Delivers | Size | Status |
|---|---|---|---|---|
| L1 | Browser end-to-end tests | Playwright journeys on the Compose stack, including user A cannot see user B's data; required on PRs (completes the ECC testing rule) | M | Not started |
| L2 | CD pipeline | GitHub OIDC role, `deploy.yml` with approval environment calling the same `make deploy` (ADR 0010) | M | Not started |
| L3 | Infrastructure scanning | tflint and checkov in CI | S | Not started |
| L4 | Operational alarms | Lambda errors and API 5xx alarms with email notification | S | Not started |
| L5 | Feature polish | Chart.js summary chart, category colours, `make seed` | S | Not started |
| L6 | Contract evolution | `oasdiff` breaking-change check against `main` | S | Not started |
| L7 | Documentation deep-dive | "How a request flows" walkthrough, detailed diagrams | S | Not started |
| L8 | Optional WAF | Terraform switch for AWS WAF managed rules (off by default) | S | Not started |

Phases 0–6 run entirely locally and cost nothing. Phase 7 writes Terraform but applies nothing. AWS is first used in
Phase 8, and only with the owner's explicit go-ahead.

**Deliberate deviation:** the ECC testing rule requires E2E tests. In the essential track, end-to-end behaviour is
covered by the curl smoke script (Phase 6, run locally and on AWS) and the cross-user integration tests (Phase 3);
Playwright arrives in L1. The owner approved this on 2026-10-09.

### Brief coverage by the essential track

| BRIEF requirement | Covered in |
|---|---|
| Account: register, sign in, sign out | 2, 4 |
| Expenses with amount, date, description, category | 3, 4 |
| Manage own categories | 3, 4 |
| View, filter, edit, delete expenses | 3 (date and category filters), 4 |
| Attach receipt and preview it | 5 |
| Monthly summary by category | 6 (table) |
| Users only see and change their own data | 3 (key design + cross-user integration tests), 2 |
| Receipts don't pass through the app server | 5 (signed S3 POST) |
| Receipt processing doesn't block the user | 5 (S3 → SQS → worker, DLQ) |
| Safe on the public internet | 2 (sessions, CSRF, throttling), 7 (HTTPS, headers, API throttling, origin lockdown, private buckets, least-privilege IAM), security review every phase |
| Runs locally without AWS | 0, 5 |
| Deploys to AWS; created and destroyed from code | 7, 8 |
| Automated tests and CI | 0 onwards |
| Works end to end locally and on AWS | 6 smoke script, run in 8 |
| Owner can understand it from the docs | 9, plus `/update-docs` each phase |

## 13. Risks

| Risk | Level | Mitigation |
|---|---|---|
| Data rules (ownership, deleted categories, receipt cleanup) live in PHP, not in database constraints | Medium | Each rule has dedicated tests, including cross-user tests and cleanup race tests |
| DynamoDB access patterns turn out wrong once built | Medium | Patterns settled up front in ADR 0002 (including lookup by id, date changes and filtered pagination); integration tests run against DynamoDB Local from Phase 1 |
| `LSI1` can't be added or removed after the table is created | Low | Access patterns fixed in ADR 0002 before Phase 1; tables are created from a script/Terraform; on AWS, migrate via PITR export if ever needed |
| Local and AWS behave differently (emulators, php-fpm vs Lambda, IAM, S3 notifications) | **Medium** | Worker receives the same SQS event shape locally (ElasticMQ + runner that behaves like the event source mapping, with DLQ redrive); handler fixtures from both AWS and the emulator; Terraform plan tests for the notification filter, event source mapping and redrive; post-deploy smoke test (valid and invalid upload, DLQ empty) |
| Local S3 emulator (pgsty/silo) depends mainly on one maintainer | Low | Pinned by digest; permanent conformance suite (POST policy, notifications, CORS) catches regressions on every bump; Floci then pinned MinIO as fallbacks (ADR 0007) |
| Large or crafted images exhaust worker memory (Lambda limit differs from local) | Low | Check dimensions before decoding (reject > ~40 MP); size the worker's memory; DLQ alarm |
| PHP Lambda cold start after idle (~0.3–0.5 s) | Low | Acceptable for a proof of concept; recorded in ADR 0001 |
| AWS resources left running or costs creeping | Low | Serverless pricing, budget alarm, destroy → re-create tested in Phase 8 |
| Bref / Lambda layer versions change, or Composer locks packages the layer's PHP can't run | Low | Terraform reads layer versions from the locked `bref/bref`; `config.platform.php` equals the layer's PHP, checked in CI; Bref confined to entry points (ADR 0012) |
| Per-IP throttling bypassed by calling API Gateway directly or forging the client-IP header | Medium | Secret origin header checked first in PHP; IP read only from `CloudFront-Viewer-Address`; both checked by the Phase 8 smoke test (ADR 0012) |
| Direct calls to the public API Gateway URL use Lambda invocations and the shared API rate limit | Low | Rejected in the first middleware within milliseconds; API Gateway throttling; budget alarm |
| PHP 8.5 is newer, so a tool may lag in support | Low | Verified in Phase 0: every pinned tool runs on 8.5.11 with deprecations as errors; CI keeps checking on each bump; fall back to 8.4 if a later bump breaks (ADR 0012) |
| x86_64 Lambdas cost more than arm64 (arm64 is about 20% cheaper) | Low | Negligible at this volume; revisit if Bref ships arm64 extra layers |

## 14. Review

This plan was approved on 2026-10-09. The review process used, and still used for later changes:

1. Reviewers read this document and the ADRs in [`docs/adr/`](adr/README.md).
2. Feedback goes in the pull request that introduces these documents, or as edits to them.
3. When a decision is agreed, its ADR status changes from **Proposed** to **Accepted** (or **Rejected** /
   **Superseded** if it changes).
4. When all ADRs are settled, the status at the top of this file changes to **Approved**, with the date and the
   approvers. Phase 0 starts only after that.

**Points that would benefit most from review:**
- DynamoDB vs a relational database (ADR 0002): this is the decision with the most knock-on effects.
- Cutting PDF thumbnails (section 7).
- WAF off by default (section 9).
- How the API is locked to CloudFront (ADR 0012): secret header checked in PHP vs Lambda function URL with OAC.

**Resolved in review round 1 (PR #1, 2026-10-09):** receipt processing loop and cleanup (ADR 0006), expense keys,
category deletion and filtered pagination (ADR 0002), Bref and the "no framework" constraint (ADR 0001), local vs
AWS worker path (ADR 0007), API contract (ADR 0011), essential vs later scope (§12).

**Approval record:**

| Date | Reviewer | Outcome |
|---|---|---|
| 2026-10-09 | Javier Valero (owner) | Approved after review round 1 (PR #1, 9 comments addressed in 64e78fc) |
